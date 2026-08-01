<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ShiftProductionEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Which weight the kg figures are computed at.
 *
 * THE LIVE DEFECT. Every kg on a completed batch — good production,
 * rejection, and therefore the unaccounted-material figure the accountant
 * approves against — used to be derived from `items.nominal_weight_grams`
 * read straight off the item master. Nothing writes that column for a
 * Tally-synced product: only the item CRUD screen and the seeders do. Real
 * products carry their weight on the approved product standard (or a machine
 * configuration), so on the floor the column is empty, quantity_produced_kg
 * came out null, and the whole reconciliation chain silently never fired —
 * no production kg, no rejection kg, no unaccounted kg, no variance banner.
 *
 * The rule these tests pin: the kg figures use the weight THE RUN RESOLVED
 * and froze in config_snapshot at Start Batch (configuration → standard →
 * item), falling back to the item column only for legacy rows whose snapshot
 * predates that key. With no weight anywhere the figures stay NULL — never
 * zero, which would report a batch that produced nothing and reconcile every
 * issued kilo as missing material.
 */
class RunResolvedWeightReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private WorkCenter $machine;

    private Shift $shift;

    private Warehouse $rm;

    private Warehouse $fg;

    private Item $resin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'is_active' => true]);
        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $this->rm = Warehouse::create(['code' => 'RM', 'name' => 'RM Store', 'is_active' => true, 'tally_guid' => 'gd-rm']);
        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg']);

        // "Kgs." with the trailing dot, exactly as the Tally master writes it.
        $this->resin = Item::create([
            'sku' => 'PET-IV08', 'name' => 'Billion Pet Resin IV-0.8', 'uom' => 'Kgs.',
            'is_active' => true, 'tally_stock_item_guid' => 'g-resin',
        ]);

        app(StockMovementService::class)->recordReceipt(
            itemId: $this->resin->id,
            warehouseId: $this->rm->id,
            quantity: '2000',
            unitCost: '0',
            reference: 'opening',
        );

        $user = User::factory()->create(['is_active' => true]);
        foreach (['production.view', 'production.manage', 'inventory.view', 'inventory.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo(['production.view', 'production.manage', 'inventory.view', 'inventory.manage']);
        Sanctum::actingAs($user);
    }

    /**
     * A Tally-synced finished good exactly as the live masters carry it:
     * NO nominal weight on the item, because nothing ever writes that column
     * for a synced master.
     */
    private function bottleWithoutItemWeight(array $attributes = []): Item
    {
        return Item::create($attributes + [
            'sku' => 'BTL-100-RND', 'name' => '100ML ROUND', 'uom' => 'Nos.', 'is_active' => true,
            'nos_per_box' => 810, 'colour' => 'Amber', 'tally_stock_item_guid' => 'g-bottle',
        ]);
    }

    /** The stored kg column at the 4dp the arithmetic works in. */
    private function kg(mixed $value): ?string
    {
        return $value === null ? null : bcadd((string) $value, '0', 4);
    }

    private function startBatch(Item $bottle): int
    {
        return $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $bottle->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => '2026-07-29',
        ])->assertOk()->json('data.id');
    }

    /**
     * 8,100 good pieces, 160 rejected, 110 kg of resin issued and 1.65 kg of
     * lumps — one shift's worth of figures, shared so the three weight cases
     * differ in nothing but the weight.
     */
    private function completeRun(int $entryId, string $produced = '8100', string $scrap = '160'): void
    {
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => $produced,
            'quantity_scrap' => $scrap,
            'running_hours' => '8',
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'warehouse_id' => $this->rm->id, 'quantity_issued_kg' => '110'],
            ],
            'scraps' => [['type' => 'lumps', 'quantity_kg' => '1.650']],
        ])->assertOk();
    }

    // =================================================================

    public function test_the_standards_weight_drives_every_kg_when_the_item_master_has_none(): void
    {
        $bottle = $this->bottleWithoutItemWeight();
        $this->assertNull($bottle->nominal_weight_grams, 'The live case is an item master with no weight at all.');

        // The factory's approved standard — the only place this product's
        // 12.9 g exists. One variant, so Start Batch resolves it unasked.
        ProductionStandard::create([
            'item_id' => $bottle->id,
            'source_product_name' => '100ML ROUND',
            'cavities' => 5,
            'unit_weight_grams' => '12.9000',
            'cycle_time' => '12.30',
            'status' => 'approved',
        ]);

        $entryId = $this->startBatch($bottle);

        $entry = ShiftProductionEntry::findOrFail($entryId);
        $this->assertSame('12.9000', $entry->config_snapshot['unit_weight_grams'],
            'Start Batch already froze the standard weight — the completion must use THAT.');

        $this->completeRun($entryId);
        $entry->refresh();

        // 8100 × 12.9 g = 104.49 kg good; 160 × 12.9 g = 2.064 kg rejected.
        // Before the fix both were null and nothing below existed.
        // (Normalized to 4dp: the columns carry no cast, so SQLite hands back
        // '104.49' where MySQL hands back '104.4900'.)
        $this->assertSame('104.4900', $this->kg($entry->quantity_produced_kg));
        $this->assertSame('2.0640', $this->kg($entry->quantity_rejection_kg));

        $metrics = app(ShiftProductionEntryService::class)->productionMetrics($entry);
        $this->assertSame('104.4900', $metrics['good_production_kg']);
        $this->assertSame('2.0640', $metrics['rejection_kg_production']);
        $this->assertNull($metrics['rejection_kg_qc']);
        $this->assertSame('2.0640', $metrics['confirmed_rejection_kg']);
        $this->assertSame('110.0000', $metrics['issued_kg']);
        $this->assertSame('1.6500', $metrics['lumps_kg']);
        // issued 110 − good 104.49 − rejection 2.064 − lumps 1.65 = 1.796 kg
        // unaccounted. THE number the accountant's variance banner shows.
        $this->assertSame('1.7960', $metrics['reconciliation_unaccounted_kg']);
        $this->assertSame('investigate', $metrics['unaccounted_band']);

        // The consumption norm answers off the same resolved weight — with no
        // BOM for this product, expected material IS pieces × 12.9 g. Sourced
        // from the item column it had no norm at all and every Tally-synced
        // product showed a blank expected/variance pair.
        $variance = app(ShiftProductionEntryService::class)->consumptionVariance($entry);
        $this->assertSame('item_weight', $variance['norm_source']);
        $this->assertSame('104.4900', $variance['expected_kg']);
        $this->assertSame('110.0000', $variance['actual_kg']);
        $this->assertSame('5.5100', $variance['variance_kg']);
        $this->assertSame(5.3, $variance['variance_pct']);
        $this->assertSame('investigate', $variance['variance_band']);
        $this->assertSame('1.7960', $variance['unaccounted_kg']);
    }

    public function test_a_legacy_entry_with_no_snapshot_weight_still_uses_the_item_column(): void
    {
        // Rows started before the snapshot carried a weight key at all. The
        // item master is all they have, and it must keep working exactly as
        // it did — every existing test that sets nominal_weight_grams to make
        // the kg figures appear depends on this fallback.
        $bottle = $this->bottleWithoutItemWeight(['nominal_weight_grams' => '20.0000']);

        $entry = ShiftProductionEntry::create([
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $bottle->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => '2026-07-29',
            'batch_status' => BatchStatus::InProgress,
            'running_hours' => '8',
        ]);
        $this->assertNull($entry->config_snapshot);

        app(ShiftProductionEntryService::class)->completeBatch($entry, [
            'quantity_produced' => '1000',
            'quantity_scrap' => '50',
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'warehouse_id' => $this->rm->id, 'quantity_issued_kg' => '25'],
            ],
        ], null);
        $entry->refresh();

        // 1000 × 20 g = 20 kg good; 50 × 20 g = 1 kg rejected.
        $this->assertSame('20.0000', $this->kg($entry->quantity_produced_kg));
        $this->assertSame('1.0000', $this->kg($entry->quantity_rejection_kg));

        $metrics = app(ShiftProductionEntryService::class)->productionMetrics($entry);
        $this->assertSame('20.0000', $metrics['good_production_kg']);
        // 25 − 20 − 1 − 0 lumps.
        $this->assertSame('4.0000', $metrics['reconciliation_unaccounted_kg']);

        $variance = app(ShiftProductionEntryService::class)->consumptionVariance($entry);
        $this->assertSame('item_weight', $variance['norm_source']);
        $this->assertSame('20.0000', $variance['expected_kg']);
    }

    public function test_a_run_with_no_weight_anywhere_reports_null_kg_never_zero(): void
    {
        // No configuration, no standard, no item weight: the snapshot records
        // the empty string this case has always written, and every kg figure
        // must stay null. Zero would say the shift produced nothing and hand
        // the accountant 110 kg of "unaccounted" material that is simply
        // unmeasurable.
        $bottle = $this->bottleWithoutItemWeight();

        $entryId = $this->startBatch($bottle);
        $entry = ShiftProductionEntry::findOrFail($entryId);
        $this->assertSame('', $entry->config_snapshot['unit_weight_grams']);

        $this->completeRun($entryId);
        $entry->refresh();

        $this->assertNull($entry->quantity_produced_kg);
        $this->assertNull($entry->quantity_rejection_kg);

        $metrics = app(ShiftProductionEntryService::class)->productionMetrics($entry);
        $this->assertNull($metrics['good_production_kg']);
        $this->assertNull($metrics['confirmed_rejection_kg']);
        // The material really was issued — that much is known and reported.
        $this->assertSame('110.0000', $metrics['issued_kg']);
        // But it cannot be reconciled against an unknown output.
        $this->assertNull($metrics['reconciliation_unaccounted_kg']);
        $this->assertNull($metrics['unaccounted_band']);
        $this->assertFalse($metrics['blocks_approval']);

        $variance = app(ShiftProductionEntryService::class)->consumptionVariance($entry);
        $this->assertNull($variance['norm_source']);
        $this->assertNull($variance['expected_kg']);
        $this->assertNull($variance['unaccounted_kg']);
    }
}
