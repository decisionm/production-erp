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
 * The mechanism for collapsing the live duplicate configurations (#49/#50,
 * #57/#58) WITHOUT anyone hand-editing rows: keep the row the resolver
 * already uses, retire the rest. Dry run by default — on live this runs
 * through the manual workflow, dry run read first, like every data command
 * in this repo.
 */
class DedupeConfigurationsCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Item, 1: WorkCenter} */
    private function subject(string $sku = 'BTL-100'): array
    {
        return [
            Item::create(['sku' => $sku, 'name' => $sku, 'uom' => 'Nos.']),
            WorkCenter::create(['code' => 'ASB-1', 'name' => 'ASB 1']),
        ];
    }

    private function config(Item $item, WorkCenter $machine, int $cavities, string $approvedAt): ProductionConfiguration
    {
        return ProductionConfiguration::create([
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'default_cavities' => $cavities,
            'default_cycle_time' => '12.40',
            'status' => ConfigurationStatus::Approved->value,
            'approved_at' => $approvedAt,
        ]);
    }

    public function test_dry_run_reports_the_plan_and_writes_nothing(): void
    {
        [$item, $machine] = $this->subject();
        $older = $this->config($item, $machine, 4, '2026-07-31 02:27:25');
        $newer = $this->config($item, $machine, 5, '2026-07-31 06:00:00');

        $this->artisan('production:dedupe-configurations')
            ->expectsOutputToContain('DRY RUN — nothing written')
            ->assertSuccessful();

        $this->assertSame(ConfigurationStatus::Approved, $older->fresh()->status);
        $this->assertSame(ConfigurationStatus::Approved, $newer->fresh()->status);
    }

    public function test_write_retires_the_loser_and_keeps_the_resolvers_own_pick(): void
    {
        [$item, $machine] = $this->subject();
        $older = $this->config($item, $machine, 4, '2026-07-31 02:27:25');
        $newer = $this->config($item, $machine, 5, '2026-07-31 06:00:00');

        $service = new ProductionConfigurationService;
        $pickBefore = $service->resolve($machine->id, $item->id)->id;

        $this->artisan('production:dedupe-configurations', ['--write' => true])
            ->assertSuccessful();

        // Retired, not deleted — history stays readable.
        $this->assertSame(ConfigurationStatus::Inactive, $older->fresh()->status);
        $this->assertNotNull($older->fresh()->effective_to);
        $this->assertSame(ConfigurationStatus::Approved, $newer->fresh()->status);

        // The figure in force did not move by even one run.
        $this->assertSame($pickBefore, $newer->id);
        $this->assertSame($pickBefore, $service->resolve($machine->id, $item->id)->id);
        $this->assertSame([], $service->overlappingApproved());
    }

    public function test_ids_filter_touches_only_groups_wholly_named(): void
    {
        [$itemA, $machine] = $this->subject();
        $itemB = Item::create(['sku' => 'BTL-60', 'name' => 'BTL-60', 'uom' => 'Nos.']);

        $a1 = $this->config($itemA, $machine, 4, '2026-07-31 02:27:25');
        $a2 = $this->config($itemA, $machine, 5, '2026-07-31 06:00:00');
        $b1 = $this->config($itemB, $machine, 3, '2026-07-31 02:27:25');
        $b2 = $this->config($itemB, $machine, 4, '2026-07-31 06:00:00');

        $this->artisan('production:dedupe-configurations', [
            '--write' => true,
            '--ids' => "{$a1->id},{$a2->id}",
        ])->assertSuccessful();

        $this->assertSame(ConfigurationStatus::Inactive, $a1->fresh()->status);
        $this->assertSame(ConfigurationStatus::Approved, $a2->fresh()->status);
        // The unnamed group is untouched — no fixing by accident of a filter.
        $this->assertSame(ConfigurationStatus::Approved, $b1->fresh()->status);
        $this->assertSame(ConfigurationStatus::Approved, $b2->fresh()->status);
    }

    public function test_a_qualified_override_pair_is_not_touched(): void
    {
        // General + colour-qualified is the designed override, not a
        // duplicate: overlappingApproved() groups by exact colour, so the
        // command never sees it and must say there is nothing to do.
        [$item, $machine] = $this->subject();
        $general = $this->config($item, $machine, 4, '2026-07-31 02:27:25');
        $amber = $this->config($item, $machine, 5, '2026-07-31 06:00:00');
        $amber->update(['colour' => 'Amber']);

        $this->artisan('production:dedupe-configurations', ['--write' => true])
            ->expectsOutputToContain('No overlapping approved configurations')
            ->assertSuccessful();

        $this->assertSame(ConfigurationStatus::Approved, $general->fresh()->status);
        $this->assertSame(ConfigurationStatus::Approved, $amber->fresh()->status);
    }

    public function test_a_malformed_ids_option_is_refused(): void
    {
        $this->artisan('production:dedupe-configurations', ['--ids' => '49,fifty'])
            ->assertFailed();
    }
}
