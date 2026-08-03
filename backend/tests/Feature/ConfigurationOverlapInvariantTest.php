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
 * The invariant nobody was asserting: at most one approved configuration may
 * be live for a given item + machine + mould + colour at any one date.
 *
 * The importer was free to break it and did — six live groups, four of them
 * contradicting each other on cavities. No code test could have found that,
 * because every test built one configuration and asked the rule a question.
 * The bug lived in the DATA, so the check has to look at data.
 *
 * Deliberately a REPORT, not a refusal. The factory is testing on a live floor
 * and a validation rule that started rejecting saves on day one would block
 * work over rows that already exist. Overlaps are surfaced so someone can
 * retire the wrong one, which is the actual fix.
 */
class ConfigurationOverlapInvariantTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Item, 1: WorkCenter} */
    private function subject(): array
    {
        return [
            Item::create(['sku' => 'BTL-60', 'name' => '60 Ml Round Amber', 'uom' => 'Nos.']),
            WorkCenter::create(['code' => 'MC-04', 'name' => 'Machine 4', 'display_sequence' => 4]),
        ];
    }

    private function config(Item $item, WorkCenter $machine, int $cavities, ?string $from = null, string $status = 'approved'): ProductionConfiguration
    {
        return ProductionConfiguration::create([
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'colour' => 'Amber',
            'default_cavities' => $cavities,
            'default_cycle_time' => '11.50',
            'unit_weight_grams' => '10.0000',
            'status' => $status,
            'effective_from' => $from,
            'approved_at' => '2026-07-31 02:27:25',
        ]);
    }

    public function test_two_open_ended_approved_configurations_are_reported_as_overlapping(): void
    {
        [$item, $machine] = $this->subject();
        $a = $this->config($item, $machine, 4);
        $b = $this->config($item, $machine, 5);

        $overlaps = (new ProductionConfigurationService)->overlappingApproved();

        $this->assertCount(1, $overlaps);
        $ids = $overlaps[0]['configuration_ids'];
        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);
        // The reason it matters: the two disagree on a figure every expected
        // output is derived from.
        $this->assertTrue($overlaps[0]['values_differ']);
    }

    public function test_a_retired_configuration_no_longer_overlaps(): void
    {
        // The fix path: retiring the contradictory row clears the overlap
        // without deleting anything.
        [$item, $machine] = $this->subject();
        $this->config($item, $machine, 5);
        $wrong = $this->config($item, $machine, 4);

        $wrong->update(['status' => ConfigurationStatus::Inactive->value]);

        $this->assertSame([], (new ProductionConfigurationService)->overlappingApproved());
        // Still there, still readable — retired, never deleted.
        $this->assertDatabaseHas('production_configurations', ['id' => $wrong->id]);
    }

    public function test_configurations_on_different_machines_do_not_overlap(): void
    {
        // A product legitimately runs on several machines with different
        // settings; that is the whole purpose of a machine exception.
        [$item, $machine] = $this->subject();
        $other = WorkCenter::create(['code' => 'MC-10', 'name' => 'Machine 10', 'display_sequence' => 10]);

        $this->config($item, $machine, 4);
        $this->config($item, $other, 7);

        $this->assertSame([], (new ProductionConfigurationService)->overlappingApproved());
    }

    public function test_non_overlapping_date_windows_do_not_overlap(): void
    {
        [$item, $machine] = $this->subject();

        $this->config($item, $machine, 4)->update(['effective_to' => '2026-07-29']);
        $this->config($item, $machine, 5, '2026-07-30');

        $this->assertSame([], (new ProductionConfigurationService)->overlappingApproved());
    }
}
