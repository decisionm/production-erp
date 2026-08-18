<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 5.5 fix loop (P5.5-06): the Start Batch preview names the RUN's
 * configuration gaps, not the standard's union.
 *
 * RED BEFORE: the modal read the chosen variant's `configuration_status`,
 * which is standardStatus() — the union over EVERY packaging plus the
 * product's identity — so a tray run on a complete tray row warned about the
 * sibling pouch's missing counts, and after Start the entry's
 * `configuration_gaps` (runStatus: the ONE packaging the run froze) said
 * complete. The modal and Completed Today contradicted each other about one
 * run.
 *
 * Now the preview carries an additive top-level `configuration_status` =
 * ProductVariantService::runStatus(resolved standard, resolved packaging,
 * item) — the same rule startBatch freezes — with `grain` naming whose
 * verdict it is: 'run' once a packaging is resolved, 'standard' while the
 * packaging is still to be chosen (the union), and null while the STANDARD
 * is still to be chosen. The per-variant statuses are unchanged.
 */
class BatchPreviewRunStatusTest extends TestCase
{
    use RefreshDatabase;

    private WorkCenter $machine;

    private Warehouse $warehouse;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->machine = WorkCenter::create(['name' => 'Machine 1', 'code' => 'MC-01', 'is_active' => true]);
        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $this->warehouse = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg']);

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        Permission::findOrCreate('production.manage', 'web');
        $user->givePermissionTo(['production.view', 'production.manage']);
        $this->actingAs($user);
    }

    private function item(string $sku, array $overrides = []): Item
    {
        return Item::create([
            'sku' => $sku, 'name' => 'Bottle '.$sku, 'uom' => 'NOS', 'is_active' => true,
            'standard_cycle_time' => '12.30', 'standard_cavities' => 5,
            'nominal_weight_grams' => '12.9000', 'nos_per_box' => 1150,
            'tally_stock_item_guid' => 'itm-'.$sku,
            ...$overrides,
        ]);
    }

    /** @param  array<int, array<string, mixed>>  $packagings */
    private function standardFor(Item $item, array $packagings, string $name = 'Bottle'): ProductionStandard
    {
        $standard = ProductionStandard::create([
            'item_id' => $item->id, 'source_product_name' => $name,
            'cavities' => 5, 'unit_weight_grams' => '12.9000', 'cycle_time' => '12.30',
            'status' => 'approved', 'source' => 'preview-run-status-test',
        ]);
        foreach ($packagings as $packaging) {
            $standard->packagings()->create($packaging);
        }

        return $standard->fresh('packagings');
    }

    private function preview(Item $item, array $query = []): array
    {
        return $this->getJson('/api/v1/production/shift-production-entries/preview?'.http_build_query([
            'item_id' => $item->id,
            'work_center_id' => $this->machine->id,
            'warehouse_id' => $this->warehouse->id,
            'shift_id' => $this->shift->id,
            ...$query,
        ]))->assertOk()->json('data');
    }

    /** A complete tray row and a pouch row still missing its per-pouch count. */
    private function twoPackagingSku(): array
    {
        $item = $this->item('BTL-TWIN');
        $standard = $this->standardFor($item, [
            ['mode' => ProductionStandardPackaging::MODE_TRAY, 'nos_per_tray' => 230, 'trays_per_box' => 5, 'nos_per_box' => 1150],
            ['mode' => ProductionStandardPackaging::MODE_POUCH, 'pouches_per_box' => 5, 'nos_per_box' => 1225],
        ]);

        return [$item, $standard, $standard->packagings->firstWhere('mode', 'tray'), $standard->packagings->firstWhere('mode', 'pouch')];
    }

    public function test_a_run_on_the_complete_packaging_names_no_gap_while_the_standards_union_still_does(): void
    {
        [$item, $standard, $tray] = $this->twoPackagingSku();

        $preview = $this->preview($item, ['production_standard_packaging_id' => $tray->id]);

        // The run's verdict: this packaging, complete.
        $this->assertSame($tray->id, $preview['packaging']['id']);
        $this->assertSame(['state' => 'complete', 'missing' => [], 'grain' => 'run'], $preview['configuration_status']);

        // The standard's union — unchanged, and rightly incomplete for the
        // standard: its pouch row is half-stated.
        $this->assertSame($standard->id, $preview['variants'][0]['id']);
        $this->assertSame('incomplete', $preview['variants'][0]['configuration_status']['state']);
        $this->assertSame(['counts'], $preview['variants'][0]['configuration_status']['missing']);
    }

    public function test_the_only_complete_packaging_resolves_by_itself_and_the_run_is_judged_on_it(): void
    {
        [$item, , $tray] = $this->twoPackagingSku();

        // No packaging named: the resolver picks the one complete row, and
        // the verdict is that row's — the same run startBatch would freeze.
        $preview = $this->preview($item);

        $this->assertSame($tray->id, $preview['packaging']['id']);
        $this->assertSame(['state' => 'complete', 'missing' => [], 'grain' => 'run'], $preview['configuration_status']);
    }

    public function test_an_unresolvable_packaging_choice_falls_back_to_the_standards_verdict_and_says_so(): void
    {
        [$item, , , $pouch] = $this->twoPackagingSku();

        // Asking for the incomplete pouch row: the resolver refuses it (a
        // half-stated row is never a run's packaging), nothing is resolved,
        // and the verdict is the standard's union, labelled as such — the
        // words the union carries are the pouch row's.
        $preview = $this->preview($item, ['production_standard_packaging_id' => $pouch->id]);

        $this->assertNull($preview['packaging']);
        $this->assertSame(['state' => 'incomplete', 'missing' => ['counts'], 'grain' => 'standard'], $preview['configuration_status']);
    }

    public function test_two_complete_packagings_with_no_default_leave_the_choice_open_at_standard_grain(): void
    {
        $item = $this->item('BTL-CHOICE');
        $this->standardFor($item, [
            ['mode' => ProductionStandardPackaging::MODE_TRAY, 'nos_per_tray' => 230, 'trays_per_box' => 5, 'nos_per_box' => 1150],
            ['mode' => ProductionStandardPackaging::MODE_TRAY, 'nos_per_tray' => 245, 'trays_per_box' => 5, 'nos_per_box' => 1225],
        ]);

        $preview = $this->preview($item);

        $this->assertNull($preview['packaging'], 'a real choice is open');
        $this->assertSame(['state' => 'complete', 'missing' => [], 'grain' => 'standard'], $preview['configuration_status']);
    }

    public function test_the_runs_verdict_is_the_one_the_entry_freezes_at_start(): void
    {
        // The whole point: the modal's words and the batch's are one set.
        [$item, $standard, $tray] = $this->twoPackagingSku();
        $item->forceFill(['tally_stock_item_guid' => null])->save();

        $preview = $this->preview($item, ['production_standard_packaging_id' => $tray->id]);
        $this->assertSame(['tally_identity'], $preview['configuration_status']['missing']);
        $this->assertSame('run', $preview['configuration_status']['grain']);
        // …where the standard's union says two things.
        $this->assertSame(['counts', 'tally_identity'], $preview['variants'][0]['configuration_status']['missing']);

        $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $this->warehouse->id,
            'production_date' => '2026-08-17',
            'production_standard_id' => $standard->id,
            'production_standard_packaging_id' => $tray->id,
        ])->assertOk()
            ->assertJsonPath('data.configuration_gaps.source', 'snapshot')
            ->assertJsonPath('data.configuration_gaps.missing', ['tally_identity']);
    }

    public function test_while_the_standard_is_still_to_be_chosen_no_verdict_is_given(): void
    {
        $item = $this->item('BTL-TWO-STD');
        $this->standardFor($item, [
            ['mode' => ProductionStandardPackaging::MODE_TRAY, 'nos_per_tray' => 230, 'trays_per_box' => 5, 'nos_per_box' => 1150],
        ], 'Bottle 5-cav');
        $second = $this->standardFor($item, [
            ['mode' => ProductionStandardPackaging::MODE_POUCH, 'pouches_per_box' => 5, 'nos_per_box' => 1225],
        ], 'Bottle 4-cav');
        $second->forceFill(['cavities' => 4])->save();

        $open = $this->preview($item);
        $this->assertCount(2, $open['variants']);
        $this->assertNull($open['standard']);
        $this->assertNull($open['configuration_status'], 'the standard question comes first; no gap is named for a standard this run may not use');

        // Chosen: judged, and the second standard's own words appear.
        $chosen = $this->preview($item, ['production_standard_id' => $second->id]);
        $this->assertSame($second->id, $chosen['standard']['id']);
        $this->assertSame(['state' => 'incomplete', 'missing' => ['counts'], 'grain' => 'standard'], $chosen['configuration_status']);
    }

    public function test_a_product_with_no_standard_at_all_is_told_so_as_the_entry_will_be(): void
    {
        $bare = $this->item('BTL-BARE', ['tally_stock_item_guid' => null]);

        $preview = $this->preview($bare);

        $this->assertSame([], $preview['variants']);
        $this->assertSame(['state' => 'incomplete', 'missing' => ['standard', 'tally_identity'], 'grain' => 'run'], $preview['configuration_status']);
    }
}
