<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\Enums\ConfigurationStatus;
use App\Modules\Production\Models\ProductionConfiguration;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ProductionConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Machine–product configurations seeded from the factory's own daily sheets.
 *
 * The properties these tests defend, in order of how much damage losing one
 * would do:
 *
 *  1. Everything imports as DRAFT, and a draft is INERT — resolve() must not
 *     return it, so the import cannot change a single run until a person
 *     approves the row.
 *  2. A row a person has approved is never touched by a re-import.
 *  3. Idempotent: running twice changes nothing the second time.
 *  4. A catalogue missing an item skips that row and says so, rather than
 *     failing the import or inventing an item.
 */
class MachineConfigurationImportTest extends TestCase
{
    use RefreshDatabase;

    /** Real Tally item names, verbatim — the fixture resolves by exact name. */
    private const ITEMS = [
        'B.100 Ml Round Pet Bottle Amber-12.9gms',
        'A.60 Ml Round Amber Pet Bottle 10gms',
        'L.500ML Kidney Clear Pet Bottle 28gms (Cover)',
        'B.500ml Round Pet Bottles Amber-36g IFF',
        'L.180ml Rib Pet Bottle Clear-Cover-10.5 G +/-0.5gms',
    ];

    private function seedFloor(): void
    {
        foreach (range(1, 10) as $n) {
            WorkCenter::create(['name' => "Machine {$n}", 'code' => "M{$n}", 'is_active' => true]);
        }
        foreach (self::ITEMS as $i => $name) {
            Item::create([
                'sku' => $name, 'name' => $name, 'uom' => 'NOS', 'is_active' => true,
                'tally_stock_item_guid' => 'g-cfg-'.$i,
            ]);
        }
    }

    public function test_observed_configurations_import_as_draft_with_the_observed_figures(): void
    {
        $this->seedFloor();

        $this->artisan('production:import-machine-configurations --write')->assertExitCode(0);

        $machine1 = WorkCenter::where('name', 'Machine 1')->firstOrFail();
        $item = Item::where('name', 'B.100 Ml Round Pet Bottle Amber-12.9gms')->firstOrFail();

        $config = ProductionConfiguration::where('work_center_id', $machine1->id)
            ->where('item_id', $item->id)
            ->firstOrFail();

        $this->assertSame(ConfigurationStatus::Draft, $config->status);
        $this->assertSame(5, $config->default_cavities);
        $this->assertSame('Amber', $config->colour);
        $this->assertNotNull($config->default_cycle_time);
        // The observed range travels with the row so the approver can see the
        // spread the median came from.
        $this->assertNotNull($config->cycle_time_min);
        $this->assertNotNull($config->cycle_time_max);
        $this->assertSame('DAILY-PRODUCTION-REVIEW', $config->source);
        $this->assertStringContainsString('shift record', (string) $config->notes);
    }

    public function test_a_draft_configuration_is_inert(): void
    {
        // THE safety property. resolve() drives what a run snapshots; if a
        // draft leaked through, this import would silently change production
        // figures for every product it touched.
        $this->seedFloor();
        $this->artisan('production:import-machine-configurations --write');

        $machine1 = WorkCenter::where('name', 'Machine 1')->firstOrFail();
        $item = Item::where('name', 'B.100 Ml Round Pet Bottle Amber-12.9gms')->firstOrFail();

        $this->assertNull(
            app(ProductionConfigurationService::class)->resolve($machine1->id, $item->id),
            'A draft configuration reached resolve() — the import is no longer inert.',
        );
    }

    public function test_reimporting_changes_nothing(): void
    {
        $this->seedFloor();
        $this->artisan('production:import-machine-configurations --write');
        $first = ProductionConfiguration::count();

        $this->artisan('production:import-machine-configurations --write');

        $this->assertSame($first, ProductionConfiguration::count());
    }

    public function test_an_approved_row_is_never_demoted_or_edited(): void
    {
        $this->seedFloor();
        $this->artisan('production:import-machine-configurations --write');

        $config = ProductionConfiguration::query()->firstOrFail();
        $config->update([
            'status' => ConfigurationStatus::Approved,
            'default_cycle_time' => '99.00',
        ]);

        $this->artisan('production:import-machine-configurations --write')
            ->expectsOutputToContain('kept as-is');

        // A person's approval — and their figure — outranks the re-import.
        $fresh = $config->fresh();
        $this->assertSame(ConfigurationStatus::Approved, $fresh->status);
        $this->assertSame('99.00', (string) $fresh->default_cycle_time);
    }

    public function test_a_missing_item_is_reported_and_skipped_not_fatal(): void
    {
        // Only two of the fixture's items exist here — the rest must be named
        // as unresolved while the two present still import.
        foreach (range(1, 10) as $n) {
            WorkCenter::create(['name' => "Machine {$n}", 'code' => "M{$n}", 'is_active' => true]);
        }
        Item::create([
            'sku' => 'A.60 Ml Round Amber Pet Bottle 10gms',
            'name' => 'A.60 Ml Round Amber Pet Bottle 10gms',
            'uom' => 'NOS', 'is_active' => true, 'tally_stock_item_guid' => 'g-only',
        ]);

        $this->artisan('production:import-machine-configurations --write')
            ->expectsOutputToContain('Could not resolve on this database')
            ->assertExitCode(0);

        $this->assertGreaterThan(0, ProductionConfiguration::count());
        $this->assertSame(
            ProductionConfiguration::count(),
            ProductionConfiguration::whereHas('item', fn ($q) => $q->where('name', 'A.60 Ml Round Amber Pet Bottle 10gms'))->count(),
        );
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->seedFloor();

        $this->artisan('production:import-machine-configurations')
            ->expectsOutputToContain('DRY RUN');

        $this->assertSame(0, ProductionConfiguration::count());
    }

    public function test_the_same_product_keeps_separate_rows_per_cavity_count(): void
    {
        // Machine 4 genuinely ran 60ml Round Amber at BOTH 4 and 5 cavities in
        // July. Those are different setups with different expected outputs —
        // collapsing them would hide exactly the choice the supervisor makes.
        $this->seedFloor();
        $this->artisan('production:import-machine-configurations --write');

        $machine4 = WorkCenter::where('name', 'Machine 4')->firstOrFail();
        $item = Item::where('name', 'A.60 Ml Round Amber Pet Bottle 10gms')->firstOrFail();

        $cavities = ProductionConfiguration::where('work_center_id', $machine4->id)
            ->where('item_id', $item->id)
            ->pluck('default_cavities')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([4, 5], $cavities);
    }
}
