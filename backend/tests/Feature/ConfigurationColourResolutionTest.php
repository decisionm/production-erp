<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Mold;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ProductionConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A stated colour SELECTS a configuration; it does not hide the general one.
 *
 * The bug this file locks shut: resolve() filtered `where('colour', $colour)`,
 * so a machine holding one general approved standard resolved to NOTHING the
 * moment a run named its colour. Two consequences, both real:
 *
 *  - The batch preview resolves WITHOUT a colour (BatchPreviewController) and
 *    found the standard; Start Batch passes the colour and did not. The same
 *    product, on the same machine, in the same minute, was configured on one
 *    screen and unconfigured on the next — and the run that followed was
 *    stamped legacy and measured against the item master instead of the
 *    approved standard.
 *  - It contradicted resolve()'s own ordering, which ranks a colour-qualified
 *    row above a general one — an ordering that can only ever decide anything
 *    if both rows are in the result set.
 *
 * The rule now: the colour-specific configuration when there is one, the
 * general configuration when there is not, and preview and Start Batch always
 * agree.
 */
class ConfigurationColourResolutionTest extends TestCase
{
    use RefreshDatabase;

    private WorkCenter $machine;

    private Item $bottle;

    private Warehouse $fgStore;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->machine = WorkCenter::create(['code' => 'MC-04', 'name' => 'Machine 4', 'is_active' => true]);
        $this->fgStore = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg']);
        $this->bottle = Item::create([
            'sku' => 'BTL-500-AMB', 'name' => '500 ml Round Amber', 'uom' => 'Nos.', 'is_active' => true,
            'nominal_weight_grams' => '31.5000', 'nos_per_box' => 800, 'colour' => 'Amber',
            'standard_cycle_time' => '20', 'standard_cavities' => 4,
            'tally_stock_item_guid' => 'itm-bottle',
        ]);
    }

    private function service(): ProductionConfigurationService
    {
        return app(ProductionConfigurationService::class);
    }

    private function approved(array $overrides = []): int
    {
        $configuration = $this->service()->create($overrides + [
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'unit_weight_grams' => '31.5',
            'default_cycle_time' => '12',
            'default_cavities' => 8,
        ], null);

        return $this->service()->approve($configuration, null)->id;
    }

    private function actor(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['production.manage', 'production.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo(['production.manage', 'production.view']);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_a_colour_stated_run_resolves_the_general_configuration(): void
    {
        // One approved standard, no colour on it — the shape of nearly every
        // configuration in this factory today.
        $general = $this->approved();

        $resolved = $this->service()->resolve(
            workCenterId: $this->machine->id,
            itemId: $this->bottle->id,
            colour: 'Amber',
        );

        $this->assertNotNull($resolved, 'Naming the colour must not hide the standard that governs every colour.');
        $this->assertSame($general, $resolved->id);
    }

    public function test_the_colour_specific_configuration_wins_when_both_exist(): void
    {
        $general = $this->approved();
        $amber = $this->approved(['colour' => 'Amber', 'default_cycle_time' => '15']);

        $resolved = $this->service()->resolve(
            workCenterId: $this->machine->id,
            itemId: $this->bottle->id,
            colour: 'Amber',
        );

        $this->assertSame($amber, $resolved->id, 'Most specific first: the Amber standard outranks the general one.');
        $this->assertSame('15.00', (string) $resolved->default_cycle_time);

        // A different colour still falls back to the general standard rather
        // than borrowing Amber's figures.
        $this->assertSame(
            $general,
            $this->service()->resolve($this->machine->id, $this->bottle->id, colour: 'Milk White')->id,
        );
    }

    public function test_a_later_general_standard_cannot_outrank_a_colour_specific_one(): void
    {
        // orderByDesc('effective_from') is the LAST tiebreaker, not the first
        // — otherwise a general standard approved today would quietly
        // displace the colour-specific one the factory agreed to last month.
        $amber = $this->approved(['colour' => 'Amber', 'effective_from' => now()->subMonth()->toDateString()]);
        $this->approved(['effective_from' => now()->toDateString()]);

        $this->assertSame(
            $amber,
            $this->service()->resolve($this->machine->id, $this->bottle->id, colour: 'Amber')->id,
        );
    }

    public function test_a_mould_stated_run_resolves_the_general_configuration(): void
    {
        $mold = Mold::create(['code' => 'MLD-1', 'name' => 'Mould 1', 'cavities' => 8]);
        $general = $this->approved();

        $this->assertSame(
            $general,
            $this->service()->resolve($this->machine->id, $this->bottle->id, moldId: $mold->id)->id,
            'A mould-less standard governs every mould until a mould-specific one is approved.',
        );
    }

    public function test_the_mould_specific_configuration_wins_when_both_exist(): void
    {
        $mold = Mold::create(['code' => 'MLD-1', 'name' => 'Mould 1', 'cavities' => 8]);
        $this->approved();
        $moulded = $this->approved(['mold_id' => $mold->id, 'default_cavities' => 6]);

        $resolved = $this->service()->resolve($this->machine->id, $this->bottle->id, moldId: $mold->id);

        $this->assertSame($moulded, $resolved->id);
        $this->assertSame(6, $resolved->default_cavities);
    }

    public function test_the_preview_and_the_started_batch_quote_the_same_standard(): void
    {
        // The parity that was broken: the preview resolves without a colour,
        // Start Batch resolves with one. Both must land on the same
        // configuration, or the supervisor is shown a rate the run is not
        // measured against.
        $configuration = $this->approved(['default_cycle_time' => '12', 'default_cavities' => 8]);
        $this->actor();

        $preview = $this->getJson('/api/v1/production/shift-production-entries/preview?'.http_build_query([
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fgStore->id,
            'shift_id' => $this->shift->id,
        ]))->assertOk();

        // The configuration's 12 s, not the item master's 20 s.
        $this->assertSame('12.00', (string) $preview->json('data.estimation.standard_cycle_time'));
        $this->assertSame(8, $preview->json('data.estimation.active_cavities'));

        $entry = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fgStore->id,
            // The colour the item carries — what the floor sends.
            'colour' => 'Amber',
        ])->assertOk()->json('data');

        // The run is stamped with the SAME configuration the preview quoted.
        // Before the fix this was null: naming the colour resolved nothing,
        // and the batch ran as legacy/unconfigured against 20 s / 4 cavities.
        $this->assertSame($configuration, $entry['production_configuration_id']);
        $this->assertSame('configuration', $entry['cycle_time_source']);
        $this->assertSame('Amber', $entry['colour']);
    }
}
