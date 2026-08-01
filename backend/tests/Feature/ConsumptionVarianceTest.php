<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ShiftProductionEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Consumption variance — expected material use per norm (active BOM first,
 * else the item's nominal weight) vs what the supervisor actually entered.
 * The whole "variance" key is null until the batch completes.
 */
class ConsumptionVarianceTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $rmStore;

    private Item $resin;

    private function completedEntry(array $itemAttributes = [], array $entryAttributes = []): ShiftProductionEntry
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
        $warehouse = Warehouse::create(['code' => 'WH-FG', 'name' => 'Finished Goods']);
        $this->rmStore = Warehouse::create(['code' => 'WH-RM', 'name' => 'RM Store']);
        $item = Item::create($itemAttributes + ['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'pcs']);
        $this->resin = Item::create(['sku' => 'PET-RESIN', 'name' => 'PET Resin', 'uom' => 'kg']);

        return ShiftProductionEntry::create($entryAttributes + [
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => '2026-07-25',
            'batch_status' => BatchStatus::Completed,
            'quantity_produced' => '1000',
            'quantity_scrap' => '0',
            'status' => ShiftProductionEntryStatus::Pending,
        ]);
    }

    public function test_item_weight_norm_happy_path_appears_on_the_approval_list(): void
    {
        // 100 production-rejected pieces at 20 g each ARE the 2 kg rejection
        // figure — the fixture used to carry the kg with no pieces behind
        // it, which no longer reads as a coherent shift now that the norm
        // counts those pieces as resin that went through the machine.
        $entry = $this->completedEntry(
            itemAttributes: ['nominal_weight_grams' => '20.0000'],
            entryAttributes: ['quantity_scrap' => '100', 'quantity_rejection_kg' => '2'],
        );
        $entry->materialConsumptions()->create([
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->rmStore->id,
            'quantity_issued_kg' => '25',
        ]);
        $entry->scraps()->create(['type' => 'lumps', 'quantity_kg' => '1.5']);

        $viewer = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        $viewer->givePermissionTo('production.view');
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/production/shift-production-entries')
            ->assertOk()
            ->assertJsonPath('data.0.variance', [
                'norm_source' => 'item_weight',
                'norm_basis' => 'throughput at standard weight + lumps',
                // (1000 packed + 100 rejected) × 20 g = 22 kg, + 1.5 kg of
                // lumps. It used to read 20.0000 — netting the norm to the
                // approved 1000 pieces charged the shift for the 100 pieces
                // it moulded and the lumps it made, both of which really
                // came out of the same resin.
                'expected_kg' => '23.5000',
                'actual_kg' => '25.0000',
                'variance_kg' => '1.5000',
                'variance_pct' => 6.4,
                'variance_band' => 'investigate',
                'rejection_kg' => '2.0000',
                'scrap_kg' => '1.5000',
                'unaccounted_kg' => '1.5000',
            ]);
    }

    public function test_no_weight_and_no_bom_reports_actuals_with_null_norm(): void
    {
        $entry = $this->completedEntry();
        $entry->materialConsumptions()->create([
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->rmStore->id,
            'quantity_issued_kg' => '10',
        ]);
        $entry->scraps()->create(['type' => 'lumps', 'quantity_kg' => '0.75']);

        $variance = app(ShiftProductionEntryService::class)->consumptionVariance($entry);

        $this->assertSame([
            'norm_source' => null,
            'norm_basis' => null,
            'expected_kg' => null,
            'actual_kg' => '10.0000',
            'variance_kg' => null,
            'variance_pct' => null,
            'variance_band' => null,
            'rejection_kg' => '0',
            'scrap_kg' => '0.7500',
            'unaccounted_kg' => null,
        ], $variance);
    }

    public function test_active_bom_overrides_item_weight_and_excludes_non_kg_components(): void
    {
        $entry = $this->completedEntry(itemAttributes: ['nominal_weight_grams' => '20.0000']);
        $masterbatch = Item::create(['sku' => 'MB-BLUE', 'name' => 'Masterbatch Blue', 'uom' => 'KGS']);
        $cap = Item::create(['sku' => 'CAP-28MM', 'name' => '28mm Cap', 'uom' => 'pcs']);

        // A superseded inactive version must not participate.
        $old = Bom::create(['item_id' => $entry->item_id, 'name' => 'Bottle BOM', 'version' => '1', 'is_active' => false]);
        $old->lines()->create(['component_item_id' => $this->resin->id, 'quantity_per' => '0.9000']);

        $bom = Bom::create(['item_id' => $entry->item_id, 'name' => 'Bottle BOM', 'version' => '2', 'is_active' => true]);
        $bom->lines()->create(['component_item_id' => $this->resin->id, 'quantity_per' => '0.0250']);
        $bom->lines()->create(['component_item_id' => $masterbatch->id, 'quantity_per' => '0.0010']);
        // Counted in Nos — must NOT be summed into kg.
        $bom->lines()->create(['component_item_id' => $cap->id, 'quantity_per' => '1.0000']);

        $variance = app(ShiftProductionEntryService::class)->consumptionVariance($entry);

        $this->assertSame('bom', $variance['norm_source']);
        // 1000 x (0.0250 + 0.0010) — not 20 kg from the item weight, and not
        // 1026 kg from also counting the cap line.
        $this->assertSame('26.0000', $variance['expected_kg']);
    }

    public function test_soft_deleted_component_still_counts_toward_the_bom_norm(): void
    {
        $entry = $this->completedEntry(itemAttributes: ['nominal_weight_grams' => '20.0000']);

        $bom = Bom::create(['item_id' => $entry->item_id, 'name' => 'Bottle BOM', 'version' => '1', 'is_active' => true]);
        $bom->lines()->create(['component_item_id' => $this->resin->id, 'quantity_per' => '0.0250']);

        // A master cleanup trashing the resin item must not zero the norm.
        $this->resin->delete();

        $variance = app(ShiftProductionEntryService::class)->consumptionVariance($entry);

        $this->assertSame('bom', $variance['norm_source']);
        $this->assertSame('25.0000', $variance['expected_kg']);
    }

    public function test_tally_kgs_trailing_dot_uom_still_counts_toward_the_bom_norm(): void
    {
        // Regression for the exception-report code fix (PR #33): Tally
        // masters write "Kgs." with a trailing dot — 90+ live items carry
        // it. The kg-family matcher must accept it, or those BOM lines
        // silently drop out of the norm and the variance lies.
        $entry = $this->completedEntry(itemAttributes: ['nominal_weight_grams' => '20.0000']);
        $tallyResin = Item::create(['sku' => 'BILLION-PET', 'name' => 'Billion Pet Resin IV-0.8', 'uom' => 'Kgs.']);

        $bom = Bom::create(['item_id' => $entry->item_id, 'name' => 'Bottle BOM', 'version' => '1', 'is_active' => true]);
        $bom->lines()->create(['component_item_id' => $tallyResin->id, 'quantity_per' => '0.0250']);

        $variance = app(ShiftProductionEntryService::class)->consumptionVariance($entry);

        // 1000 × 0.0250 from the "Kgs." line — not the 20 kg item-weight
        // fallback a dropped line would produce.
        $this->assertSame('bom', $variance['norm_source']);
        $this->assertSame('25.0000', $variance['expected_kg']);
    }

    public function test_bom_with_only_nos_lines_falls_back_to_the_item_weight(): void
    {
        $entry = $this->completedEntry(itemAttributes: ['nominal_weight_grams' => '20.0000']);
        $cap = Item::create(['sku' => 'CAP-28MM', 'name' => '28mm Cap', 'uom' => 'pcs']);

        // Packaging-only BOM provides no mass norm — expected must come from
        // the product weight, not report a 0 kg BOM norm.
        $bom = Bom::create(['item_id' => $entry->item_id, 'name' => 'Pack BOM', 'version' => '1', 'is_active' => true]);
        $bom->lines()->create(['component_item_id' => $cap->id, 'quantity_per' => '1.0000']);

        $variance = app(ShiftProductionEntryService::class)->consumptionVariance($entry);

        $this->assertSame('item_weight', $variance['norm_source']);
        $this->assertSame('20.0000', $variance['expected_kg']);
    }

    public function test_variance_is_null_for_a_batch_that_has_not_completed(): void
    {
        $entry = $this->completedEntry(entryAttributes: [
            'batch_status' => BatchStatus::InProgress,
            'quantity_produced' => null,
        ]);

        $this->assertNull(app(ShiftProductionEntryService::class)->consumptionVariance($entry));
    }

    public function test_a_nos_unit_consumption_line_is_excluded_from_the_kg_actual(): void
    {
        // The expected/BOM side has always filtered to the kg family; the
        // actual side did not. The "Other materials (exceptions)" repeater
        // accepts ANY item and labels the input with that item's own UOM, so
        // a supervisor entering 500 cartons wrote 500 into a column named
        // quantity_issued_kg — and 500 kg into the variance. Stock stays
        // correct (the issue is in the item's own unit); only the kg
        // roll-ups were wrong.
        $entry = $this->completedEntry(itemAttributes: ['nominal_weight_grams' => '20.0000']);
        $carton = Item::create(['sku' => 'CTN-24', 'name' => 'Corrugated Carton 24', 'uom' => 'Nos.']);

        $entry->materialConsumptions()->create([
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->rmStore->id,
            'quantity_issued_kg' => '25',
        ]);
        $entry->materialConsumptions()->create([
            'item_id' => $carton->id,
            'warehouse_id' => $this->rmStore->id,
            'quantity_issued_kg' => '500',
        ]);

        $variance = app(ShiftProductionEntryService::class)->consumptionVariance($entry);

        // 25 kg of resin — not 525.
        $this->assertSame('25.0000', $variance['actual_kg']);
        $this->assertSame('5.0000', $variance['variance_kg']);
        $this->assertSame(25.0, $variance['variance_pct']);
    }

    /**
     * The same mass filter, pinned on the RECONCILIATION block — issued_kg
     * and reconciliation_unaccounted_kg, the pair the completion drawer
     * shows before submit and the approval drawer shows after.
     *
     * This is the arithmetic the frontend's pre-submit "Material issued" /
     * "Unaccounted" memo mirrors. That memo was summing every consumption
     * line's quantity into one kilogram figure, so a shift that issued 25 kg
     * of resin and 500 cartons previewed 525.5 kg issued and 502.0 kg
     * unaccounted — a fabricated half-tonne loss on screen, against a server
     * that had already filtered the cartons out and would file 2.0. Pinning
     * both numbers here gives the screen one contract to agree with.
     */
    public function test_a_nos_unit_line_is_excluded_from_issued_kg_and_the_unaccounted_figure(): void
    {
        $entry = $this->completedEntry(
            itemAttributes: ['nominal_weight_grams' => '20.0000'],
            entryAttributes: ['quantity_produced_kg' => '20.0000', 'quantity_rejection_kg' => '2'],
        );
        // "Kgs." with the trailing dot is how Tally's masters spell it on the
        // live books — it must still count as kg.
        $masterbatch = Item::create(['sku' => 'MB-BLUE', 'name' => 'Masterbatch Blue', 'uom' => 'Kgs.']);
        $carton = Item::create(['sku' => 'CTN-24', 'name' => 'Corrugated Carton 24', 'uom' => 'Nos.']);

        foreach ([[$this->resin, '25'], [$masterbatch, '0.5'], [$carton, '500']] as [$item, $quantity]) {
            $entry->materialConsumptions()->create([
                'item_id' => $item->id,
                'warehouse_id' => $this->rmStore->id,
                'quantity_issued_kg' => $quantity,
            ]);
        }
        $entry->scraps()->create(['type' => 'lumps', 'quantity_kg' => '1.5']);

        $metrics = app(ShiftProductionEntryService::class)->productionMetrics($entry);

        // 25 + 0.5 — the 500 cartons are issued and stocked in their own
        // unit, they are simply not kilograms.
        $this->assertSame('25.5000', $metrics['issued_kg']);
        // 25.5 − 20 good − 2 rejection − 1.5 lumps = 2.0. Summing the
        // cartons would read 502.0000.
        $this->assertSame('2.0000', $metrics['reconciliation_unaccounted_kg']);
    }

    public function test_a_soft_deleted_consumption_master_still_counts_toward_the_kg_actual(): void
    {
        // Mirrors the BOM-side rule: a master cleanup trashing the resin
        // item must not silently drop kg already issued against it.
        $entry = $this->completedEntry(itemAttributes: ['nominal_weight_grams' => '20.0000']);
        $entry->materialConsumptions()->create([
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->rmStore->id,
            'quantity_issued_kg' => '25',
        ]);

        $this->resin->delete();

        $variance = app(ShiftProductionEntryService::class)->consumptionVariance($entry->fresh());

        $this->assertSame('25.0000', $variance['actual_kg']);
    }

    public function test_a_consumption_line_whose_master_has_no_uom_counts_as_kg(): void
    {
        // Fail-safe direction: an unknown/blank UOM keeps its historical
        // behaviour (counted as kg) rather than silently vanishing from the
        // reconciliation. Dropping a real resin line would be the worse bug.
        $entry = $this->completedEntry(itemAttributes: ['nominal_weight_grams' => '20.0000']);
        $unknown = Item::create(['sku' => 'MYSTERY', 'name' => 'Unlabelled material', 'uom' => '']);

        $entry->materialConsumptions()->create([
            'item_id' => $unknown->id,
            'warehouse_id' => $this->rmStore->id,
            'quantity_issued_kg' => '7',
        ]);

        $variance = app(ShiftProductionEntryService::class)->consumptionVariance($entry);

        $this->assertSame('7.0000', $variance['actual_kg']);
    }

    // =================================================================
    // THE NORM IS THROUGHPUT, NOT APPROVED OUTPUT.
    //
    // One shift, told twice — once after its quality check and once before
    // it. 2,000 pieces packed and sent to QC, of which QC refused 150; 250
    // more rejected on the production floor; 3 kg of lumps. At 20 g a piece
    // the machine therefore ran (1,850 + 150 + 250) × 20 g = 45 kg of resin
    // into pieces and 3 kg into lumps, and the store issued exactly 48 kg.
    //
    // That shift is formula-perfect and must read 0.0000. Under the old
    // net-only norm it read 48 − 37 = +11 kg, and the approver was sent to
    // investigate resin that had never gone missing.
    // =================================================================

    /**
     * The checked shift: gross packed count split into approved FG and QC
     * rejects, with production rejects and lumps beside them.
     *
     * KNOWN DISAGREEMENT ONE CARD DOWN, deliberately not pinned here.
     * productionMetrics() confirms rejection as `qc_rejection_kg ?? production`
     * — written when the two were rival MEASUREMENTS of the same rejects, and
     * now wrong, because since the quality gate they are different
     * POPULATIONS. On this batch it keeps QC's 3 kg and drops the 5 kg of
     * floor rejects, so reconciliation_unaccounted_kg reads 5.0000
     * 'investigate' (verified) on the same shift this card calls 0.0000 'ok'.
     * That is a productionMetrics fix, outside the norm; asserting the 5 here
     * would cement a figure believed wrong.
     */
    private function formulaPerfectCheckedEntry(string $issuedKg): ShiftProductionEntry
    {
        $entry = $this->completedEntry(
            itemAttributes: ['nominal_weight_grams' => '20.0000'],
            entryAttributes: [
                // The quality check netted 150 pieces off the packed 2,000
                // and filed the count it removed — it moves approved FG, and
                // it must not move a single gram of the resin norm.
                'quantity_produced' => '1850',
                'gross_quantity_produced' => '2000',
                'quality_rejected_nos' => 150,
                'quantity_produced_kg' => '37.0000',
                'qc_rejection_kg' => '3.0000',
                // Rejected at the machine, never packed.
                'quantity_scrap' => '250',
                'quantity_rejection_kg' => '5.0000',
            ],
        );
        $entry->scraps()->create(['type' => 'lumps', 'quantity_kg' => '3']);
        $entry->materialConsumptions()->create([
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->rmStore->id,
            'quantity_issued_kg' => $issuedKg,
        ]);

        return $entry;
    }

    public function test_a_formula_perfect_batch_with_rejects_and_lumps_has_zero_variance(): void
    {
        $variance = app(ShiftProductionEntryService::class)
            ->consumptionVariance($this->formulaPerfectCheckedEntry('48'));

        $this->assertSame('item_weight', $variance['norm_source']);
        $this->assertSame('throughput at standard weight + lumps', $variance['norm_basis']);
        // 2,250 pieces × 20 g = 45 kg + 3 kg lumps.
        $this->assertSame('48.0000', $variance['expected_kg']);
        $this->assertSame('48.0000', $variance['actual_kg']);
        $this->assertSame('0.0000', $variance['variance_kg']);
        $this->assertSame(0.0, $variance['variance_pct']);
        // Not 'watch'. The supervisor followed the formula, and the screen
        // must say so — that is the whole defect this replaces.
        $this->assertSame('ok', $variance['variance_band']);
        $this->assertSame('0.0000', $variance['unaccounted_kg']);
    }

    public function test_a_hand_override_that_really_added_two_kilograms_is_still_flagged(): void
    {
        // The same shift with 50 kg typed against it. Two kilograms of resin
        // genuinely left the store beyond the formula, and widening the norm
        // must not have blunted the bands: this is the case they exist for.
        $variance = app(ShiftProductionEntryService::class)
            ->consumptionVariance($this->formulaPerfectCheckedEntry('50'));

        $this->assertSame('48.0000', $variance['expected_kg']);
        $this->assertSame('2.0000', $variance['variance_kg']);
        // 2 / 48 = 4.17%.
        $this->assertSame(4.2, $variance['variance_pct']);
        $this->assertSame('watch', $variance['variance_band']);
        $this->assertNotSame('ok', $variance['variance_band']);
    }

    public function test_an_unchecked_batch_norms_on_the_packed_count_and_still_zeroes(): void
    {
        // The SAME shift an hour earlier, before QC touched it: no check, so
        // quality_rejected_nos is null and quantity_produced still IS the
        // 2,000 packed pieces. The norm must land on the identical 48 kg —
        // the resin was in the machine long before anyone certified it.
        $entry = $this->completedEntry(
            itemAttributes: ['nominal_weight_grams' => '20.0000'],
            entryAttributes: [
                'quantity_produced' => '2000',
                'quantity_produced_kg' => '40.0000',
                'quantity_scrap' => '250',
                'quantity_rejection_kg' => '5.0000',
            ],
        );
        $entry->scraps()->create(['type' => 'lumps', 'quantity_kg' => '3']);
        $entry->materialConsumptions()->create([
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->rmStore->id,
            'quantity_issued_kg' => '48',
        ]);

        $this->assertNull($entry->quality_rejected_nos);
        $this->assertNull($entry->gross_quantity_produced);

        $variance = app(ShiftProductionEntryService::class)->consumptionVariance($entry);

        $this->assertSame('48.0000', $variance['expected_kg']);
        $this->assertSame('0.0000', $variance['variance_kg']);
        $this->assertSame('ok', $variance['variance_band']);
    }
}
