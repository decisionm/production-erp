<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Models\Enums\ShiftScrapType;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftMaterialConsumption;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\FactoryDayBinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ESTIMATED RESIN REMAINING PER MACHINE — the read that replaced the central
 * day-bin bookkeeping, in the owner's own words (31-Jul):
 *
 *   "Our factory does not use a Day Bin warehouse or an evening physical bin
 *    weight. Replace that idea with estimated resin remaining for each
 *    machine: previous carryover plus barcode-scanned loads minus calculated
 *    consumption."
 *
 *   "Scanning a bag means material was loaded into the selected machine; it
 *    does not mean the whole quantity was consumed."
 *
 *   "A correction must replace the previous calculation. The current resin
 *    quantity, totals and voucher preview must never count every correction
 *    as fresh consumption."
 *
 * What must hold:
 *  - a scan names its machine, and the kg land against THAT machine only;
 *  - the estimate is loads − consumption, per machine per material, running
 *    from the first scan of that material into that machine (a pair nobody
 *    has ever scanned has no baseline and is not reported);
 *  - amending twice MOVES the figure, it never grows it;
 *  - a negative estimate is served honestly, never clamped;
 *  - piece-counted materials (cartons) never appear in a kilogram estimate;
 *  - a quality rejection never touches a kilogram of it;
 *  - the physical-count reconciliation endpoint is gone.
 *
 * THE FIXTURE'S ARITHMETIC, once:
 *   bottle = 10.0 g, so 1,000 pieces = 10 kg exactly.
 */
class MachineResinEstimateTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Item $resin;

    private Item $carton;

    private Warehouse $fg;

    private Warehouse $rm;

    /**
     * The internal machine-WIP location a scan moves stock INTO. In the books
     * that is all a load is — a location change; Tally still sees one godown.
     * Which machine got it is the ledger's job, not a warehouse's.
     */
    private Warehouse $wip;

    private Shift $shift;

    private WorkCenter $machineOne;

    private WorkCenter $machineTwo;

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.traceability_enabled' => true]);

        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->machineOne = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'is_active' => true]);
        $this->machineTwo = WorkCenter::create(['code' => 'MC-02', 'name' => 'Machine 2', 'is_active' => true]);

        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg']);
        $this->rm = Warehouse::create(['code' => 'RM', 'name' => 'RM Store', 'is_active' => true, 'tally_guid' => 'gd-rm']);
        $this->wip = Warehouse::create(['code' => 'WIP', 'name' => 'Machine WIP', 'is_active' => true]);

        $this->resin = Item::create([
            'sku' => 'PET-IV08', 'name' => 'Billion Pet Resin IV-0.8', 'uom' => 'Kgs.',
            'is_active' => true, 'tally_stock_item_guid' => 'itm-resin',
        ]);

        // Counted in Nos and filed into a column named quantity_issued_kg by
        // the "Other materials" repeater — the exact row that would otherwise
        // read as "-500 kg of cartons remaining".
        $this->carton = Item::create([
            'sku' => 'CTN-800', 'name' => 'Carton 800', 'uom' => 'Nos.',
            'is_active' => true, 'tally_stock_item_guid' => 'itm-carton',
        ]);

        $this->bottle = Item::create([
            'sku' => 'BTL-500-AMB', 'name' => '500 ml Round Amber', 'uom' => 'Nos.',
            'is_active' => true, 'nominal_weight_grams' => '10.0000',
            'standard_cycle_time' => '12.00', 'standard_cavities' => 5, 'nos_per_box' => 800,
            'tally_stock_item_guid' => 'itm-bottle',
        ]);

        app(FactoryDayBinService::class)->setWarehouseId($this->wip->id);

        foreach ([[$this->resin, '2000'], [$this->carton, '5000']] as [$item, $quantity]) {
            app(StockMovementService::class)->recordReceipt(
                itemId: $item->id, warehouseId: $this->rm->id,
                quantity: $quantity, unitCost: '50', reference: 'opening', createdBy: null,
            );
        }
    }

    private function actAsSupervisor(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['production.view', 'production.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo(['production.view', 'production.manage']);
        Sanctum::actingAs($user);

        return $user;
    }

    /** A registered bag of resin sitting in the store, ready to scan. */
    private function bag(string $barcode, string $kg): MaterialBag
    {
        $lot = MaterialLot::create([
            'item_id' => $this->resin->id,
            'received_date' => '2026-07-31',
            'bag_count' => 1,
            'total_received_kg' => $kg,
        ]);

        return $lot->bags()->create([
            'barcode' => $barcode,
            'original_kg' => $kg,
            'remaining_kg' => $kg,
            'status' => MaterialBagStatus::InStore,
            'current_warehouse_id' => $this->rm->id,
        ]);
    }

    private function scan(string $barcode, WorkCenter $machine, ?string $quantityKg = null): void
    {
        $this->postJson('/api/v1/production/day-bin/load-bag', array_filter([
            'barcode' => $barcode,
            'work_center_id' => $machine->id,
            'quantity_kg' => $quantityKg,
        ]))->assertOk();
    }

    private function startBatch(WorkCenter $machine): int
    {
        return $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => '2026-07-31',
        ])->assertOk()->json('data.id');
    }

    /**
     * A completion carrying the owner's own resin arithmetic: (packed pieces
     * + production-rejected pieces) × 10 g + lumps kg.
     *
     * @return array<string, mixed>
     */
    private function figures(int $pieces, int $rejectedPieces = 0, string $lumpsKg = '0', ?string $resinKg = null): array
    {
        $resinKg ??= bcadd(
            bcdiv(bcmul((string) ($pieces + $rejectedPieces), '10.0000', 4), '1000', 4),
            $lumpsKg,
            4,
        );

        return [
            'quantity_produced' => (string) $pieces,
            'quantity_scrap' => (string) $rejectedPieces,
            'running_hours' => '8',
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'warehouse_id' => $this->rm->id, 'quantity_issued_kg' => $resinKg],
            ],
            'scraps' => bccomp($lumpsKg, '0', 4) === 1
                ? [['type' => ShiftScrapType::Lumps->value, 'quantity_kg' => $lumpsKg]]
                : [],
        ];
    }

    /** The estimate rows for one machine, keyed by item id. */
    private function estimateFor(WorkCenter $machine): array
    {
        $data = $this->getJson('/api/v1/production/machine-resin')->assertOk()->json('data');

        $machineRow = collect($data)->firstWhere('work_center_id', $machine->id);

        return collect($machineRow['materials'] ?? [])->keyBy('item_id')->all();
    }

    // (a) a load belongs to the machine it was scanned into --------------------

    public function test_a_scan_credits_the_machine_it_names_and_no_other(): void
    {
        $this->actAsSupervisor();
        $this->bag('BAG-A', '500');
        $this->bag('BAG-B', '300');

        $this->scan('BAG-A', $this->machineOne);
        $this->scan('BAG-B', $this->machineTwo);

        $one = $this->estimateFor($this->machineOne);
        $two = $this->estimateFor($this->machineTwo);

        $this->assertSame('500.0000', $one[$this->resin->id]['loaded_kg']);
        $this->assertSame('0.0000', $one[$this->resin->id]['consumed_kg']);
        $this->assertSame('500.0000', $one[$this->resin->id]['estimated_remaining_kg']);
        $this->assertNotNull($one[$this->resin->id]['last_load_at']);

        $this->assertSame('300.0000', $two[$this->resin->id]['loaded_kg']);
        $this->assertSame('300.0000', $two[$this->resin->id]['estimated_remaining_kg']);
    }

    public function test_a_partial_scan_credits_only_the_weighed_kg_and_the_bag_keeps_the_rest(): void
    {
        $this->actAsSupervisor();
        $bag = $this->bag('BAG-A', '500');

        // "Scanning a bag means material was loaded into the selected
        // machine; it does not mean the whole quantity was consumed."
        $this->scan('BAG-A', $this->machineOne, '120.5');

        $this->assertSame('379.5000', $bag->fresh()->remaining_kg);
        $this->assertSame(MaterialBagStatus::InStore, $bag->fresh()->status);

        $estimate = $this->estimateFor($this->machineOne);
        $this->assertSame('120.5000', $estimate[$this->resin->id]['loaded_kg']);
        $this->assertSame('120.5000', $estimate[$this->resin->id]['estimated_remaining_kg']);

        // The same bag can be poured into a second machine later — which is
        // exactly why the load has to be a ledger row and not a pointer on
        // the bag.
        $this->scan('BAG-A', $this->machineTwo, '79.5');
        $this->assertSame('300.0000', $bag->fresh()->remaining_kg);
        $this->assertSame('79.5000', $this->estimateFor($this->machineTwo)[$this->resin->id]['loaded_kg']);
        $this->assertSame('120.5000', $this->estimateFor($this->machineOne)[$this->resin->id]['loaded_kg']);
    }

    // (b) the owner's arithmetic ------------------------------------------------

    public function test_the_estimate_is_loads_minus_the_calculated_consumption_of_that_machines_batches(): void
    {
        $this->actAsSupervisor();
        $this->bag('BAG-A', '500');
        $this->scan('BAG-A', $this->machineOne);

        // 9,500 packed + 500 production-rejected = 10,000 pieces × 10 g =
        // 100 kg, plus 2 kg of lumps = 102 kg consumed.
        $entryId = $this->startBatch($this->machineOne);
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $this->figures(9500, 500, '2'))
            ->assertOk();

        $estimate = $this->estimateFor($this->machineOne)[$this->resin->id];
        $this->assertSame('500.0000', $estimate['loaded_kg']);
        $this->assertSame('102.0000', $estimate['consumed_kg']);
        $this->assertSame('398.0000', $estimate['estimated_remaining_kg']);

        // Another machine's batch is another machine's material.
        $this->assertArrayNotHasKey($this->machineTwo->id, collect(
            $this->getJson('/api/v1/production/machine-resin')->json('data')
        )->keyBy('work_center_id')->all());
    }

    public function test_carryover_needs_no_daily_cutoff_because_the_running_total_is_the_carryover(): void
    {
        $this->actAsSupervisor();
        $this->bag('BAG-A', '500');
        $this->bag('BAG-B', '200');

        $this->scan('BAG-A', $this->machineOne);
        $first = $this->startBatch($this->machineOne);
        $this->postJson("/api/v1/production/shift-production-entries/{$first}/complete", $this->figures(10000))
            ->assertOk();

        // A second shift on the same machine, days later — nothing is reset,
        // nothing is opened, the figure simply carries.
        $this->scan('BAG-B', $this->machineOne);
        $second = $this->startBatch($this->machineOne);
        $this->postJson("/api/v1/production/shift-production-entries/{$second}/complete", $this->figures(5000))
            ->assertOk();

        $estimate = $this->estimateFor($this->machineOne)[$this->resin->id];
        $this->assertSame('700.0000', $estimate['loaded_kg']);
        $this->assertSame('150.0000', $estimate['consumed_kg']);
        $this->assertSame('550.0000', $estimate['estimated_remaining_kg']);
    }

    // (c) a correction REPLACES, it never accumulates ---------------------------

    public function test_amending_twice_moves_the_estimate_it_never_grows_it(): void
    {
        $this->actAsSupervisor();
        $this->bag('BAG-A', '500');
        $this->scan('BAG-A', $this->machineOne);

        $entryId = $this->startBatch($this->machineOne);

        // Counted wrong: 12,000 pieces = 120 kg.
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $this->figures(12000))
            ->assertOk();
        $this->assertSame('380.0000', $this->estimateFor($this->machineOne)[$this->resin->id]['estimated_remaining_kg']);

        // First correction: 10,000 pieces = 100 kg.
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/amend", [
            ...$this->figures(10000),
            'amendment_reason' => 'Counted the last pallet twice',
        ])->assertOk();

        $afterFirst = $this->estimateFor($this->machineOne)[$this->resin->id];
        $this->assertSame('100.0000', $afterFirst['consumed_kg'], 'A correction replaces the previous calculation.');
        $this->assertSame('400.0000', $afterFirst['estimated_remaining_kg']);

        // Second correction: 9,000 pieces = 90 kg. The figure MOVES again.
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/amend", [
            ...$this->figures(9000),
            'amendment_reason' => 'One tray was short',
        ])->assertOk();

        $afterSecond = $this->estimateFor($this->machineOne)[$this->resin->id];
        $this->assertSame('90.0000', $afterSecond['consumed_kg']);
        $this->assertSame('410.0000', $afterSecond['estimated_remaining_kg']);

        // The whole point, stated as the thing that would break: had the
        // corrections accumulated, consumption would read 120 + 100 + 90 =
        // 310 kg and the machine would look nearly empty. Exactly ONE
        // consumption row stands, and it is the latest calculation.
        $this->assertSame(1, ShiftMaterialConsumption::query()
            ->where('shift_production_entry_id', $entryId)->count());
    }

    // (d) honest negatives -------------------------------------------------------

    public function test_a_machine_that_ran_past_what_was_scanned_into_it_reads_negative_and_is_not_clamped(): void
    {
        $this->actAsSupervisor();

        // 40 kg scanned, then a shift that burnt 100 kg — the machine ran on
        // 60 kg nobody logged.
        $this->bag('BAG-A', '40');
        $this->scan('BAG-A', $this->machineOne);

        $entryId = $this->startBatch($this->machineOne);
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $this->figures(10000))
            ->assertOk();

        $estimate = $this->estimateFor($this->machineOne)[$this->resin->id];
        $this->assertSame('40.0000', $estimate['loaded_kg']);
        $this->assertSame('100.0000', $estimate['consumed_kg']);
        // Clamping this at zero would erase the one thing on the page worth
        // acting on: loads are being missed at the scanner.
        $this->assertSame('-60.0000', $estimate['estimated_remaining_kg']);
    }

    // (e) where the running total starts -----------------------------------------

    public function test_a_machine_nobody_has_scanned_into_is_not_reported_at_all(): void
    {
        $this->actAsSupervisor();

        // The rollout case, and the reason this rule exists. Consumption rows
        // predate the scanner (and scanning is behind a config flag a
        // deployment may never have turned on), so an all-time subtraction
        // would open with every machine in the factory reporting a deficit
        // equal to its whole history — indistinguishable from the genuine
        // "material burnt without a scan" signal above.
        $entryId = $this->startBatch($this->machineOne);
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $this->figures(10000))
            ->assertOk();

        $this->assertSame([], $this->getJson('/api/v1/production/machine-resin')->assertOk()->json('data'));
    }

    public function test_consumption_from_before_the_first_scan_is_not_subtracted(): void
    {
        $this->actAsSupervisor();

        // Yesterday's shift, out of a hopper nobody logged.
        $before = $this->startBatch($this->machineOne);
        $this->postJson("/api/v1/production/shift-production-entries/{$before}/complete", $this->figures(10000))
            ->assertOk();

        // The scanner starts today.
        $this->travel(1)->days();
        $this->bag('BAG-A', '500');
        $this->scan('BAG-A', $this->machineOne);

        $after = $this->startBatch($this->machineOne);
        $this->postJson("/api/v1/production/shift-production-entries/{$after}/complete", $this->figures(8000))
            ->assertOk();

        $estimate = $this->estimateFor($this->machineOne)[$this->resin->id];
        $this->assertSame('500.0000', $estimate['loaded_kg']);
        // 80 kg, not 180: the count begins where the evidence does. The
        // unknown carryover in the hopper on day one is taken as zero and
        // stated as such, rather than charged to the first scan.
        $this->assertSame('80.0000', $estimate['consumed_kg']);
        $this->assertSame('420.0000', $estimate['estimated_remaining_kg']);
    }

    // (f) kilograms only ---------------------------------------------------------

    public function test_a_piece_counted_material_never_appears_in_a_kilogram_estimate(): void
    {
        $this->actAsSupervisor();
        $this->bag('BAG-A', '500');
        $this->scan('BAG-A', $this->machineOne);

        $entryId = $this->startBatch($this->machineOne);
        $figures = $this->figures(10000);
        $figures['material_consumptions'][] = [
            'item_id' => $this->carton->id,
            'warehouse_id' => $this->rm->id,
            // 13 cartons, filed into a column named kg by the exceptions
            // repeater — a real row shape, not a contrived one.
            'quantity_issued_kg' => '13',
        ];

        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $figures)->assertOk();

        $estimate = $this->estimateFor($this->machineOne);
        $this->assertArrayHasKey($this->resin->id, $estimate);
        $this->assertArrayNotHasKey(
            $this->carton->id,
            $estimate,
            'Cartons are counted in Nos — a kilogram estimate must never claim to know how many are left.',
        );
    }

    public function test_the_read_narrows_to_one_machine_on_request(): void
    {
        $this->actAsSupervisor();
        $this->bag('BAG-A', '500');
        $this->bag('BAG-B', '300');
        $this->scan('BAG-A', $this->machineOne);
        $this->scan('BAG-B', $this->machineTwo);

        $data = $this->getJson('/api/v1/production/machine-resin?work_center_id='.$this->machineTwo->id)
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data);
        $this->assertSame($this->machineTwo->id, $data[0]['work_center_id']);
        $this->assertSame('MC-02', $data[0]['work_center']['code']);
        $this->assertSame('300.0000', $data[0]['materials'][0]['loaded_kg']);

        // A filter it cannot honour is refused, not quietly widened to "every
        // machine" (garbage) or narrowed to nothing (a machine that does not
        // exist) — both are confident answers to a question nobody asked.
        $this->getJson('/api/v1/production/machine-resin?work_center_id=abc')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_center_id']);
        $this->getJson('/api/v1/production/machine-resin?work_center_id=99999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_center_id' => 'No such machine.']);
    }

    // (g) QC rejection is not consumption ----------------------------------------

    public function test_a_quality_rejection_never_adds_to_resin_consumption_a_second_time(): void
    {
        // The owner, on why this is a trap worth a test of its own (31-Jul):
        // "QC-rejected pieces are already part of the packed quantity. They
        // reduce approved finished goods, but must not be added to resin
        // consumption a second time."
        $this->actAsSupervisor();
        $this->bag('BAG-A', '500');
        $this->scan('BAG-A', $this->machineOne);

        $entryId = $this->startBatch($this->machineOne);

        // 10,000 packed to QC + 500 rejected on the machine = 10,500 pieces
        // × 10 g = 105 kg, plus 1 kg of lumps = 106 kg.
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $this->figures(10000, 500, '1'))
            ->assertOk();

        $before = $this->estimateFor($this->machineOne)[$this->resin->id];
        $this->assertSame('106.0000', $before['consumed_kg']);
        $this->assertSame('394.0000', $before['estimated_remaining_kg']);

        $consumptionBefore = ShiftMaterialConsumption::query()
            ->where('shift_production_entry_id', $entryId)
            ->pluck('quantity_issued_kg', 'item_id')
            ->all();

        // Quality checks the packed pieces and rejects 800 of them.
        $checker = User::factory()->create(['is_active' => true]);
        foreach (['quality.view', 'quality.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $checker->givePermissionTo(['quality.view', 'quality.manage']);
        Sanctum::actingAs($checker);

        $checked = $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/quality-check", [
            'reviewed_nos' => 10000, 'ok_nos' => 9200, 'rejected_nos' => 800,
        ])->assertOk();

        // The check DID what it is for: approved finished goods fell.
        $this->assertSame(9200.0, (float) $checked->json('data.quantity_produced'));
        $this->assertSame(10000.0, (float) $checked->json('data.gross_quantity_produced'));

        // And did NOT touch a single kilogram of material. Those 800 bottles
        // were moulded out of resin already counted in the 106 kg — counting
        // them again would invent 8 kg of consumption the factory never had.
        $this->assertSame(
            $consumptionBefore,
            ShiftMaterialConsumption::query()
                ->where('shift_production_entry_id', $entryId)
                ->pluck('quantity_issued_kg', 'item_id')
                ->all(),
        );

        $this->actAsSupervisor();
        $after = $this->estimateFor($this->machineOne)[$this->resin->id];
        $this->assertSame($before, $after, 'A quality check moves finished goods, never the resin estimate.');
    }

    // (h) the physical count is gone ---------------------------------------------

    public function test_the_reconciliation_endpoint_is_retired(): void
    {
        $this->actAsSupervisor();

        // The factory takes no evening bin weight, so the read that waited
        // for one is gone rather than left on the screen looking answerable.
        $this->getJson('/api/v1/production/factory-day-bin/reconciliation')->assertNotFound();
    }
}
