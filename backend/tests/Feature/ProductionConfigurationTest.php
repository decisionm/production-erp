<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\ConfigurationStatus;
use App\Modules\Production\Models\ProductionConfiguration;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ProductionCalculationEngine;
use App\Modules\Production\Services\ProductionConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The configuration lifecycle and the policy it enforces on production:
 * draft → approved → inactive, machine capability limits, non-overlap, and
 * the bounded, reasoned override at Start Batch.
 */
class ProductionConfigurationTest extends TestCase
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
        $this->machine = WorkCenter::create([
            'code' => 'MC-10', 'name' => 'Machine 10', 'is_active' => true,
            'capacity_class' => 'High Capacity',
            'permitted_cavities' => [6, 7, 8],
            'cycle_time_min' => '8', 'cycle_time_max' => '14',
        ]);
        $this->fgStore = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg']);
        $this->bottle = Item::create([
            'sku' => 'BTL-500-AMB', 'name' => '500 ml Round Amber', 'uom' => 'Nos.', 'is_active' => true,
            'nominal_weight_grams' => '31.5000', 'nos_per_box' => 800, 'colour' => 'Amber',
            'tally_stock_item_guid' => 'itm-bottle',
        ]);
    }

    private function actor(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['production.manage', 'production.view'] as $p) {
            Permission::findOrCreate($p, 'web');
        }
        $user->givePermissionTo(['production.manage', 'production.view']);
        Sanctum::actingAs($user);

        return $user;
    }

    private function draft(array $overrides = []): ProductionConfiguration
    {
        return app(ProductionConfigurationService::class)->create($overrides + [
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'unit_weight_grams' => '31.5',
            'default_cycle_time' => '12',
            'default_cavities' => 8,
        ], null);
    }

    public function test_a_configuration_is_always_created_as_a_draft(): void
    {
        // Even if a caller tries to assert otherwise — the workbook's rows
        // are all "To Confirm" and must not arrive looking approved.
        $this->actor();

        $this->postJson('/api/v1/production/configurations', [
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'default_cycle_time' => '12',
            'default_cavities' => 8,
            'unit_weight_grams' => '31.5',
            'status' => 'approved',
        ])->assertSuccessful()->assertJsonPath('data.status', 'draft');
    }

    public function test_approval_refuses_a_configuration_with_missing_standards(): void
    {
        $config = $this->draft(['default_cycle_time' => null, 'unit_weight_grams' => null]);

        $this->actor();
        $this->postJson("/api/v1/production/configurations/{$config->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'Cannot approve — still missing: default cycle time, unit weight.');
    }

    public function test_approval_refuses_cavities_outside_the_machines_permitted_set(): void
    {
        // Machine 10 permits 6/7/8 — 5 is not a narrowing, it is a value the
        // machine cannot run.
        $config = $this->draft(['default_cavities' => 5]);

        $this->actor();
        $this->postJson("/api/v1/production/configurations/{$config->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('errors.default_cavities.0', 'Machine 10 permits only these cavity options: 6, 7, 8.');
    }

    public function test_a_cycle_time_above_the_global_bound_is_approved_with_a_warning(): void
    {
        // The factory's own product master carries 48 standards above the
        // 14 s "global maximum", up to 30.5 s. A blocking bound would
        // refuse half the real catalogue, so bounds are advisory.
        $config = $this->draft(['default_cycle_time' => '21.5']);

        $this->actor();
        $this->postJson("/api/v1/production/configurations/{$config->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.default_cycle_time', '21.50');

        // The advisory note is still available to show beside it.
        $warning = app(ProductionConfigurationService::class)
            ->cycleTimeWarning('21.5', $config->fresh()->load('workCenter'), $this->machine);

        $this->assertNotNull($warning);
        $this->assertStringContainsString('above the usual maximum', $warning);
    }

    public function test_cavity_six_and_seven_are_ordinary_valid_configurations(): void
    {
        // 6 and 7 are CAVITY COUNTS, not machines. The factory master has
        // 6 variants at 6 cavities and 4 at 7, and they must behave exactly
        // like 2, 3, 4 or 5.
        $service = app(ProductionConfigurationService::class);
        $this->actor();

        foreach ([6, 7] as $cavities) {
            $item = Item::create([
                'sku' => "BTL-CAV{$cavities}", 'name' => "Bottle {$cavities} cav",
                'uom' => 'Nos.', 'is_active' => true, 'nominal_weight_grams' => '10.5',
            ]);

            $config = $service->create([
                'work_center_id' => $this->machine->id, 'item_id' => $item->id,
                'default_cycle_time' => '11.6', 'default_cavities' => $cavities,
                'unit_weight_grams' => '10.5',
            ], null);

            $this->postJson("/api/v1/production/configurations/{$config->id}/approve")
                ->assertOk()
                ->assertJsonPath('data.default_cavities', $cavities)
                ->assertJsonPath('data.status', 'approved');
        }
    }

    public function test_two_approved_configurations_cannot_overlap_in_time(): void
    {
        $service = app(ProductionConfigurationService::class);
        $first = $this->draft();
        $service->approve($first, null);

        $second = $this->draft();

        $this->actor();
        $this->postJson("/api/v1/production/configurations/{$second->id}/approve")
            ->assertStatus(422)
            ->assertJsonFragment(['effective_from' => ["Configuration #{$first->id} is already approved for this machine, product, mould and colour over an overlapping period. Set its end date first, or make this one start later."]]);
    }

    public function test_an_approved_configuration_cannot_be_edited_in_place(): void
    {
        $config = $this->draft();
        app(ProductionConfigurationService::class)->approve($config, null);

        $this->actor();
        $this->putJson("/api/v1/production/configurations/{$config->id}", [
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'default_cycle_time' => '9',
        ])->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'An approved configuration cannot be edited. Copy it to a new draft, or set it inactive first.');
    }

    public function test_start_batch_resolves_the_approved_configuration_and_snapshots_the_version(): void
    {
        $config = $this->draft(['default_cycle_time' => '12', 'default_cavities' => 8]);
        app(ProductionConfigurationService::class)->approve($config, null);

        $this->actor();
        $entry = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fgStore->id,
        ])->assertOk()->json('data');

        $row = ShiftProductionEntry::find($entry['id']);

        $this->assertSame($config->id, $row->production_configuration_id);
        // The approval is attributable — who and when, not just a flag.
        $this->assertNotNull($config->fresh()->approved_at);
        $this->assertSame(ProductionCalculationEngine::VERSION_CURRENT, $row->calculation_version);
        // Configuration standards win over the item master's.
        $this->assertSame('12.00', (string) $row->standard_cycle_time);
        $this->assertSame(8, $row->standard_cavities);
        $this->assertSame('configuration', $row->cycle_time_source);
        $this->assertFalse($row->config_snapshot['unconfigured']);
    }

    public function test_an_unconfigured_product_still_runs_but_is_marked_legacy(): void
    {
        // Nothing is blocked by the absence of a configuration — the factory
        // has 364 products without one and must keep producing.
        $this->actor();
        $entry = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fgStore->id,
        ])->assertOk()->json('data');

        $row = ShiftProductionEntry::find($entry['id']);

        $this->assertNull($row->production_configuration_id);
        $this->assertTrue($row->config_snapshot['unconfigured']);
        // Still stamped with the current engine — the run is new, only its
        // standards are legacy.
        $this->assertSame(ProductionCalculationEngine::VERSION_CURRENT, $row->calculation_version);
    }

    public function test_an_override_outside_the_permitted_set_is_refused(): void
    {
        $config = $this->draft();
        app(ProductionConfigurationService::class)->approve($config, null);

        $this->actor();
        $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fgStore->id,
            'cavities_override' => 5,
            'override_reason' => 'two cavities blocked',
        ])->assertStatus(422)
            ->assertJsonPath('errors.cavities.0', 'Permitted cavity options are: 6, 7, 8.');
    }

    public function test_an_override_within_bounds_requires_a_reason_and_records_it(): void
    {
        $config = $this->draft();
        app(ProductionConfigurationService::class)->approve($config, null);

        $this->actor();

        // Without a reason: refused.
        $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fgStore->id,
            'cavities_override' => 6,
        ])->assertStatus(422)
            ->assertJsonPath('errors.override_reason.0', 'A reason is required when overriding the approved cycle time or cavities.');

        // With one: accepted, and the reason is on the record.
        $entry = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fgStore->id,
            'cavities_override' => 6,
            'override_reason' => 'two cavities blocked',
        ])->assertOk()->json('data');

        $row = ShiftProductionEntry::find($entry['id']);
        $this->assertSame(6, $row->active_cavities);
        $this->assertSame('override', $row->cavities_source);
        $this->assertSame('two cavities blocked', $row->override_reason);
        // The approved default is still on the record beside the override,
        // so approval can show default vs effective.
        $this->assertSame(8, $row->standard_cavities);
    }

    public function test_echoing_the_default_back_is_not_treated_as_an_override(): void
    {
        $config = $this->draft();
        app(ProductionConfigurationService::class)->approve($config, null);

        $this->actor();
        $entry = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fgStore->id,
            // The form prefills 8 and sends it back untouched.
            'cavities_override' => 8,
        ])->assertOk()->json('data');

        $row = ShiftProductionEntry::find($entry['id']);
        $this->assertSame('configuration', $row->cavities_source);
        $this->assertNull($row->override_reason);
    }

    public function test_approval_records_who_approved_and_when(): void
    {
        $config = $this->draft();
        $approver = User::factory()->create(['is_active' => true]);

        $approved = app(ProductionConfigurationService::class)->approve($config, $approver->id);

        $this->assertSame($approver->id, $approved->approved_by);
        $this->assertNotNull($approved->approved_at);
        $this->assertNotNull($approved->effective_from);
    }

    public function test_only_approved_configurations_appear_for_a_machine(): void
    {
        $approved = $this->draft();
        app(ProductionConfigurationService::class)->approve($approved, null);

        $other = Item::create(['sku' => 'BTL-2', 'name' => 'Other bottle', 'uom' => 'Nos.', 'is_active' => true]);
        $this->draft(['item_id' => $other->id]);   // stays draft

        $this->actor();
        $this->getJson("/api/v1/production/work-centers/{$this->machine->id}/configurations")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $approved->id);
    }

    public function test_deactivating_frees_the_key_for_a_new_approved_configuration(): void
    {
        $service = app(ProductionConfigurationService::class);
        $first = $this->draft();
        $service->approve($first, null);
        $service->deactivate($first->fresh());

        $second = $this->draft(['effective_from' => now()->addDay()->toDateString()]);

        $this->actor();
        $this->postJson("/api/v1/production/configurations/{$second->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', ConfigurationStatus::Approved->value);
    }
}
