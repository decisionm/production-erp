<?php

namespace Tests\Feature\Production;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\BatchEstimationService;
use App\Modules\Production\Services\PackQuantityResolver;
use App\Modules\Production\Services\ShiftProductionEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5 P5-04: ONE reader of pack quantities.
 *
 * Start Batch froze nos_per_box / nos_per_tray / nos_per_pouch /
 * pouches_per_box / trays_per_box from packaging ?? item into the run's
 * snapshot, but the metric reader read only `entry->nos_per_box ??
 * item->nos_per_box` — the run's packaging row was never consulted, and
 * tray/pouch counts never at all (§4.14, confirmed live). So a batch
 * tray-packed at 490/box under a product whose master says 520/box was
 * measured against 520, and a pouch-packed run of a product with no pouch
 * count on the master had no expected pouches.
 *
 * PackQuantityResolver is the one place the precedence lives now:
 * frozen entry columns → the run's packaging row → the item master, each
 * figure saying where it came from.
 */
class PackQuantityResolverTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private ProductionStandard $standard;

    private ProductionStandardPackaging $trayPacking;

    private ProductionStandardPackaging $pouchPacking;

    protected function setUp(): void
    {
        parent::setUp();

        // The item master carries the LAST-fallback counts. Deliberately
        // different from every packaging figure so a wrong rung is visible.
        $this->bottle = Item::create([
            'sku' => 'BTL-200RA', 'name' => 'B.200 Ml Round Pet Bottle Amber 18gms',
            'uom' => 'NOS', 'is_active' => true,
            'nos_per_box' => 520, 'nos_per_tray' => 104, 'trays_per_box' => 5,
            'nos_per_pouch' => null, 'pouches_per_box' => null,
            'standard_cycle_time' => '12.00', 'standard_cavities' => 8,
        ]);

        $this->standard = ProductionStandard::create([
            'source_product_name' => '200ML RA', 'item_id' => $this->bottle->id,
            'cavities' => 8, 'unit_weight_grams' => 18, 'cycle_time' => 12, 'status' => 'approved',
        ]);
        $this->trayPacking = $this->standard->packagings()->create([
            'mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5, 'nos_per_box' => 490,
        ]);
        $this->pouchPacking = $this->standard->packagings()->create([
            'mode' => 'pouch', 'nos_per_pouch' => 130, 'pouches_per_box' => 4, 'nos_per_box' => 520,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function entry(?ProductionStandardPackaging $packaging, array $overrides = []): ShiftProductionEntry
    {
        $shift = Shift::firstOrCreate(['name' => 'Morning'], ['start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::firstOrCreate(['code' => 'MC-01'], ['name' => 'Machine 1']);
        $store = Warehouse::firstOrCreate(['code' => 'WH-FG'], ['name' => 'FG Store']);

        return ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $store->id,
            'production_date' => '2026-08-17',
            'batch_number' => '20260817-M01-'.str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT),
            'batch_status' => BatchStatus::InProgress,
            'quantity_produced' => null,
            'quantity_scrap' => '0',
            'production_standard_id' => $this->standard->id,
            'production_standard_packaging_id' => $packaging?->id,
            'packaging_mode' => $packaging?->mode,
            'standard_cycle_time' => '12.00',
            'standard_cavities' => 8,
            'active_cavities' => 8,
            ...$overrides,
        ]);
    }

    // ------------------------------------------------------------ rungs ---

    public function test_frozen_entry_columns_win_over_the_packaging_row(): void
    {
        $entry = $this->entry($this->trayPacking, [
            'nos_per_box' => 480, 'nos_per_tray' => 96, 'nos_per_pouch' => 120,
        ]);

        $pack = app(PackQuantityResolver::class)->forEntry($entry);

        $this->assertSame(480, $pack->nos_per_box);
        $this->assertSame(96, $pack->nos_per_tray);
        $this->assertSame(120, $pack->nos_per_pouch);
        $this->assertSame('entry', $pack->source);
        $this->assertSame('entry', $pack->sources['nos_per_box']);
        $this->assertSame('entry', $pack->sources['nos_per_tray']);
        // The entry has no column for these two, so the run's packaging row
        // answers them — and says so.
        $this->assertSame(5, $pack->trays_per_box);
        $this->assertSame('packaging', $pack->sources['trays_per_box']);
    }

    public function test_the_packaging_row_answers_before_the_item_master(): void
    {
        $entry = $this->entry($this->trayPacking);

        $pack = app(PackQuantityResolver::class)->forEntry($entry);

        $this->assertSame(490, $pack->nos_per_box, 'the run was tray-packed at 490, whatever the master says');
        $this->assertSame(98, $pack->nos_per_tray);
        $this->assertSame(5, $pack->trays_per_box);
        $this->assertSame('packaging', $pack->source);
        // A tray packaging states no pouch counts; the item master has none
        // either — null, never a figure borrowed from the pouch sibling.
        $this->assertNull($pack->nos_per_pouch);
        $this->assertNull($pack->pouches_per_box);
        $this->assertSame('none', $pack->sources['nos_per_pouch']);
    }

    public function test_the_item_master_is_the_last_fallback(): void
    {
        $entry = $this->entry(null);

        $pack = app(PackQuantityResolver::class)->forEntry($entry);

        $this->assertSame(520, $pack->nos_per_box);
        $this->assertSame(104, $pack->nos_per_tray);
        $this->assertSame(5, $pack->trays_per_box);
        $this->assertNull($pack->nos_per_pouch);
        $this->assertSame('item', $pack->source);
        $this->assertSame('item', $pack->sources['nos_per_box']);
    }

    public function test_a_packaging_gap_falls_through_to_the_item_master_per_figure(): void
    {
        // The pouch packaging says nothing about trays; the master does.
        // Each figure resolves on its own — the box count comes from the
        // packaging, the tray count from the master, and each is labelled.
        $entry = $this->entry($this->pouchPacking);

        $pack = app(PackQuantityResolver::class)->forEntry($entry);

        $this->assertSame(520, $pack->nos_per_box);
        $this->assertSame('packaging', $pack->sources['nos_per_box']);
        $this->assertSame(130, $pack->nos_per_pouch);
        $this->assertSame(4, $pack->pouches_per_box);
        $this->assertSame(104, $pack->nos_per_tray);
        $this->assertSame('item', $pack->sources['nos_per_tray']);
        $this->assertSame('packaging', $pack->source);
    }

    public function test_nothing_anywhere_is_none_not_zero(): void
    {
        $this->bottle->update([
            'nos_per_box' => null, 'nos_per_tray' => null, 'trays_per_box' => null,
        ]);
        $entry = $this->entry(null);

        $pack = app(PackQuantityResolver::class)->forEntry($entry);

        $this->assertNull($pack->nos_per_box);
        $this->assertNull($pack->nos_per_tray);
        $this->assertSame('none', $pack->source);
        $this->assertSame([
            'nos_per_box' => null, 'nos_per_tray' => null, 'trays_per_box' => null,
            'nos_per_pouch' => null, 'pouches_per_box' => null,
        ], array_intersect_key($pack->toArray(), array_flip(['nos_per_box', 'nos_per_tray', 'trays_per_box', 'nos_per_pouch', 'pouches_per_box'])));
    }

    public function test_an_unsaved_entry_with_only_an_item_relation_still_resolves(): void
    {
        // productionMetrics() is called on bare models in tests and previews
        // (ExpectedOutputDivergenceTest builds one with setRelation) — the
        // resolver must not need a database row to answer.
        $entry = new ShiftProductionEntry(['standard_cycle_time' => '12', 'active_cavities' => 8]);
        $entry->setRelation('item', $this->bottle);

        $pack = app(PackQuantityResolver::class)->forEntry($entry);

        $this->assertSame(520, $pack->nos_per_box);
        $this->assertSame('item', $pack->source);
    }

    // ------------------------------------- the metric reader, routed ---

    public function test_red_before_the_metric_reader_took_box_and_pouch_counts_from_the_run_packaging(): void
    {
        // A pouch-packed run: the master has no pouch count, the packaging
        // has 130/pouch. Completed with hours so expected pieces exist.
        // Before P5-04 expected_pouches was NULL (the reader read
        // entry->nos_per_pouch ?? item->nos_per_pouch and never the
        // packaging), and expected_boxes was measured against the MASTER's
        // 520 even for the 490 tray run below.
        $pouchRun = $this->entry($this->pouchPacking, [
            'batch_status' => BatchStatus::Completed,
            'quantity_produced' => '2400', 'running_hours' => '1.00',
        ]);

        $metrics = app(ShiftProductionEntryService::class)->productionMetrics($pouchRun->fresh());

        // 3600 / 12 × 8 × 1 h = 2400 pieces; 2400 / 130 per pouch = 18.46 → 19 (ceil)
        $this->assertSame('2400.00', $metrics['expected_pieces']);
        $this->assertSame(19, $metrics['expected_pouches'], 'expected pouches must come from the run\'s pouch packaging');

        $trayRun = $this->entry($this->trayPacking, [
            'batch_status' => BatchStatus::Completed,
            'quantity_produced' => '2400', 'running_hours' => '1.00',
        ]);

        $metrics = app(ShiftProductionEntryService::class)->productionMetrics($trayRun->fresh());

        // 2400 / 490 per box = 4.90 → 5 boxes at the packaging's 490, where
        // the master's 520 would have said 2400 / 520 = 4.62 → 5 too, so the
        // figure that proves the rung is the pack size the metrics report.
        $this->assertSame(490, $metrics['pack_quantities']['nos_per_box']);
        $this->assertSame('packaging', $metrics['pack_quantities']['source']);
        $this->assertSame(5, $metrics['expected_boxes']);
    }

    public function test_the_metric_reader_still_prefers_what_the_supervisor_typed(): void
    {
        // Byte-for-byte the pre-P5-04 rule for the rung it already had: the
        // entry's own pack size wins over anything else (a run packed at a
        // non-standard count must not be measured against the master's).
        $run = $this->entry($this->trayPacking, [
            'batch_status' => BatchStatus::Completed,
            'quantity_produced' => '2400', 'running_hours' => '1.00',
            'nos_per_box' => 400,
        ]);

        $metrics = app(ShiftProductionEntryService::class)->productionMetrics($run->fresh());

        // 2400 / 400 = 6 exactly.
        $this->assertSame(6, $metrics['expected_boxes']);
        $this->assertSame(400, $metrics['pack_quantities']['nos_per_box']);
        $this->assertSame('entry', $metrics['pack_quantities']['source']);
        // Beside it, the figures the entry did NOT type still come from the
        // run's packaging — and say so.
        $this->assertSame(98, $metrics['pack_quantities']['nos_per_tray']);
        $this->assertSame('packaging', $metrics['pack_quantities']['sources']['nos_per_tray']);
    }

    // ---------------------------------------- the estimation, routed ---

    public function test_the_start_batch_estimate_reads_the_same_precedence(): void
    {
        $estimation = app(BatchEstimationService::class);

        $withTray = $estimation->estimate($this->bottle, null, '1', 8, $this->standard, $this->trayPacking);
        $this->assertSame(490, $withTray['nos_per_box']);
        $this->assertSame(98, $withTray['nos_per_tray']);
        // The tray packaging states no pouch count; the master has none.
        $this->assertNull($withTray['nos_per_pouch']);
        $this->assertSame('packaging', $withTray['pack_quantity_source']);

        $withPouch = $estimation->estimate($this->bottle, null, '1', 8, $this->standard, $this->pouchPacking);
        $this->assertSame(520, $withPouch['nos_per_box']);
        $this->assertSame(130, $withPouch['nos_per_pouch']);
        // Same per-figure fall-through as the entry reader: tray count from
        // the master when the pouch packaging has none.
        $this->assertSame(104, $withPouch['nos_per_tray']);

        $bare = $estimation->estimate($this->bottle, null, '1', 8, $this->standard, null);
        $this->assertSame(520, $bare['nos_per_box']);
        $this->assertSame(104, $bare['nos_per_tray']);
        $this->assertSame('item', $bare['pack_quantity_source']);
    }
}
