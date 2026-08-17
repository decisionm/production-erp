<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Modules\Inventory\Models\Enums\StoreIssueStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\StoreIssue;
use App\Modules\Inventory\Models\StoreIssueBagScan;
use App\Modules\Inventory\Models\StoreIssueLine;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\DayBinMovementType;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\BagCostAllocationService;
use App\Modules\Production\Services\DayBinLedgerService;
use App\Modules\Production\Services\FinishedCartonService;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * THE TIERED CARTON TRACE (DEC-20260810-001). One barcode, two answers:
 *
 *  - the PUBLIC/dispatch scan keeps exactly the shape it had — no cost, no
 *    rate, no lot identity, no completion metadata, the keys ABSENT rather
 *    than null, for everyone including a Supervisor login;
 *  - the INTERNAL tier (completion datetime, shift, day-bin lot attribution
 *    with GRN reference/inward date/rate, the batch's costing rate) answers
 *    only behind the carton-trace permission, which the seeder hands to
 *    exactly Owner (Administrator), Plant Manager and Accounts.
 *
 * The attribution is the CALCULATED bin-held-these-lots claim (FC-01): the
 * lots whose day-bin loads fall inside the batch's shift window on its
 * production date, read in the factory's wall clock — never a UTC calendar
 * day, and never a bag→batch identity.
 */
class CartonInternalTraceTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Item $resin;

    private Warehouse $store;

    private ?Warehouse $wip = null;

    private ?User $issuer = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml Bottle', 'uom' => 'Nos']);
        $this->resin = Item::create(['sku' => 'RM-PET', 'name' => 'PET Resin', 'uom' => 'Kgs']);
        $this->store = Warehouse::create(['code' => 'RM', 'name' => 'RM Store']);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function actingAsSupervisor(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['production.view', 'production.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    private function actingAsTraceReader(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('carton-trace.view', 'web');
        $user->givePermissionTo('carton-trace.view');
        Sanctum::actingAs($user);

        return $user;
    }

    private function completedEntry(Shift $shift, string $date = '2026-08-02'): ShiftProductionEntry
    {
        $machine = WorkCenter::firstOrCreate(['code' => 'MC-01'], ['name' => 'Machine 1', 'is_active' => true]);

        $entry = ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->store->id,
            'production_date' => $date,
            'batch_number' => "B-{$shift->name}-001",
            'status' => ShiftProductionEntryStatus::Pending,
            'batch_status' => BatchStatus::Completed,
            'quantity_produced' => '1200',
            'nos_per_box' => '600',
        ]);

        app(FinishedCartonService::class)->generateFor($entry, null);

        return $entry;
    }

    private function morningShift(): Shift
    {
        return Shift::create(['name' => 'Morning', 'start_time' => '06:00:00', 'end_time' => '14:00:00', 'is_active' => true]);
    }

    /** A supplier lot with one registered bag sitting in the store. */
    private function lotWithBag(string $lotNo, ?string $rate, ?int $grnId = null): MaterialBag
    {
        $lot = MaterialLot::create([
            'grn_id' => $grnId,
            'item_id' => $this->resin->id,
            'supplier_lot_no' => $lotNo,
            'received_date' => '2026-07-25',
            'bag_count' => 1,
            'total_received_kg' => '1000',
            'receipt_rate_per_kg' => $rate,
        ]);

        return $lot->bags()->create([
            'barcode' => "BAG-{$lotNo}",
            'original_kg' => '1000',
            'remaining_kg' => '1000',
            'status' => MaterialBagStatus::InStore,
            'current_warehouse_id' => $this->store->id,
        ]);
    }

    /**
     * A Load row in the common day-bin ledger at an exact FACTORY wall-clock
     * instant — through the ledger service, the only writer.
     */
    private function loadAt(MaterialBag $bag, string $kg, string $factoryClock): void
    {
        app(DayBinLedgerService::class)->record([
            'work_center_id' => null,
            'item_id' => $this->resin->id,
            'type' => DayBinMovementType::Load->value,
            'material_bag_id' => $bag->id,
            'quantity_kg' => $kg,
            // ->utc() matters: Eloquent formats a datetime WITHOUT converting
            // its timezone, and this table's convention (like every other) is
            // UTC storage — production writes recorded_at with now().
            'recorded_at' => CarbonImmutable::parse($factoryClock, 'Asia/Kolkata')->utc(),
        ]);
    }

    /**
     * A STORE ISSUE handing this bag to production at an exact FACTORY
     * wall-clock instant (Phase 7.5, WS-C) — how material reaches production
     * now that DEC-20260817-001 has retired the Day Bin. Written as the
     * database holds it: the lifecycle is StoreIssueService's subject, the
     * WINDOW is this test's.
     */
    private function issueAt(MaterialBag $bag, string $kg, string $factoryClock, StoreIssueStatus $status = StoreIssueStatus::Issued): void
    {
        static $sequence = 0;
        // ->utc() for the same reason loadAt() does it: this table stores UTC.
        $at = CarbonImmutable::parse($factoryClock, 'Asia/Kolkata')->utc();

        $issue = StoreIssue::create([
            'issue_number' => sprintf('SI-2026-%04d', ++$sequence),
            'status' => $status,
            'issued_by' => $this->issuer()->id,
            'received_by' => $this->issuer()->id,
            'issued_at' => $at,
        ]);
        $line = StoreIssueLine::create([
            'store_issue_id' => $issue->id,
            'item_id' => $this->resin->id,
            'from_warehouse_id' => $this->store->id,
            'to_warehouse_id' => $this->wipWarehouse()->id,
            'quantity_issued' => $kg,
            'quantity_returned' => '0',
            'uom' => 'Kgs',
        ]);
        StoreIssueBagScan::create([
            'store_issue_id' => $issue->id,
            'store_issue_line_id' => $line->id,
            'material_bag_id' => $bag->id,
            'material_lot_id' => $bag->material_lot_id,
            'quantity_kg' => $kg,
            'issued_by' => $this->issuer()->id,
            'received_by' => $this->issuer()->id,
            'scanned_at' => $at,
        ]);
    }

    private function issuer(): User
    {
        return $this->issuer ??= User::factory()->create(['is_active' => true]);
    }

    private function wipWarehouse(): Warehouse
    {
        return $this->wip ??= Warehouse::create(['code' => 'WIP', 'name' => 'Work In Progress']);
    }

    /** Every key in a nested array payload, dotted, for exact-shape asserts. */
    private function allKeys(array $payload, string $prefix = ''): array
    {
        $keys = [];
        foreach ($payload as $key => $value) {
            if (is_int($key)) {
                continue;
            }
            $keys[] = $prefix.$key;
            if (is_array($value)) {
                $keys = [...$keys, ...$this->allKeys($value, $prefix.$key.'.')];
            }
        }

        return $keys;
    }

    // ------------------------------------------------------------------
    // (a) The public tier is frozen — keys ABSENT, not null
    // ------------------------------------------------------------------

    public function test_the_public_scan_carries_no_cost_rate_lot_or_completion_fields_even_for_a_supervisor(): void
    {
        $this->actingAsSupervisor();
        $entry = $this->completedEntry($this->morningShift());

        $carton = $this->getJson("/api/v1/production/cartons/{$entry->batch_number}-C01")
            ->assertSuccessful()
            ->json('data');

        // The EXACT shape the scan served before this feature — frozen
        // byte-for-byte by DEC-20260810-001. A new key here is a regression
        // even if its value is null.
        $this->assertSame(
            [
                'id', 'carton_no', 'item', 'pieces', 'is_partial', 'status', 'delivery_id',
                'net_weight_kg', 'sales_order', 'quality', 'batch', 'created_at',
            ],
            array_keys($carton),
        );
        $this->assertSame(
            ['shift_production_entry_id', 'batch_number', 'production_date', 'machine', 'shift', 'nos_per_box'],
            array_keys($carton['batch']),
        );

        // ABSENT, not null — and nothing rate- or lot-shaped anywhere in the
        // payload, however deeply nested.
        foreach (['completion', 'costing', 'day_bin_attribution'] as $internal) {
            $this->assertArrayNotHasKey($internal, $carton);
        }
        foreach ($this->allKeys($carton) as $key) {
            $this->assertStringNotContainsString('rate', $key);
            $this->assertStringNotContainsString('cost', $key);
            $this->assertStringNotContainsString('lot', $key);
        }
    }

    // ------------------------------------------------------------------
    // (b) The internal tier answers only to the carton-trace permission
    // ------------------------------------------------------------------

    public function test_the_internal_tier_requires_the_carton_trace_permission(): void
    {
        $this->seed(PermissionSeeder::class);

        $entry = $this->completedEntry($this->morningShift());
        $url = "/api/v1/production/cartons/{$entry->batch_number}-C01/trace";

        // A Supervisor login — full production access, quality, sales — is
        // refused: production.* does not open this gate (DEC-20260810-001:
        // never Supervisor).
        $supervisor = User::factory()->create(['is_active' => true]);
        foreach (['production.view', 'production.manage', 'quality.manage', 'sales.manage'] as $permission) {
            $supervisor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        Sanctum::actingAs($supervisor);
        $this->getJson($url)->assertForbidden();

        // The three named identities pass on the SEEDED grants alone — this
        // is the seeder's Plant Manager/Accounts wiring under test, and
        // Administrator is how the owner logs in.
        foreach (['Plant Manager', 'Accounts', 'Administrator'] as $roleName) {
            $reader = User::factory()->create(['is_active' => true]);
            $reader->assignRole(Role::findByName($roleName, 'web'));
            Sanctum::actingAs($reader);

            $this->getJson($url)
                ->assertSuccessful()
                ->assertJsonPath('data.carton.carton_no', "{$entry->batch_number}-C01")
                ->assertJsonPath('data.costing.basis', BagCostAllocationService::ALLOCATION_SENTENCE)
                ->assertJsonPath('data.day_bin_attribution.basis', FinishedCartonService::ATTRIBUTION_SENTENCE);
        }
    }

    // ------------------------------------------------------------------
    // (c) The attribution window is the shift's own wall clock
    // ------------------------------------------------------------------

    public function test_the_attribution_lists_exactly_the_lots_loaded_within_the_shift_window(): void
    {
        $vendor = Vendor::create(['code' => 'SUP-1', 'name' => 'Resin Supplier']);
        $order = PurchaseOrder::create([
            'vendor_id' => $vendor->id,
            'status' => PurchaseOrderStatus::Sent,
            'order_date' => '2026-07-24',
        ]);
        $grn = GoodsReceiptNote::create([
            'purchase_order_id' => $order->id,
            'warehouse_id' => $this->store->id,
            'reference' => 'GRN-2026-104',
            'received_date' => '2026-07-25',
        ]);

        $inFirst = $this->lotWithBag('REL-A', '92.0000', $grn->id);
        $inLast = $this->lotWithBag('REL-B', '118.0000');
        $beforeStart = $this->lotWithBag('REL-EARLY', '95.0000');
        $atEnd = $this->lotWithBag('REL-LATE', '96.0000');

        // Morning runs 06:00–14:00 IST on 2026-08-02. Loads land exactly on
        // and beside both boundaries: start-inclusive, end-EXCLUSIVE — the
        // handover-instant load belongs to the incoming shift.
        $this->loadAt($inFirst, '60.0000', '2026-08-02 06:00:00');
        $this->loadAt($inFirst, '40.0000', '2026-08-02 09:15:00');
        $this->loadAt($inLast, '25.0000', '2026-08-02 13:59:59');
        $this->loadAt($beforeStart, '30.0000', '2026-08-02 05:59:59');
        $this->loadAt($atEnd, '30.0000', '2026-08-02 14:00:00');

        $this->actingAsTraceReader();
        $entry = $this->completedEntry($this->morningShift());

        $attribution = $this->getJson("/api/v1/production/cartons/{$entry->batch_number}-C01/trace")
            ->assertSuccessful()
            ->json('data.day_bin_attribution');

        $this->assertSame(FinishedCartonService::ATTRIBUTION_SENTENCE, $attribution['basis']);
        $this->assertSame('2026-08-02', $attribution['window']['production_date']);
        $this->assertSame('Morning', $attribution['window']['shift']);
        $this->assertSame('Asia/Kolkata', $attribution['window']['timezone']);

        // Exactly the in-window lots — the boundary constructions above are
        // the point of this assertion.
        $this->assertSame(['REL-A', 'REL-B'], array_column($attribution['lots'], 'supplier_lot_no'));

        [$lotA, $lotB] = $attribution['lots'];
        $this->assertSame('PET Resin', $lotA['material']);
        $this->assertSame('GRN-2026-104', $lotA['grn_reference']);
        $this->assertSame('2026-07-25', $lotA['inward_date']);
        $this->assertSame('92.0000', $lotA['rate_per_kg']);
        $this->assertSame('bag_receipt', $lotA['rate_source']);
        // Two in-window loads of one lot are one attribution line.
        $this->assertSame('100.0000', $lotA['loaded_kg']);

        $this->assertNull($lotB['grn_reference']);
        $this->assertSame('118.0000', $lotB['rate_per_kg']);
        $this->assertSame('25.0000', $lotB['loaded_kg']);

        $this->assertSame('0.0000', $attribution['unattributed_loaded_kg']);
    }

    /**
     * THE SAME WINDOW, ASKED OF THE STORE-ISSUE LEDGER (Phase 7.5, WS-C).
     *
     * Material reaches production as a store issue into Production/WIP now
     * (DEC-20260817-001), so a provenance surface that read only
     * day_bin_movements would be EMPTY for every batch run under the current
     * flow — the one failure this tier must not have. The attribution stays
     * exactly what it always was: by shift window, calculated, never a claim
     * that a bag reached a batch (DEC-20260810-001, FC-01).
     *
     * Boundaries are asserted the same way as the day-bin test above:
     * start-INCLUSIVE, end-EXCLUSIVE. A handover-instant issue belongs to
     * the incoming shift, and to exactly one shift — an inclusive upper
     * bound would attribute the same kilograms to two shifts at once.
     */
    public function test_the_attribution_also_lists_lots_issued_to_production_within_the_window(): void
    {
        $issuedInside = $this->lotWithBag('ISS-IN', '101.0000');
        $issuedAtStart = $this->lotWithBag('ISS-START', '102.0000');
        $issuedBefore = $this->lotWithBag('ISS-EARLY', '103.0000');
        $issuedAtEnd = $this->lotWithBag('ISS-LATE', '104.0000');
        $cancelled = $this->lotWithBag('ISS-CANCELLED', '105.0000');
        $dayBinLot = $this->lotWithBag('OLD-DAYBIN', '106.0000');

        // Morning runs 06:00–14:00 IST on 2026-08-02.
        $this->issueAt($issuedAtStart, '10.0000', '2026-08-02 06:00:00');
        $this->issueAt($issuedInside, '35.0000', '2026-08-02 10:30:00');
        $this->issueAt($issuedBefore, '99.0000', '2026-08-02 05:59:59');
        $this->issueAt($issuedAtEnd, '99.0000', '2026-08-02 14:00:00');
        // Reversed in full — it never stood in production, so it is not
        // provenance for anything.
        $this->issueAt($cancelled, '99.0000', '2026-08-02 09:00:00', StoreIssueStatus::Cancelled);
        // And a historical day-bin load in the same window: the two ledgers
        // are read side by side, neither replacing the other.
        $this->loadAt($dayBinLot, '20.0000', '2026-08-02 08:00:00');

        $this->actingAsTraceReader();
        $entry = $this->completedEntry($this->morningShift());

        $attribution = $this->getJson("/api/v1/production/cartons/{$entry->batch_number}-C01/trace")
            ->assertSuccessful()
            ->json('data.day_bin_attribution');

        // The owner-fixed sentence is untouched (DEC-20260810-001 requires
        // the bin-held-these-lots wording); `sources` says, beside it, which
        // ledger the kilograms actually came from.
        $this->assertSame(FinishedCartonService::ATTRIBUTION_SENTENCE, $attribution['basis']);
        $this->assertSame(['day_bin_loaded_kg' => '20.0000', 'store_issued_kg' => '45.0000'], $attribution['sources']);

        $lots = collect($attribution['lots'])->keyBy('supplier_lot_no');
        $this->assertSame(['OLD-DAYBIN', 'ISS-START', 'ISS-IN'], $lots->keys()->all());
        $this->assertSame('10.0000', $lots['ISS-START']['loaded_kg']);
        $this->assertSame('35.0000', $lots['ISS-IN']['loaded_kg']);
        $this->assertSame('PET Resin', $lots['ISS-IN']['material']);
        // The lot's own rate still reaches this internal tier through the
        // ordinary lot-attribution path — the issue ledger carries none.
        $this->assertSame('101.0000', $lots['ISS-IN']['rate_per_kg']);

        $this->assertSame('0.0000', $attribution['unattributed_loaded_kg']);
    }

    public function test_an_overnight_shifts_window_crosses_midnight_in_factory_time(): void
    {
        $night = Shift::create(['name' => 'Night', 'start_time' => '22:00:00', 'end_time' => '06:00:00', 'is_active' => true]);

        $evening = $this->lotWithBag('N-EVENING', '90.0000');
        $smallHours = $this->lotWithBag('N-SMALL-HOURS', '91.0000');
        $beforeStart = $this->lotWithBag('N-BEFORE', '89.0000');
        $atHandover = $this->lotWithBag('N-HANDOVER', '88.0000');

        // Production date 2026-08-02: the Night instance runs 02nd 22:00 IST
        // → 03rd 06:00 IST. A UTC calendar-day window would get every one of
        // these wrong.
        $this->loadAt($evening, '50.0000', '2026-08-02 23:00:00');
        $this->loadAt($smallHours, '50.0000', '2026-08-03 02:00:00');
        $this->loadAt($beforeStart, '50.0000', '2026-08-02 21:59:59');
        $this->loadAt($atHandover, '50.0000', '2026-08-03 06:00:00');

        $this->actingAsTraceReader();
        $entry = $this->completedEntry($night);

        $attribution = $this->getJson("/api/v1/production/cartons/{$entry->batch_number}-C01/trace")
            ->assertSuccessful()
            ->json('data.day_bin_attribution');

        $this->assertSame(['N-EVENING', 'N-SMALL-HOURS'], array_column($attribution['lots'], 'supplier_lot_no'));
    }

    // ------------------------------------------------------------------
    // (d) The label data carries completion date + shift; the scan does not
    // ------------------------------------------------------------------

    public function test_label_data_carries_completion_date_and_shift_and_the_public_scan_does_not(): void
    {
        $this->actingAsSupervisor();
        $entry = $this->completedEntry($this->morningShift());

        // The completion instant is the standing consumption rows' write
        // time (no column stamps it — see batchCompletedAt). 20:45 UTC is
        // 02:15 IST the NEXT day: the label date must be the factory's.
        $entry->materialConsumptions()->create([
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->store->id,
            'quantity_issued_kg' => '14.4000',
        ])->forceFill(['created_at' => '2026-08-02 20:45:00'])->save();

        $labels = $this->getJson("/api/v1/production/shift-production-entries/{$entry->id}/cartons")
            ->assertSuccessful()
            ->json('data');

        $this->assertSame('2026-08-03', $labels[0]['completion']['completed_on']);
        $this->assertSame('Morning', $labels[0]['completion']['shift']);
        // …and that is ALL the label gained: no rate, cost or lot key joined it.
        foreach ($this->allKeys($labels[0]) as $key) {
            $this->assertStringNotContainsString('rate', $key);
            $this->assertStringNotContainsString('cost', $key);
            $this->assertStringNotContainsString('lot', $key);
        }

        // The generate (print) response says the same thing as the reprint.
        $generated = $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/cartons")
            ->assertSuccessful()
            ->json('data');
        $this->assertSame('2026-08-03', $generated[0]['completion']['completed_on']);

        // The public scan still refuses to know: the key is ABSENT.
        $scan = $this->getJson("/api/v1/production/cartons/{$entry->batch_number}-C01")
            ->assertSuccessful()
            ->json('data');
        $this->assertArrayNotHasKey('completion', $scan);
    }
}
