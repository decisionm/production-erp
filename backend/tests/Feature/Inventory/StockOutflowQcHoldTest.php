<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Exceptions\IncomingQcHoldException;
use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Quality\Services\IncomingInspectionService;
use Database\Seeders\CanonicalMachineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use ReflectionClass;
use ReflectionNamedType;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * EVERY DOOR OUT OF A BALANCE, NOT JUST THE ONE THAT WAS FOUND FIRST.
 *
 * The arrival hold (a bag is born `waiting_qc` and is not production's until
 * Incoming Inspection releases it) lives on the BAG. Every outflow in this
 * system works on the BALANCE, and the balance counts held kilograms. The
 * first pass at this closed the typed store-issue line, and a reviewer then
 * reproduced two ways past it that had nothing to do with store issues:
 *
 *  A. `POST /api/v1/inventory/stock-movements/issues` — 100 kg standing in
 *     four un-inspected bags, issue 75, balance 25, four bags still
 *     `waiting_qc`. And the same writer's `/transfers` sibling could move
 *     all 100 kg to a second store, where the bags — which never move —
 *     hold nothing against it, and hand it over there instead. LAUNDERING
 *     BY RELOCATION.
 *  B. A shift completion's `material_consumptions`, reachable with
 *     `production.manage` and no inventory permission at all, going through
 *     recordIssue(allowNegative: true) — where even an empty balance is not
 *     a refusal.
 *
 * Both are closed at ONE place, StockMovementService::decrementBalance,
 * which every issue and the source leg of every transfer passes through. So
 * these tests do not check "the store issue is guarded" — they check that
 * the WRITERS are guarded, one test per door, including the two doors that
 * were open when the reviewer looked.
 *
 * Each test in the first three sections FAILS on the parent commit (the
 * store-issue-only guard) — that is the point of the file.
 */
class StockOutflowQcHoldTest extends TestCase
{
    use RefreshDatabase;

    private Item $resin;

    private Warehouse $store;

    private Warehouse $farStore;

    private User $storeKeeper;

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.traceability_enabled' => true]);

        $this->store = Warehouse::create(['code' => 'QH-RM', 'name' => 'QH Raw Material Store', 'is_active' => true]);
        $this->farStore = Warehouse::create(['code' => 'QH-RM2', 'name' => 'QH Second Store', 'is_active' => true]);

        $this->resin = Item::create([
            'sku' => 'QH-RESIN', 'name' => 'QH Resin', 'uom' => 'KGS',
            'is_active' => true, 'is_production_input' => true,
        ]);

        $this->storeKeeper = User::factory()->create(['is_active' => true]);
        foreach (['inventory.manage', 'procurement.manage', 'quality.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $this->storeKeeper->givePermissionTo($permission);
        }
        Sanctum::actingAs($this->storeKeeper);
    }

    // ---- fixtures ----------------------------------------------------------

    private function stock(string $quantity, ?Warehouse $warehouse = null, ?Item $item = null): void
    {
        StockBalance::query()->updateOrCreate(
            ['item_id' => ($item ?? $this->resin)->id, 'warehouse_id' => ($warehouse ?? $this->store)->id],
            ['quantity' => $quantity, 'average_cost' => '10.0000'],
        );
    }

    /**
     * Bags exactly as a goods receipt makes them: one lot, N bags, each in
     * the store it was unloaded into, at the given status.
     *
     * @return list<MaterialBag>
     */
    private function bags(
        string $lotNo,
        int $count,
        string $kgEach,
        MaterialBagStatus $status,
        ?Warehouse $warehouse = null,
        ?Item $item = null,
    ): array {
        $item ??= $this->resin;

        $lot = MaterialLot::create([
            'item_id' => $item->id,
            'supplier_lot_no' => $lotNo,
            'received_date' => '2026-08-18',
            'bag_count' => $count,
            'bag_weight_kg' => $kgEach,
            'total_received_kg' => bcmul($kgEach, (string) $count, 4),
        ]);

        $made = [];
        for ($seq = 1; $seq <= $count; $seq++) {
            $made[] = $lot->bags()->create([
                'barcode' => "{$lotNo}-B{$seq}",
                'original_kg' => $kgEach,
                'remaining_kg' => $kgEach,
                'status' => $status,
                'current_warehouse_id' => $warehouse === null ? $this->store->id : $warehouse->id,
            ]);
        }

        return $made;
    }

    private function balance(?Warehouse $warehouse = null, ?Item $item = null): string
    {
        return (string) (StockBalance::query()
            ->where('item_id', ($item ?? $this->resin)->id)
            ->where('warehouse_id', ($warehouse ?? $this->store)->id)
            ->value('quantity') ?? '0');
    }

    private function genericIssue(string $quantity, ?Warehouse $from = null)
    {
        return $this->postJson('/api/v1/inventory/stock-movements/issues', [
            'item_id' => $this->resin->id,
            'warehouse_id' => ($from ?? $this->store)->id,
            'quantity' => $quantity,
        ]);
    }

    private function genericTransfer(string $quantity, ?Warehouse $from = null, ?Warehouse $to = null)
    {
        return $this->postJson('/api/v1/inventory/stock-movements/transfers', [
            'item_id' => $this->resin->id,
            'from_warehouse_id' => ($from ?? $this->store)->id,
            'to_warehouse_id' => ($to ?? $this->farStore)->id,
            'quantity' => $quantity,
        ]);
    }

    // =====================================================================
    // A. The generic stock-movement writers — the reviewer's first bypass
    // =====================================================================

    public function test_the_generic_issue_writer_cannot_consume_held_material(): void
    {
        $this->stock('100');
        $this->bags('QH-LOT-A', 4, '25', MaterialBagStatus::WaitingQc);

        $this->genericIssue('75')
            ->assertStatus(422)
            ->assertJsonPath('code', 'incoming_qc_hold');

        // Nothing moved and nothing was written: the balance is where the
        // lorry left it and the ledger has no issue row to explain.
        $this->assertSame(0, bccomp($this->balance(), '100', 4));
        $this->assertSame(0, StockMovement::query()->where('item_id', $this->resin->id)->count());

        // And the bags are still exactly what they were — the refusal does
        // not touch the hold it is enforcing.
        $this->assertSame(4, MaterialBag::query()->where('status', MaterialBagStatus::WaitingQc->value)->count());
    }

    public function test_the_generic_issue_writer_may_still_take_the_part_that_is_not_held(): void
    {
        $this->stock('100');
        $this->bags('QH-LOT-B', 2, '20', MaterialBagStatus::WaitingQc);   // 40 held
        $this->bags('QH-LOT-C', 2, '30', MaterialBagStatus::InStore);     // 60 released

        $this->genericIssue('60')->assertCreated();
        $this->assertSame(0, bccomp($this->balance(), '40', 4));

        // The 40 that is left is the hold itself. Not one kilogram more.
        $this->genericIssue('0.0001')->assertStatus(422);
        $this->assertSame(0, bccomp($this->balance(), '40', 4));
    }

    public function test_the_transfer_writer_cannot_launder_held_material_into_another_store(): void
    {
        $this->stock('100');
        $this->bags('QH-LOT-D', 4, '25', MaterialBagStatus::WaitingQc);

        // The reviewer's exact route: move it somewhere the bags are not.
        $this->genericTransfer('100')
            ->assertStatus(422)
            ->assertJsonPath('code', 'incoming_qc_hold');

        $this->assertSame(0, bccomp($this->balance(), '100', 4));
        $this->assertSame(0, bccomp($this->balance($this->farStore), '0', 4));
        $this->assertSame(0, StockMovement::query()->where('item_id', $this->resin->id)->count());
    }

    public function test_a_transfer_may_still_move_the_part_that_is_not_held(): void
    {
        $this->stock('100');
        $this->bags('QH-LOT-E', 1, '40', MaterialBagStatus::WaitingQc);

        $this->genericTransfer('60')->assertOk();

        $this->assertSame(0, bccomp($this->balance(), '40', 4));
        $this->assertSame(0, bccomp($this->balance($this->farStore), '60', 4));

        $this->genericTransfer('1')->assertStatus(422);
    }

    public function test_material_moved_before_the_hold_existed_is_not_chased_into_the_second_store(): void
    {
        // The laundering that has ALREADY happened (or a backfill) leaves a
        // hold bigger than the balance it stands against. The source then
        // refuses everything — fail-closed and blunt, said out loud here so
        // it is a decision and not a surprise — and the second store, which
        // holds no bags, is untouched: this change refuses new movements, it
        // never reaches back into stock that has already moved.
        $this->stock('20');
        $this->stock('80', $this->farStore);
        $this->bags('QH-LOT-F', 4, '25', MaterialBagStatus::WaitingQc);

        $this->genericIssue('1')->assertStatus(422);
        $this->genericIssue('80', $this->farStore)->assertCreated();
        $this->assertSame(0, bccomp($this->balance($this->farStore), '0', 4));
    }

    // =====================================================================
    // B. The production door — production.manage only, allowNegative on
    // =====================================================================

    private function completionFixture(): array
    {
        $this->seed(CanonicalMachineSeeder::class);
        config(['production.approvals.quality_stage_enabled' => false]);

        $machine = WorkCenter::where('code', 'MC-01')->firstOrFail();
        $shift = Shift::create(['name' => 'QH Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $fg = Warehouse::create(['code' => 'QH-FG', 'name' => 'QH FG Store', 'is_active' => true]);

        $bottle = Item::create([
            'sku' => 'QH-BTL', 'name' => 'QH 100ML ROUND', 'uom' => 'Nos.', 'is_active' => true,
            'nominal_weight_grams' => '12.9000', 'standard_cycle_time' => '12.30', 'standard_cavities' => 5,
            'nos_per_tray' => 162, 'trays_per_box' => 5, 'nos_per_box' => 810, 'colour' => 'Amber',
        ]);
        $bom = Bom::create(['item_id' => $bottle->id, 'name' => 'recipe', 'version' => '1', 'is_active' => true]);
        $bom->lines()->create(['component_item_id' => $this->resin->id, 'quantity_per' => '0.0129']);

        // THE ACTOR THE REVIEWER USED: the floor's own permission, and not
        // one inventory permission anywhere on it. This route group is
        // gated `module:production`, so nothing in Inventory's own guard
        // stack is ever consulted on the way to the balance.
        $supervisor = User::factory()->create(['is_active' => true]);
        foreach (['production.view', 'production.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $supervisor->givePermissionTo($permission);
        }
        Sanctum::actingAs($supervisor);
        $this->assertFalse($supervisor->hasPermissionTo('inventory.manage'));

        $entryId = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $bottle->id,
            'warehouse_id' => $fg->id,
            'production_date' => '2026-08-18',
        ])->assertOk()->json('data.id');

        return ['entry_id' => $entryId, 'supervisor' => $supervisor];
    }

    private function complete(int $entryId, string $issuedKg)
    {
        return $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => '8100',
            'running_hours' => '8',
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'warehouse_id' => $this->store->id, 'quantity_issued_kg' => $issuedKg],
            ],
        ]);
    }

    public function test_a_shift_completion_cannot_consume_material_waiting_for_incoming_qc(): void
    {
        $this->stock('100');
        $this->bags('QH-LOT-G', 4, '25', MaterialBagStatus::WaitingQc);

        $fixture = $this->completionFixture();

        $this->complete($fixture['entry_id'], '75')
            ->assertStatus(422)
            ->assertJsonPath('code', 'incoming_qc_hold');

        // The whole completion rolled back — not merely the stock leg.
        $this->assertSame(0, bccomp($this->balance(), '100', 4));
        $entry = ShiftProductionEntry::findOrFail($fixture['entry_id']);
        $this->assertNotSame(BatchStatus::Completed, $entry->batch_status);
        $this->assertSame(0, $entry->materialConsumptions()->count());
    }

    public function test_the_same_completion_goes_through_once_quality_releases_the_arrival(): void
    {
        $this->stock('100');
        $bags = $this->bags('QH-LOT-H', 4, '25', MaterialBagStatus::WaitingQc);

        $fixture = $this->completionFixture();
        $this->complete($fixture['entry_id'], '75')->assertStatus(422);

        foreach ($bags as $bag) {
            $bag->update(['status' => MaterialBagStatus::InStore]);
        }

        $this->complete($fixture['entry_id'], '75')->assertOk();
        $this->assertSame(0, bccomp($this->balance(), '25', 4));
    }

    // =====================================================================
    // C. allowNegative — outranked by a hold, untouched without one
    // =====================================================================

    public function test_a_hold_outranks_allow_negative(): void
    {
        $this->stock('100');
        $this->bags('QH-LOT-I', 4, '25', MaterialBagStatus::WaitingQc);

        $this->expectException(IncomingQcHoldException::class);

        try {
            app(StockMovementService::class)->recordIssue(
                itemId: $this->resin->id,
                warehouseId: $this->store->id,
                quantity: '75',
                allowNegative: true,
            );
        } finally {
            $this->assertSame(0, bccomp($this->balance(), '100', 4));
        }
    }

    public function test_allow_negative_behaves_exactly_as_before_when_nothing_is_held(): void
    {
        // No bags at all — the commonest case in this factory, and the one
        // the negative-stock-on-completion decision exists for. Nothing here
        // may change by one character.
        $this->assertSame(0, bccomp($this->balance(), '0', 4));

        $shortfall = null;
        app(StockMovementService::class)->recordIssue(
            itemId: $this->resin->id,
            warehouseId: $this->store->id,
            quantity: '118.998',
            allowNegative: true,
            shortfallKg: $shortfall,
        );

        $this->assertSame('118.9980', $shortfall);
        $this->assertSame(0, bccomp($this->balance(), '-118.998', 4));
    }

    public function test_released_and_rejected_bags_hold_nothing_back(): void
    {
        $this->stock('100');
        $this->bags('QH-LOT-J', 2, '25', MaterialBagStatus::InStore);
        $this->bags('QH-LOT-K', 2, '25', MaterialBagStatus::RejectedQc);

        // A rejected bag's kilograms left the balance through the
        // inspection's own issue. Withholding them a second time would
        // refuse material that is genuinely standing there.
        $this->genericIssue('100')->assertCreated();
        $this->assertSame(0, bccomp($this->balance(), '0', 4));
    }

    // =====================================================================
    // D. Quality's own door stays open — and takes no item-wide bag lock
    // =====================================================================

    /** @return array{grn_line_id: int, warehouse: Warehouse} */
    private function arrival(string $lotNo, string $quantity, int $bagCount, string $kgEach, string $key): int
    {
        $vendor = Vendor::query()->firstOrCreate(
            ['code' => 'QH-V1'],
            ['name' => 'QH Test Supplier', 'is_active' => true],
        );

        $orderId = $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $vendor->id,
            'order_date' => '2026-08-10',
            'expected_date' => '2026-08-20',
            'lines' => [['item_id' => $this->resin->id, 'quantity' => $quantity, 'unit_price' => '1.00']],
        ])->assertCreated()->json('data.id');

        $poLineId = $this->getJson("/api/v1/procurement/purchase-orders/{$orderId}")
            ->assertOk()->json('data.lines.0.id');
        $this->postJson("/api/v1/procurement/purchase-orders/{$orderId}/send")->assertOk();

        $barcodes = [];
        for ($seq = 1; $seq <= $bagCount; $seq++) {
            $barcodes[] = "{$lotNo}-{$seq}";
        }

        $this->postJson('/api/v1/procurement/goods-receipts', [
            'receipt_key' => $key,
            'purchase_order_id' => $orderId,
            'warehouse_id' => $this->store->id,
            'reference' => "QH-DC-{$key}",
            'received_date' => '2026-08-18',
            'lines' => [[
                'purchase_order_line_id' => $poLineId,
                'quantity' => $quantity,
                'lots' => [[
                    'supplier_lot_no' => $lotNo,
                    'bag_count' => $bagCount,
                    'bag_weight_kg' => $kgEach,
                    'barcodes' => $barcodes,
                ]],
            ]],
        ])->assertCreated();

        return (int) GoodsReceiptNoteLine::query()->latest('id')->value('id');
    }

    public function test_quality_can_reject_an_arrival_while_a_second_arrival_of_the_same_material_is_still_held(): void
    {
        // The case pure sequencing does NOT cover: rejecting line A's bags
        // takes them out of the hold, but line B's are still in it, and the
        // rejection issue is measured against a balance that carries both.
        // Quality must not be blocked by a hold on somebody else's lorry.
        $lineA = $this->arrival('QH-LOT-P', '100', 4, '25', 'qh-key-a');
        $this->arrival('QH-LOT-Q', '100', 4, '25', 'qh-key-b');

        $this->assertSame(0, bccomp($this->balance(), '200', 4));
        $this->genericIssue('1')->assertStatus(422);

        $this->postJson('/api/v1/quality/incoming-inspections', [
            'goods_receipt_note_line_id' => $lineA,
            'inspected_quantity' => '100',
            'accepted_quantity' => '0',
            'rejected_quantity' => '100',
            'inspection_date' => '2026-08-18',
        ])->assertCreated();

        // The rejected 100 kg left the balance; the other lorry is still held.
        $this->assertSame(0, bccomp($this->balance(), '100', 4));
        $this->genericIssue('1')->assertStatus(422);

        $this->assertSame(
            4,
            MaterialBag::query()->where('status', MaterialBagStatus::RejectedQc->value)->count(),
        );
    }

    public function test_a_partial_rejection_still_releases_exactly_the_accepted_kilograms(): void
    {
        $line = $this->arrival('QH-LOT-R', '100', 4, '25', 'qh-key-c');

        $this->postJson('/api/v1/quality/incoming-inspections', [
            'goods_receipt_note_line_id' => $line,
            'inspected_quantity' => '100',
            'accepted_quantity' => '50',
            'rejected_quantity' => '50',
            'inspection_date' => '2026-08-18',
        ])->assertCreated();

        $this->assertSame(0, bccomp($this->balance(), '50', 4));

        $this->genericIssue('50')->assertCreated();
        $this->assertSame(0, bccomp($this->balance(), '0', 4));
    }

    public function test_the_rejection_door_is_narrow_and_has_exactly_one_caller(): void
    {
        // A guard with a bypass is only as good as the list of things that
        // can reach the bypass. This test IS that list.
        $callers = [];
        $root = dirname(__DIR__, 3).'/app';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php'
                && str_contains((string) file_get_contents($file->getPathname()), 'recordIncomingQcRejectionIssue')) {
                $callers[] = str_replace($root.'/', '', $file->getPathname());
            }
        }
        sort($callers);

        $this->assertSame([
            'Modules/Inventory/Services/StockMovementService.php',
            'Modules/Quality/Services/IncomingInspectionService.php',
        ], $callers);
    }

    public function test_no_public_writer_offers_a_hold_bypass_flag(): void
    {
        // The rejection door is a named method, never a boolean somebody can
        // pass — a boolean is one FormRequest away from being selectable
        // from an HTTP payload.
        foreach ((new ReflectionClass(StockMovementService::class))->getMethods() as $method) {
            if (! $method->isPublic()) {
                continue;
            }
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                $isBool = $type instanceof ReflectionNamedType && $type->getName() === 'bool';

                $this->assertFalse(
                    $isBool && $parameter->getName() !== 'allowNegative',
                    "StockMovementService::{$method->getName()} takes a new boolean \${$parameter->getName()} — "
                    .'a QC-hold bypass must be a named method, not a flag.',
                );
            }
        }

        $this->assertTrue(method_exists(StockMovementService::class, 'recordIncomingQcRejectionIssue'));
    }

    public function test_the_service_itself_refuses_a_transfer_not_merely_the_http_layer(): void
    {
        $this->stock('100');
        $this->bags('QH-LOT-N', 4, '25', MaterialBagStatus::WaitingQc);

        $this->expectException(IncomingQcHoldException::class);

        try {
            app(StockMovementService::class)->recordTransfer(
                itemId: $this->resin->id,
                fromWarehouseId: $this->store->id,
                toWarehouseId: $this->farStore->id,
                quantity: '100',
            );
        } finally {
            $this->assertSame(0, bccomp($this->balance(), '100', 4));
            $this->assertSame(0, bccomp($this->balance($this->farStore), '0', 4));
        }
    }

    public function test_nothing_outside_the_guarded_service_reaches_for_a_stock_balance_to_write_it(): void
    {
        // THE SWEEP, AS A TEST. The guard is worth exactly as much as the
        // claim that every outflow passes through it, and that claim rests on
        // StockMovementService being the only writer of stock_balances. A
        // future service reaching for the table directly would be invisible
        // to every other test in this file, so it is checked rather than
        // trusted — through the MODEL and through the raw table, because a
        // DB::table('stock_balances')->decrement() would slip past a grep for
        // the Eloquent class.
        //
        // WHAT IT CANNOT SEE, said plainly rather than implied away: a write
        // through a model instance already sitting in a variable
        // ($balance->update(...) two statements after the query) is beyond a
        // regex, and is the form StockMovementService's own
        // increment/decrementBalance use. This is a tripwire for the obvious
        // route in, not a proof of exhaustiveness — the proof that outflows
        // are covered is that they all land in decrementBalance, which the
        // rest of this file exercises door by door.
        //
        // ResetTestData is the one expected exception and stays one: a
        // console command that zeroes the whole table to reset a demo
        // instance, which is not an outflow and has no hold to respect.
        // CheckStockLedger reads the raw table and writes nothing, so it does
        // not appear — if it ever does, it has stopped being read-only.
        $writers = [];
        $root = dirname(__DIR__, 3).'/app';
        $patterns = [
            // A write chained straight off the model.
            '/StockBalance::\s*(?:query\(\)[^;]*?->\s*(?:update|increment|decrement|delete|updateOrCreate)\(|create\(|updateOrCreate\()/s',
            // The same thing done past Eloquent entirely.
            '/DB::table\(\s*[\'"]stock_balances[\'"]\s*\)[^;]*?->\s*(?:update|insert|insertOrIgnore|upsert|delete|truncate|increment|decrement)\(/s',
        ];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $source) === 1) {
                    $writers[] = str_replace($root.'/', '', $file->getPathname());
                    break;
                }
            }
        }
        sort($writers);

        $this->assertSame([
            'Console/Commands/ResetTestData.php',
            'Modules/Inventory/Services/StockMovementService.php',
        ], $writers);
    }

    // =====================================================================
    // E. Reach: which stores, which items, and the fail-closed edge
    // =====================================================================

    public function test_a_hold_in_one_store_does_not_withhold_another_store_s_material(): void
    {
        $this->stock('100');
        $this->stock('100', $this->farStore);
        $this->bags('QH-LOT-S', 4, '25', MaterialBagStatus::WaitingQc);

        $this->genericIssue('100', $this->farStore)->assertCreated();
        $this->assertSame(0, bccomp($this->balance($this->farStore), '0', 4));
        $this->genericIssue('1')->assertStatus(422);
    }

    public function test_an_item_with_no_bags_is_untouched_at_every_door(): void
    {
        $tray = Item::create([
            'sku' => 'QH-TRAY', 'name' => 'QH Tray', 'uom' => 'Nos',
            'is_active' => true, 'is_production_input' => true,
        ]);
        $this->stock('500', null, $tray);

        // Another material's hold, in this same store, holds nothing of this one.
        $this->bags('QH-LOT-T', 4, '25', MaterialBagStatus::WaitingQc);

        $this->postJson('/api/v1/inventory/stock-movements/issues', [
            'item_id' => $tray->id,
            'warehouse_id' => $this->store->id,
            'quantity' => '500',
        ])->assertCreated();

        $this->assertSame(0, bccomp($this->balance(null, $tray), '0', 4));
    }

    public function test_a_held_bag_with_no_store_recorded_freezes_the_material_everywhere(): void
    {
        // Fail-closed cover, pinned so it is deliberate: no code path creates
        // a `waiting_qc` bag without a warehouse (a lot without a GRN is born
        // `in_store`), but a backfill could. Nothing says which store it is
        // in, so it is held against all of them — the blunt answer, not the
        // permissive one.
        $this->stock('100');
        $this->stock('100', $this->farStore);
        $this->bags('QH-LOT-U', 1, '10', MaterialBagStatus::WaitingQc);
        MaterialBag::query()->where('barcode', 'QH-LOT-U-B1')->update(['current_warehouse_id' => null]);

        $this->genericIssue('95')->assertStatus(422);
        $this->genericIssue('95', $this->farStore)->assertStatus(422);
        $this->genericIssue('90')->assertCreated();
    }

    // =====================================================================
    // F. The concurrency contract, as far as sqlite can carry it
    // =====================================================================

    public function test_bags_are_read_before_balances_in_every_decrement(): void
    {
        // lockForUpdate compiles to nothing on sqlite, so this cannot prove a
        // serialisation. What it CAN prove — and what a deadlock actually
        // turns on — is the ORDER the two tables are taken in. Bags first,
        // balance second, on every outflow door; IncomingInspectionService
        // takes them in that same order. Nothing reverses it, so no pair of
        // these can wait on each other.
        $this->stock('100');
        $this->bags('QH-LOT-V', 1, '10', MaterialBagStatus::WaitingQc);

        $seen = [];
        DB::listen(function ($query) use (&$seen) {
            foreach (['material_bags', 'stock_balances'] as $table) {
                if (str_contains($query->sql, $table) && ! in_array($table, $seen, true)) {
                    $seen[] = $table;
                }
            }
        });

        $this->genericIssue('10')->assertCreated();
        $this->assertSame(['material_bags', 'stock_balances'], $seen, 'door: generic issue');

        $seen = [];
        $this->genericTransfer('10')->assertOk();
        $this->assertSame(['material_bags', 'stock_balances'], $seen, 'door: generic transfer');

        $wip = Warehouse::create(['code' => 'QH-WIP', 'name' => 'QH Work In Progress', 'is_active' => false]);
        app(ProductionWipLocationResolver::class)->setWarehouseId($wip->id);

        $seen = [];
        $this->postJson('/api/v1/inventory/store-issues', [
            'lines' => [[
                'item_id' => $this->resin->id,
                'quantity' => '10',
                'from_warehouse_id' => $this->store->id,
            ]],
        ])->assertCreated();
        $this->assertSame(['material_bags', 'stock_balances'], $seen, 'door: typed store issue');
    }

    public function test_the_rejection_issue_reads_no_bags_of_its_own(): void
    {
        // Why the rejection has its own door, stated as a test rather than as
        // a comment: dispositionBags already holds ONE GRN line's bags when it
        // issues. Were that issue to take the item-wide `waiting_qc` lock the
        // guard takes, two inspections on two lines of the same material would
        // each be waiting on bags the other holds. So the rejection issue must
        // read no bag set at all — only the balance.
        $line = $this->arrival('QH-LOT-W', '100', 4, '25', 'qh-key-d');

        $tables = [];
        DB::listen(function ($query) use (&$tables) {
            $tables[] = $query->sql;
        });

        app(IncomingInspectionService::class)->create([
            'goods_receipt_note_line_id' => $line,
            'inspected_quantity' => '100',
            'accepted_quantity' => '0',
            'rejected_quantity' => '100',
            'inspection_date' => '2026-08-18',
        ], $this->storeKeeper->id);

        // The one bag read this transaction makes is dispositionBags' own,
        // narrowed to this GRN line through its lot — never the item-wide
        // status scan the guard performs.
        $bagReads = array_values(array_filter(
            $tables,
            fn (string $sql) => str_starts_with($sql, 'select') && str_contains($sql, '"material_bags"'),
        ));

        $this->assertCount(1, $bagReads);
        $this->assertStringContainsString('goods_receipt_note_line_id', $bagReads[0]);
    }
}
