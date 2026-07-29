<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftMaterialConsumption;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\ShiftScrap;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ShiftProductionEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The expected-output engine (SHIFT-REDESIGN-FORMULAS.md #22-24, #9/#10/#20):
 * expected pieces/boxes from the molding standards, boxes-based efficiency,
 * QC-vs-production rejection, and the issued-kg reconciliation. Every fixture
 * below is lifted from the workbooks (WB1/WB2) — see the formula dictionary's
 * §4 worked examples.
 */
class ExpectedOutputEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // This suite exercises the expected-output formulas, not the production-readiness gate.
        // Its fixtures are deliberately minimal items (no weight, no Tally
        // identity), which the fail-closed gate would refuse at Start Batch.
        // Turning enforcement off here keeps each test on its own subject;
        // the gate itself is covered by ProductReadinessGateTest.
        config()->set('production.readiness.enforced', false);
    }

    /**
     * In-memory completed entry with its relations set (OutboundVoucherTest
     * pattern) — productionMetrics() is pure computation, so no DB needed.
     */
    private function completedEntry(
        array $attributes = [],
        array $itemAttributes = [],
        array $consumptions = [],
        array $scraps = [],
    ): ShiftProductionEntry {
        $entry = new ShiftProductionEntry($attributes + ['batch_status' => BatchStatus::Completed]);
        $entry->setRelation('item', new Item($itemAttributes + ['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'NOS']));
        $entry->setRelation('materialConsumptions', collect($consumptions)->map(fn ($line) => new ShiftMaterialConsumption($line)));
        $entry->setRelation('scraps', collect($scraps)->map(fn ($line) => new ShiftScrap($line)));

        return $entry;
    }

    private function metrics(ShiftProductionEntry $entry): ?array
    {
        return app(ShiftProductionEntryService::class)->productionMetrics($entry);
    }

    public function test_wb2_row_6_ct_10_6_five_cavities_full_shift_pack_840(): void
    {
        // WB2 row 6: CT=10.6, CAV=5, HRS=8, PACK=840 → 13584.9056… pieces,
        // 16.1725… → 16 boxes; actual 7 boxes → 43.75 → 43.8 at 1dp.
        $metrics = $this->metrics($this->completedEntry(
            attributes: [
                'standard_cycle_time' => '10.6',
                'active_cavities' => 5,
                'running_hours' => '8',
                'quantity_produced' => '5880',
                'no_of_box' => 7,
            ],
            itemAttributes: ['nos_per_box' => 840],
        ));

        $this->assertSame('13584.91', $metrics['expected_pieces']);
        $this->assertSame(16, $metrics['expected_boxes']);
        $this->assertSame(7, $metrics['actual_boxes']);
        $this->assertSame('5880', $metrics['actual_pieces']);
        $this->assertSame(43.8, $metrics['efficiency_pct']);
    }

    public function test_wb2_row_7_ct_12_five_cavities_full_shift_pack_1040(): void
    {
        // WB2 row 7: CT=12, CAV=5, HRS=8, PACK=1040 → 12000 pieces,
        // 11.538… → 12 boxes.
        $metrics = $this->metrics($this->completedEntry(
            attributes: [
                'standard_cycle_time' => '12',
                'active_cavities' => 5,
                'running_hours' => '8',
            ],
            itemAttributes: ['nos_per_box' => 1040],
        ));

        $this->assertSame('12000.00', $metrics['expected_pieces']);
        $this->assertSame(12, $metrics['expected_boxes']);
    }

    public function test_partial_shift_four_hours_ct_13_6_two_cavities_pack_161(): void
    {
        // Partial-shift fixture: CT=13.6, CAV=2, HRS=4, PACK=161.
        // 3600/13.6 × 2 × 4 = 2117.647… → 2117.65; /161 = 13.153… → 13.
        // NOTE: WB2's partial-shift row 131 records W=9, but that row runs a
        // DIFFERENT product (its own CT/cavity/pack); with row 179's
        // standards at 4 hours the formula honestly yields 13, and that is
        // what the engine must report — the true value of the formula, not
        // the other row's cell.
        $metrics = $this->metrics($this->completedEntry(
            attributes: [
                'standard_cycle_time' => '13.6',
                'active_cavities' => 2,
                'running_hours' => '4',
            ],
            itemAttributes: ['nos_per_box' => 161],
        ));

        $this->assertSame('2117.65', $metrics['expected_pieces']);
        $this->assertSame(13, $metrics['expected_boxes']);
    }

    public function test_addendum_fixture_expected_15_actual_13_is_86_7_percent(): void
    {
        // The addendum fixture: expected 15 boxes, actual 13 → 86.666… →
        // 86.7 at 1dp. CT=12, CAV=5, HRS=8 → 12000 pieces; pack 800 → 15.
        $metrics = $this->metrics($this->completedEntry(
            attributes: [
                'standard_cycle_time' => '12',
                'active_cavities' => 5,
                'running_hours' => '8',
                'no_of_box' => 13,
            ],
            itemAttributes: ['nos_per_box' => 800],
        ));

        $this->assertSame(15, $metrics['expected_boxes']);
        $this->assertSame(13, $metrics['actual_boxes']);
        $this->assertSame(86.7, $metrics['efficiency_pct']);
    }

    public function test_reconciliation_wb1_row_62_issued_minus_good_rejection_lumps(): void
    {
        // WB1 row 62 data: issued 130, good 124.416, QC rejection 3.16,
        // lumps 0.5 → unaccounted 130 − 124.416 − 3.16 − 0.5 = 1.9240.
        $metrics = $this->metrics($this->completedEntry(
            attributes: [
                'quantity_produced_kg' => '124.416',
                'qc_rejection_kg' => '3.16',
            ],
            consumptions: [['quantity_issued_kg' => '130']],
            scraps: [['type' => 'lumps', 'quantity_kg' => '0.5']],
        ));

        $this->assertSame('130.0000', $metrics['issued_kg']);
        $this->assertSame('124.4160', $metrics['good_production_kg']);
        // QC weighed it, so QC wins as the confirmed figure.
        $this->assertSame('3.1600', $metrics['confirmed_rejection_kg']);
        $this->assertSame('0.5000', $metrics['lumps_kg']);
        $this->assertSame('1.9240', $metrics['reconciliation_unaccounted_kg']);
    }

    public function test_reconciliation_excludes_a_nos_unit_consumption_line(): void
    {
        // Same defect as ConsumptionVarianceTest's nos-unit case, on the
        // reconciliation side: 500 cartons entered through the exceptions
        // repeater must not read as 500 kg issued, or unaccounted_kg — the
        // number management investigates — is off by the carton count.
        $resin = new ShiftMaterialConsumption(['quantity_issued_kg' => '130']);
        $resin->setRelation('item', new Item(['sku' => 'PET', 'name' => 'PET Resin', 'uom' => 'Kgs.']));
        $cartons = new ShiftMaterialConsumption(['quantity_issued_kg' => '500']);
        $cartons->setRelation('item', new Item(['sku' => 'CTN', 'name' => 'Carton 24', 'uom' => 'Nos.']));

        $entry = new ShiftProductionEntry([
            'batch_status' => BatchStatus::Completed,
            'quantity_produced_kg' => '124.416',
            'qc_rejection_kg' => '3.16',
        ]);
        $entry->setRelation('item', new Item(['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'NOS']));
        $entry->setRelation('materialConsumptions', collect([$resin, $cartons]));
        $entry->setRelation('scraps', collect([new ShiftScrap(['type' => 'lumps', 'quantity_kg' => '0.5'])]));

        $metrics = $this->metrics($entry);

        // Identical to the WB1 row 62 fixture above — the carton line is
        // invisible to the kg reconciliation.
        $this->assertSame('130.0000', $metrics['issued_kg']);
        $this->assertSame('1.9240', $metrics['reconciliation_unaccounted_kg']);
    }

    public function test_qc_difference_wb2_row_7_production_7_7529_vs_qc_7_75(): void
    {
        // WB2 row 7: P=7.7529 (calculated), Q=7.75 (weighed) → R = 0.0029.
        $metrics = $this->metrics($this->completedEntry(
            attributes: [
                'quantity_rejection_kg' => '7.7529',
                'qc_rejection_kg' => '7.75',
            ],
        ));

        $this->assertSame('7.7529', $metrics['rejection_kg_production']);
        $this->assertSame('7.7500', $metrics['rejection_kg_qc']);
        $this->assertSame('0.0029', $metrics['rejection_diff_kg']);
    }

    public function test_missing_cycle_time_yields_null_expectations_never_fake_numbers(): void
    {
        // No CT captured — every expectation-derived figure must be null
        // (never a misleading number), while the reported actuals survive.
        $metrics = $this->metrics($this->completedEntry(
            attributes: [
                'active_cavities' => 5,
                'running_hours' => '8',
                'no_of_box' => 7,
                'quantity_produced' => '5880',
            ],
            itemAttributes: ['nos_per_box' => 840],
        ));

        $this->assertNotNull($metrics);
        $this->assertNull($metrics['expected_pieces']);
        $this->assertNull($metrics['expected_boxes']);
        $this->assertNull($metrics['efficiency_pct']);
        $this->assertSame(7, $metrics['actual_boxes']);
        $this->assertSame('5880', $metrics['actual_pieces']);
    }

    public function test_metrics_is_null_until_the_batch_completes(): void
    {
        $entry = $this->completedEntry(attributes: [
            'batch_status' => BatchStatus::InProgress,
            'standard_cycle_time' => '10.6',
            'active_cavities' => 5,
        ]);

        $this->assertNull($this->metrics($entry));
    }

    public function test_item_master_round_trips_colour_and_molding_standards(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('inventory.manage', 'web');
        $user->givePermissionTo('inventory.manage');
        Sanctum::actingAs($user);

        $id = $this->postJson('/api/v1/inventory/items', [
            'sku' => 'BTL-500', 'name' => '500ml Bottle', 'uom' => 'NOS',
            'colour' => 'Amber',
            'standard_cycle_time' => 10.6,
            'standard_cavities' => 5,
        ])
            ->assertCreated()
            ->assertJsonPath('data.colour', 'Amber')
            ->assertJsonPath('data.standard_cycle_time', '10.60')
            ->assertJsonPath('data.standard_cavities', 5)
            ->json('data.id');

        // Clearable — a wrong data load must not be sticky.
        $this->putJson("/api/v1/inventory/items/{$id}", ['standard_cycle_time' => null])
            ->assertOk()
            ->assertJsonPath('data.standard_cycle_time', null);

        // Zero/negative standards are rejected, not silently stored.
        $this->putJson("/api/v1/inventory/items/{$id}", ['standard_cycle_time' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['standard_cycle_time']);
        $this->putJson("/api/v1/inventory/items/{$id}", ['standard_cavities' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['standard_cavities']);
    }

    public function test_start_batch_snapshots_the_item_standards_and_defaults_active_cavities(): void
    {
        [$shift, $machine, $item, $warehouse] = $this->factoryFloor();

        $entry = app(ShiftProductionEntryService::class)->startBatch([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => '2026-07-27',
        ], null);

        $this->assertSame('10.60', (string) $entry->standard_cycle_time);
        $this->assertSame(5, $entry->standard_cavities);
        // Active cavities default to the standard until overridden.
        $this->assertSame(5, $entry->active_cavities);
        $this->assertNull($entry->actual_cycle_time);

        // The snapshot survives an item-master change — the entry keeps the
        // standard the shift actually ran against.
        $item->update(['standard_cycle_time' => '9.5', 'standard_cavities' => 4]);
        $this->assertSame('10.60', (string) $entry->fresh()->standard_cycle_time);
        $this->assertSame(5, $entry->fresh()->standard_cavities);
    }

    public function test_standards_are_never_writable_through_start_or_complete_requests(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.manage', 'web');
        $user->givePermissionTo('production.manage');
        Sanctum::actingAs($user);

        [$shift, $machine, $item, $warehouse] = $this->factoryFloor();

        // Start Batch: attempts to send standard_* are stripped (no rule in
        // StartBatchRequest), the snapshot comes from the item master.
        $response = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => '2026-07-27',
            'standard_cycle_time' => '99',
            'standard_cavities' => 99,
            'active_cavities' => 4,
        ])->assertSuccessful();

        $entryId = $response->json('data.id');
        $response->assertJsonPath('data.standard_cycle_time', '10.60')
            ->assertJsonPath('data.standard_cavities', 5)
            // Active cavities ARE editable — the override sticks.
            ->assertJsonPath('data.active_cavities', 4)
            // Not completed yet — no authoritative metrics.
            ->assertJsonPath('data.metrics', null);

        // Complete Batch: same rule — run actuals land, standards don't.
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => '5880',
            'no_of_box' => 7,
            'running_hours' => 8,
            'qc_rejection_kg' => 3.16,
            'actual_cycle_time' => 11.2,
            'standard_cycle_time' => '1',
            'standard_cavities' => 1,
        ])->assertOk()
            ->assertJsonPath('data.standard_cycle_time', '10.60')
            ->assertJsonPath('data.standard_cavities', 5)
            ->assertJsonPath('data.actual_cycle_time', '11.20')
            ->assertJsonPath('data.running_hours', '8.00')
            ->assertJsonPath('data.qc_rejection_kg', '3.1600')
            // The engine runs on the snapshot: CT 10.6 × 4 active cavities.
            // 3600/10.6 × 4 × 8 = 10867.9245… → 10867.92; /840 = 12.937 → 13.
            ->assertJsonPath('data.metrics.expected_pieces', '10867.92')
            ->assertJsonPath('data.metrics.expected_boxes', 13)
            ->assertJsonPath('data.metrics.actual_boxes', 7)
            // 7/13 × 100 = 53.846… → 53.8.
            ->assertJsonPath('data.metrics.efficiency_pct', 53.8);

        $this->assertDatabaseHas('shift_production_entries', [
            'id' => $entryId,
            'standard_cavities' => 5,
        ]);
    }

    /** @return array{0: Shift, 1: WorkCenter, 2: Item, 3: Warehouse} */
    private function factoryFloor(): array
    {
        return [
            Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']),
            WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']),
            Item::create([
                'sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'NOS',
                'standard_cycle_time' => '10.6', 'standard_cavities' => 5, 'nos_per_box' => 840,
            ]),
            Warehouse::create(['code' => 'WH-1', 'name' => 'FG Store']),
        ];
    }
}
