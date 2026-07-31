<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The production-readiness gate: a product whose masters are incomplete
 * must not start, and the refusal must name every missing field rather than
 * saying "not ready".
 *
 * The gate's whole purpose is moving the cost of a missing master from
 * after the shift (a Tally rejection, or an approval screen full of dashes)
 * to before it, while the supervisor can still pick a different product.
 */
class ProductReadinessGateTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $fgStore;

    private WorkCenter $machine;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'is_active' => true]);
        $this->fgStore = Warehouse::create([
            'code' => 'FG', 'name' => 'FG Store', 'is_active' => true,
            'tally_guid' => 'gd-fg-0001',
        ]);

        // Enforcement is OFF by default (watch-only) so that a deployment
        // reaching an un-edited .env cannot refuse every under-configured
        // product. These tests are about what the gate does WHEN enforcing,
        // so they opt in explicitly; the default itself is covered by
        // test_the_shipped_default_is_watch_only below.
        config()->set('production.readiness.enforced', true);
    }

    /** A product with every master present — the control for every other case. */
    private function readyItem(array $overrides = []): Item
    {
        $item = Item::create($overrides + [
            'sku' => 'BTL-500',
            'name' => '500ml Round Amber',
            'uom' => 'Nos.',
            'is_active' => true,
            'nominal_weight_grams' => '31.5000',
            'standard_cycle_time' => '12.00',
            'standard_cavities' => 5,
            'nos_per_box' => 800,
            'nos_per_tray' => 40,
            'colour' => 'Amber',
            'tally_stock_item_guid' => 'itm-0001',
        ]);

        $resin = Item::create(['sku' => 'PET', 'name' => 'Billion Pet Resin', 'uom' => 'Kgs.', 'is_active' => true]);
        $bom = Bom::create(['item_id' => $item->id, 'name' => 'BOM', 'version' => '1', 'is_active' => true]);
        $bom->lines()->create(['component_item_id' => $resin->id, 'quantity_per' => '0.0315']);

        return $item->fresh();
    }

    private function supervisor(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.manage', 'web');
        Permission::findOrCreate('production.view', 'web');
        $user->givePermissionTo(['production.manage', 'production.view']);
        Sanctum::actingAs($user);

        return $user;
    }

    private function startPayload(Item $item): array
    {
        return [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $this->fgStore->id,
        ];
    }

    public function test_a_fully_mastered_product_starts_normally(): void
    {
        $this->supervisor();
        $item = $this->readyItem();

        $this->postJson('/api/v1/production/shift-production-entries', $this->startPayload($item))
            ->assertOk()
            ->assertJsonPath('data.batch_status', BatchStatus::InProgress->value);
    }

    public function test_a_product_missing_cycle_time_and_packing_is_refused_naming_both(): void
    {
        $this->supervisor();
        $item = $this->readyItem(['standard_cycle_time' => null, 'nos_per_box' => null]);

        $response = $this->postJson('/api/v1/production/shift-production-entries', $this->startPayload($item))
            ->assertStatus(422)
            ->assertJsonPath('code', 'product_not_ready');

        $codes = array_column($response->json('blocking'), 'code');
        sort($codes);
        $this->assertSame(['cycle_time', 'packing'], $codes);

        // The message names the fields — "not production-ready" alone would
        // leave the supervisor with nothing to act on.
        $this->assertStringContainsString('standard cycle time', $response->json('message'));
        $this->assertStringContainsString('packing configuration', $response->json('message'));

        $this->assertDatabaseCount('shift_production_entries', 0);
    }

    public function test_a_product_with_no_tally_identity_is_refused_before_the_shift_not_after(): void
    {
        // The exact failure the exception report describes: the voucher dies
        // with "Stock Item does not exist" hours after the work is done.
        $this->supervisor();
        $item = $this->readyItem(['tally_stock_item_guid' => null]);

        $this->postJson('/api/v1/production/shift-production-entries', $this->startPayload($item))
            ->assertStatus(422)
            ->assertJsonPath('code', 'product_not_ready')
            ->assertJsonPath('blocking.0.code', 'tally_item');
    }

    public function test_a_warehouse_without_a_tally_godown_is_refused(): void
    {
        $this->supervisor();
        $item = $this->readyItem();
        $seeded = Warehouse::create(['code' => 'FG-STORE', 'name' => 'Finished Goods Store', 'is_active' => true]);

        $this->postJson('/api/v1/production/shift-production-entries', [
            ...$this->startPayload($item),
            'warehouse_id' => $seeded->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('blocking.0.code', 'tally_godown');
    }

    public function test_a_deactivated_machine_is_refused_through_the_api(): void
    {
        // Deactivating a machine was previously a client-side filter only —
        // the API accepted a bare exists: check, so a stale tab or a direct
        // call could still start on a machine the factory had retired.
        $this->supervisor();
        $item = $this->readyItem();
        $this->machine->update(['is_active' => false]);

        $this->postJson('/api/v1/production/shift-production-entries', $this->startPayload($item))
            ->assertStatus(422)
            ->assertJsonPath('blocking.0.code', 'machine_active');
    }

    public function test_a_deactivated_shift_is_refused_through_the_api(): void
    {
        // Same hole as the machine above, and it survived that fix: the shift
        // rule was a bare exists: check, so the "only active shifts" rule lived
        // solely in the browser's picker. A retired shift's start/end times
        // drive the shift-aware production date and the Tally voucher's period,
        // so a batch filed against one lands on a date nobody worked.
        //
        // Refused by validation rather than by the readiness gate, which has no
        // shift check to extend — hence a plain 422 on the field, not a
        // structured `blocking` finding.
        $this->supervisor();
        $item = $this->readyItem();
        $this->shift->update(['is_active' => false]);

        $this->postJson('/api/v1/production/shift-production-entries', $this->startPayload($item))
            ->assertStatus(422)
            ->assertJsonValidationErrors('shift_id');
    }

    public function test_an_active_shift_still_starts(): void
    {
        // The other half: scoping the rule must not refuse the normal case.
        $this->supervisor();
        $item = $this->readyItem();

        $this->postJson('/api/v1/production/shift-production-entries', $this->startPayload($item))
            ->assertStatus(200);
    }

    public function test_a_missing_recipe_is_not_a_finding_at_all(): void
    {
        // The factory decided recipes come after go-live, so EVERY product
        // lacks one — a notice that fires on every product on every start is
        // wallpaper, not information, and the owner read it as an error three
        // times in one night. The expected-materials card simply stays empty.
        $this->supervisor();
        $item = $this->readyItem(['colour' => null]);
        Bom::query()->where('item_id', $item->id)->update(['is_active' => false]);

        $this->postJson('/api/v1/production/shift-production-entries', $this->startPayload($item))
            ->assertOk();

        $preview = $this->getJson('/api/v1/production/shift-production-entries/preview?item_id='.$item->id)
            ->assertOk();

        $warnings = array_column($preview->json('data.readiness.warnings'), 'code');
        $this->assertNotContains('consumption_recipe', $warnings);
        // colour still warns — it drives a real suggestion and is fixable.
        $this->assertContains('colour', $warnings);
        $this->assertTrue($preview->json('data.readiness.ready'));
    }

    public function test_severity_is_configurable_so_a_factory_can_tighten_the_gate(): void
    {
        config()->set('production.readiness.checks.weight', 'block');

        $this->supervisor();
        $item = $this->readyItem(['nominal_weight_grams' => null]);

        $this->postJson('/api/v1/production/shift-production-entries', $this->startPayload($item))
            ->assertStatus(422)
            ->assertJsonPath('blocking.0.code', 'weight');
    }

    public function test_the_shipped_default_is_watch_only(): void
    {
        // Deployment-safety property, asserted against the real config file
        // rather than a value set in a test: ~364 of ~410 finished goods
        // still lack cycle time and cavities, so a `true` default would
        // refuse them all on the next shift if a server's .env had not been
        // edited yet. Watch-only cannot cause that.
        // Read the shipped config file directly — setUp() above overrides
        // the runtime value, and what matters here is the default a fresh
        // deployment gets.
        $shipped = require config_path('production.php');

        $this->assertFalse(
            (bool) $shipped['readiness']['enforced'],
            'production.readiness.enforced must default to false — see the config comment.',
        );
    }

    public function test_the_master_switch_turns_enforcement_off_without_hiding_findings(): void
    {
        // How a factory watches the gate for a week before letting it bite.
        config()->set('production.readiness.enforced', false);

        $this->supervisor();
        $item = $this->readyItem(['standard_cycle_time' => null]);

        $this->postJson('/api/v1/production/shift-production-entries', $this->startPayload($item))
            ->assertOk();

        $preview = $this->getJson('/api/v1/production/shift-production-entries/preview?item_id='.$item->id)
            ->assertOk()
            ->assertJsonPath('data.readiness.ready', true);

        $this->assertContains('cycle_time', array_column($preview->json('data.readiness.warnings'), 'code'));
    }

    public function test_a_second_start_on_a_running_machine_returns_the_running_batch(): void
    {
        $this->supervisor();
        $item = $this->readyItem();

        $first = $this->postJson('/api/v1/production/shift-production-entries', $this->startPayload($item))
            ->assertOk();

        $response = $this->postJson('/api/v1/production/shift-production-entries', $this->startPayload($item))
            ->assertStatus(422)
            ->assertJsonPath('code', 'machine_busy');

        // The supervisor is told WHAT is running, not merely "no" — the
        // usual cause is a batch they cannot see (previous shift, someone
        // else's start).
        $this->assertSame($first->json('data.id'), $response->json('active_batch.id'));
        $this->assertSame($first->json('data.batch_number'), $response->json('active_batch.batch_number'));
        $this->assertSame('Machine 1', $response->json('active_batch.work_center'));
        $this->assertDatabaseCount('shift_production_entries', 1);
    }

    public function test_a_batch_running_from_a_previous_shift_and_date_still_blocks_and_is_named(): void
    {
        // The globally-active-batch case: a machine looks idle on today's
        // screen while the backend correctly considers it running.
        $this->supervisor();
        $item = $this->readyItem();
        $nightShift = Shift::create(['name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00']);

        $stale = ShiftProductionEntry::create([
            'shift_id' => $nightShift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $this->fgStore->id,
            'production_date' => '2026-07-01',
            'batch_number' => '20260701-M01-001',
            'batch_status' => BatchStatus::InProgress,
            'quantity_scrap' => '0',
        ]);

        $this->postJson('/api/v1/production/shift-production-entries', $this->startPayload($item))
            ->assertStatus(422)
            ->assertJsonPath('code', 'machine_busy')
            ->assertJsonPath('active_batch.id', $stale->id)
            ->assertJsonPath('active_batch.id', $stale->id);

        $this->assertStringStartsWith(
            '2026-07-01',
            $this->postJson('/api/v1/production/shift-production-entries', $this->startPayload($item))
                ->json('active_batch.production_date'),
        );
    }
}
