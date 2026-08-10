<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\ConfigurationStatus;
use App\Modules\Production\Models\ProductionConfiguration;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ConfigurationTieResolutionTest pins that the resolver's pick between two
 * duplicate approved configurations is DETERMINISTIC. This file pins the
 * other half of the contract: the pick is also TOLD to the supervisor.
 *
 * The live shape is configs #49/#50 — item 339 "B.100 Ml Round Pet Bottle
 * Clear-12.9gms" on ASB-1, CT 12.40/4 cavities against CT 12.45/5 — both
 * approved, both applying to every run, differing on the very figures the
 * shift is measured by. The resolver picks one stably; before this warning,
 * nothing on the Start Batch screen said there had been a choice at all.
 */
class ConfigurationOverlapSurfacedTest extends TestCase
{
    use RefreshDatabase;

    private WorkCenter $machine;

    private Item $item;

    private Warehouse $warehouse;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->machine = WorkCenter::create(['name' => 'ASB 1', 'code' => 'ASB-1', 'is_active' => true]);
        $this->shift = Shift::create(['name' => 'Shift A', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $this->warehouse = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg']);
        $this->item = Item::create([
            'sku' => 'B.100 Ml Round Pet Bottle Clear-12.9gms',
            'name' => 'B.100 Ml Round Pet Bottle Clear-12.9gms',
            'uom' => 'NOS', 'is_active' => true, 'colour' => 'Clear',
            'standard_cycle_time' => '12.40', 'standard_cavities' => 4,
            'nominal_weight_grams' => '12.9000', 'nos_per_box' => 500,
            'tally_stock_item_guid' => 'itm-100-clear',
        ]);

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        $user->givePermissionTo('production.view');
        $this->actingAs($user);
    }

    private function config(array $overrides = []): ProductionConfiguration
    {
        return ProductionConfiguration::create([
            'work_center_id' => $this->machine->id,
            'item_id' => $this->item->id,
            'default_cycle_time' => '12.40',
            'default_cavities' => 4,
            'unit_weight_grams' => '12.9000',
            'status' => ConfigurationStatus::Approved->value,
            'approved_at' => '2026-07-31 02:27:25',
            ...$overrides,
        ]);
    }

    private function preview(): array
    {
        return $this->getJson(
            '/api/v1/production/shift-production-entries/preview'
            ."?item_id={$this->item->id}&work_center_id={$this->machine->id}"
            ."&warehouse_id={$this->warehouse->id}&shift_id={$this->shift->id}"
        )->assertOk()->json('data');
    }

    public function test_a_duplicate_pair_previews_without_error_and_names_the_tie(): void
    {
        // The #49/#50 shape, oldest first, so a naive first-row pick is
        // distinguishable from the deterministic one.
        $this->config(['default_cycle_time' => '12.40', 'default_cavities' => 4]);
        $newer = $this->config(['default_cycle_time' => '12.45', 'default_cavities' => 5, 'approved_at' => '2026-07-31 06:00:00']);

        $data = $this->preview();

        // The deterministic pick drives the figures…
        $this->assertSame($newer->id, $data['configuration']['id']);
        $this->assertSame(5, $data['configuration']['default_cavities']);

        // …and the tie is SAID, with the pick and the cure in the message.
        $codes = array_column($data['warnings'], 'code');
        $this->assertContains('configuration_overlap', $codes);
        $overlap = collect($data['warnings'])->firstWhere('code', 'configuration_overlap');
        $this->assertStringContainsString("#{$newer->id}", $overlap['message']);
        $this->assertStringContainsString('different figures', $overlap['message']);
    }

    public function test_a_single_configuration_raises_no_overlap_warning(): void
    {
        $this->config();

        $codes = array_column($this->preview()['warnings'], 'code');

        $this->assertNotContains('configuration_overlap', $codes);
    }

    public function test_a_colour_qualified_override_of_a_general_row_is_the_feature_not_a_tie(): void
    {
        // The designed shape: a general setting plus an Amber-specific one.
        // Specificity decides — nobody needs a warning that the feature
        // worked, and one here would fire on every legitimately qualified
        // run until it stopped being read.
        $this->config(['colour' => null]);
        $this->config(['colour' => 'Clear', 'default_cavities' => 5]);

        $codes = array_column($this->preview()['warnings'], 'code');

        $this->assertNotContains('configuration_overlap', $codes);
    }

    public function test_a_retired_duplicate_no_longer_warns(): void
    {
        $this->config();
        $this->config([
            'default_cavities' => 5,
            'status' => ConfigurationStatus::Inactive->value,
            'effective_to' => '2026-08-01',
        ]);

        $codes = array_column($this->preview()['warnings'], 'code');

        $this->assertNotContains('configuration_overlap', $codes);
    }

    public function test_sequential_windows_are_history_not_a_tie(): void
    {
        // A row that ended before today's run and its replacement — correct
        // history, one applicable row, no warning.
        $this->config(['effective_from' => '2026-07-01', 'effective_to' => '2026-07-31']);
        $this->config(['effective_from' => '2026-08-01', 'default_cavities' => 5]);

        $data = $this->preview();

        $this->assertSame(5, $data['configuration']['default_cavities']);
        $this->assertNotContains('configuration_overlap', array_column($data['warnings'], 'code'));
    }
}
