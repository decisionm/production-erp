<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\ConfigurationStatus;
use App\Modules\Production\Models\Mold;
use App\Modules\Production\Models\ProductionConfiguration;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 5.5 (P5.5-01): the Start Batch preview NAMES the mould the approved
 * machine configuration runs, so the modal can say it instead of asking or
 * leaving it unsaid. Additive: `configuration.mould` rides beside the
 * configuration's own figures — {id, code, name} when the configuration
 * names a mould, null when it does not, and absent altogether (with the
 * whole configuration block) when no configuration governs the run.
 *
 * Nothing is decided here: the mould is READ from the same configuration
 * ProductionConfigurationService::resolve already picked for the estimate.
 */
class BatchPreviewMouldTest extends TestCase
{
    use RefreshDatabase;

    private WorkCenter $machine;

    private Item $item;

    private Warehouse $warehouse;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->machine = WorkCenter::create(['name' => 'Machine 6', 'code' => 'M6', 'is_active' => true]);
        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $this->warehouse = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg']);
        $this->item = Item::create([
            'sku' => 'B.200 Ml Brute Pet Bottle Amber-18gms',
            'name' => 'B.200 Ml Brute Pet Bottle Amber-18gms',
            'uom' => 'NOS', 'is_active' => true, 'colour' => 'Amber',
            'standard_cycle_time' => '20.00', 'standard_cavities' => 2,
            'nominal_weight_grams' => '18.0000', 'nos_per_box' => 490,
            'tally_stock_item_guid' => 'itm-brute',
        ]);

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        Permission::findOrCreate('production.manage', 'web');
        $user->givePermissionTo(['production.view', 'production.manage']);
        $this->actingAs($user);
    }

    private function approvedConfiguration(array $overrides = []): ProductionConfiguration
    {
        return ProductionConfiguration::create(array_merge([
            'work_center_id' => $this->machine->id,
            'item_id' => $this->item->id,
            'colour' => 'Amber',
            'default_cycle_time' => '15.10',
            'default_cavities' => 4,
            'unit_weight_grams' => '18.0000',
            'status' => ConfigurationStatus::Approved,
            'source' => 'DAILY-PRODUCTION-REVIEW',
        ], $overrides));
    }

    private function preview(): array
    {
        return $this->getJson(
            '/api/v1/production/shift-production-entries/preview'
            ."?item_id={$this->item->id}&work_center_id={$this->machine->id}"
            ."&warehouse_id={$this->warehouse->id}&shift_id={$this->shift->id}"
        )->assertOk()->json('data');
    }

    public function test_the_preview_names_the_configurations_mould(): void
    {
        $mold = Mold::create(['code' => 'MLD-200-BRUTE', 'name' => '200ml Brute 4-cav', 'cavity_count' => 4]);
        $this->approvedConfiguration(['mold_id' => $mold->id]);

        $data = $this->preview();

        $this->assertNotNull($data['configuration']);
        $this->assertArrayHasKey('mould', $data['configuration']);
        $this->assertSame(
            ['id' => $mold->id, 'code' => 'MLD-200-BRUTE', 'name' => '200ml Brute 4-cav'],
            $data['configuration']['mould'],
        );
    }

    public function test_a_configuration_without_a_mould_says_null_not_a_guess(): void
    {
        $this->approvedConfiguration();

        $data = $this->preview();

        $this->assertNotNull($data['configuration']);
        $this->assertArrayHasKey('mould', $data['configuration']);
        $this->assertNull($data['configuration']['mould']);
    }

    public function test_no_configuration_means_no_configuration_block_and_no_mould(): void
    {
        $data = $this->preview();

        $this->assertNull($data['configuration']);
    }

    public function test_the_mould_is_the_resolved_configurations_mould_not_another_rows(): void
    {
        // Two approved rows: a mould-qualified one and a general one. The
        // resolver ranks the mould-qualified row first; the preview must
        // name THAT row's mould — the same row whose figures it quotes.
        $mold = Mold::create(['code' => 'MLD-A', 'name' => 'Mould A', 'cavity_count' => 6]);
        $this->approvedConfiguration(['default_cavities' => 4]);
        $this->approvedConfiguration(['mold_id' => $mold->id, 'default_cavities' => 6]);

        $data = $this->preview();

        $this->assertSame(6, $data['configuration']['default_cavities']);
        $this->assertSame('MLD-A', $data['configuration']['mould']['code']);
    }
}
