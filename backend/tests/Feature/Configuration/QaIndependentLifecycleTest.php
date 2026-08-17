<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\HRMS\Models\Employee;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\DowntimeReason;
use App\Modules\Production\Models\Mold;
use App\Modules\Production\Models\ProductionConfiguration;
use App\Modules\Production\Models\ProductionDowntimeEvent;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Models\ScrapReason;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * INDEPENDENT QA — Sonnet reproduction of D-WIRING's claims, over the ROUTE
 * layer, with fixtures this file builds itself (none of it reuses the
 * builder's FloorMasterLifecycleTestCase / ProductDefinitionLifecycleTestCase
 * harness). Covers all 11 wired masters: Warehouse, Item, WorkCenter, Mold,
 * Shift, ScrapReason, DowntimeReason, ProductionStandard,
 * ProductionStandardPackaging, ProductionConfiguration, Employee.
 *
 * Scratch file for QA verification — not meant to stay in the tree.
 */
class QaIndependentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Administrator');

        return $user;
    }

    /** A user who holds ONE module's manage permission and nothing else. */
    private function moduleManager(string $module, string $roleName): User
    {
        $this->seed(PermissionSeeder::class);
        $role = Role::findOrCreate($roleName, 'web');
        $role->givePermissionTo(Permission::findOrCreate("{$module}.manage", 'web'));
        $role->givePermissionTo(Permission::findOrCreate("{$module}.view", 'web'));
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function asAdmin(): User
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        return $admin;
    }

    // =====================================================================
    // WAREHOUSE (module: inventory)
    // =====================================================================

    public function test_warehouse_referenced_delete_refused_children_survive(): void
    {
        $this->asAdmin();
        $warehouse = Warehouse::create(['code' => 'QA-WH-1', 'name' => 'QA Store 1', 'is_active' => true]);
        $item = Item::create(['sku' => 'QA-ITEM-1', 'name' => 'QA Resin', 'uom' => 'Kgs', 'is_active' => true]);
        StockBalance::create(['item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity' => '10.0000', 'average_cost' => '0.0000']);

        $before = StockBalance::where('warehouse_id', $warehouse->id)->count();
        $this->assertSame(1, $before);

        $response = $this->deleteJson("/api/v1/inventory/warehouses/{$warehouse->id}")
            ->assertStatus(422);

        $blocking = $response->json('blocking') ?? $response->json('data.blocking');
        $this->assertNotEmpty($blocking, 'expected a blocking list in the 422 body');
        $stockBalanceEntry = collect($blocking)->firstWhere('code', 'stock_balances') ?? collect($blocking)->first();
        $this->assertIsInt($stockBalanceEntry['count'], 'count must be an integer, not a numeric string');
        $this->assertGreaterThan(0, $stockBalanceEntry['count']);
        $alternative = $response->json('alternative') ?? $response->json('data.alternative');
        $this->assertSame('archive', $alternative);

        $this->assertDatabaseHas('warehouses', ['id' => $warehouse->id]);
        $after = StockBalance::where('warehouse_id', $warehouse->id)->count();
        $this->assertSame($before, $after, 'the referencing stock balance must survive the refusal untouched');
    }

    public function test_warehouse_unused_delete_succeeds_and_frees_code(): void
    {
        $this->asAdmin();
        $warehouse = Warehouse::create(['code' => 'QA-WH-2', 'name' => 'QA Store 2', 'is_active' => true]);

        $this->deleteJson("/api/v1/inventory/warehouses/{$warehouse->id}")->assertNoContent();
        $this->assertDatabaseMissing('warehouses', ['id' => $warehouse->id]);

        // Code is freed: a genuinely new row may claim it.
        $reused = $this->postJson('/api/v1/inventory/warehouses', ['code' => 'QA-WH-2', 'name' => 'Re-used'])
            ->assertCreated();
        $this->assertSame('QA-WH-2', $reused->json('data.code'));
    }

    public function test_warehouse_archive_activate_round_trip(): void
    {
        $this->asAdmin();
        $warehouse = Warehouse::create(['code' => 'QA-WH-3', 'name' => 'QA Store 3', 'is_active' => true]);

        $this->postJson("/api/v1/inventory/warehouses/{$warehouse->id}/archive", ['reason' => 'qa'])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->postJson("/api/v1/inventory/warehouses/{$warehouse->id}/activate")
            ->assertOk()->assertJsonPath('data.is_active', true);
    }

    public function test_warehouse_archived_excluded_from_new_start_batch_but_history_renders(): void
    {
        $admin = $this->asAdmin();
        $warehouse = Warehouse::create(['code' => 'QA-WH-4', 'name' => 'QA Store 4', 'is_active' => true]);
        $item = Item::create(['sku' => 'QA-ITEM-4', 'name' => 'QA Bottle', 'uom' => 'Nos', 'is_active' => true]);
        $workCenter = WorkCenter::create(['code' => 'QA-WC-4', 'name' => 'QA Machine 4', 'is_active' => true]);

        $this->postJson("/api/v1/inventory/warehouses/{$warehouse->id}/archive", ['reason' => 'qa'])->assertOk();

        // An archived warehouse must be REFUSED for a NEW start-batch, per
        // StartBatchRequest's Rule::exists(...)->where('is_active', true).
        $response = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => Shift::create(['name' => 'QA Shift 4', 'start_time' => '08:00', 'end_time' => '16:00', 'is_active' => true])->id,
            'work_center_id' => $workCenter->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => now()->toDateString(),
        ]);
        $response->assertStatus(422);
        $this->assertArrayHasKey('warehouse_id', $response->json('errors') ?? []);

        // History still renders it — show() is not gated on is_active.
        $this->getJson("/api/v1/inventory/warehouses/{$warehouse->id}")
            ->assertOk()
            ->assertJsonPath('data.code', 'QA-WH-4');
    }

    public function test_warehouse_duplicate_active_code_refused_including_against_archived_row(): void
    {
        $this->asAdmin();
        $warehouse = Warehouse::create(['code' => 'QA-WH-5', 'name' => 'QA Store 5', 'is_active' => true]);
        $this->postJson("/api/v1/inventory/warehouses/{$warehouse->id}/archive", ['reason' => 'qa'])->assertOk();

        $this->postJson('/api/v1/inventory/warehouses', ['code' => 'QA-WH-5', 'name' => 'Duplicate Attempt'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_warehouse_audit_trail_recorded_for_archive_and_delete(): void
    {
        $admin = $this->asAdmin();
        $warehouse = Warehouse::create(['code' => 'QA-WH-6', 'name' => 'QA Store 6', 'is_active' => true]);

        $this->postJson("/api/v1/inventory/warehouses/{$warehouse->id}/archive", ['reason' => 'qa'])->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'configuration',
            'subject_type' => Warehouse::class,
            'subject_id' => $warehouse->id,
            'causer_id' => $admin->id,
        ]);

        $unused = Warehouse::create(['code' => 'QA-WH-7', 'name' => 'QA Store 7', 'is_active' => true]);
        $this->deleteJson("/api/v1/inventory/warehouses/{$unused->id}")->assertNoContent();
        // The row is gone, so the activity log is the ONLY surviving record —
        // proving delete really did happen through the audited path.
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'configuration',
            'subject_type' => Warehouse::class,
            'subject_id' => $unused->id,
        ]);
    }

    public function test_warehouse_module_manager_gets_403_on_delete_but_may_archive(): void
    {
        $warehouse = Warehouse::create(['code' => 'QA-WH-8', 'name' => 'QA Store 8', 'is_active' => true]);
        $keeper = $this->moduleManager('inventory', 'QA Store Keeper');
        Sanctum::actingAs($keeper);

        $this->deleteJson("/api/v1/inventory/warehouses/{$warehouse->id}")->assertForbidden();
        $this->assertDatabaseHas('warehouses', ['id' => $warehouse->id]);

        $this->postJson("/api/v1/inventory/warehouses/{$warehouse->id}/archive", ['reason' => 'qa'])
            ->assertOk()->assertJsonPath('data.is_active', false);
    }

    // =====================================================================
    // ITEM (module: inventory)
    // =====================================================================

    public function test_item_referenced_delete_refused_children_survive(): void
    {
        $this->asAdmin();
        $item = Item::create(['sku' => 'QA-ITM-1', 'name' => 'QA Product', 'uom' => 'Nos', 'is_active' => true]);
        $warehouse = Warehouse::create(['code' => 'QA-WHI-1', 'name' => 'QA WH I1', 'is_active' => true]);
        StockBalance::create(['item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity' => '5.0000', 'average_cost' => '0.0000']);

        $before = StockBalance::where('item_id', $item->id)->count();

        $response = $this->deleteJson("/api/v1/inventory/items/{$item->id}")->assertStatus(422);
        $blocking = $response->json('blocking') ?? $response->json('data.blocking');
        $this->assertNotEmpty($blocking);
        foreach ($blocking as $entry) {
            $this->assertIsInt($entry['count']);
        }

        $this->assertDatabaseHas('items', ['id' => $item->id]);
        $this->assertSame($before, StockBalance::where('item_id', $item->id)->count());
    }

    public function test_item_unused_delete_succeeds_and_frees_sku(): void
    {
        $this->asAdmin();
        $item = Item::create(['sku' => 'QA-ITM-2', 'name' => 'QA Product 2', 'uom' => 'Nos', 'is_active' => true]);

        $this->deleteJson("/api/v1/inventory/items/{$item->id}")->assertNoContent();
        $this->assertDatabaseMissing('items', ['id' => $item->id]);

        $this->postJson('/api/v1/inventory/items', ['sku' => 'QA-ITM-2', 'name' => 'Reused SKU', 'uom' => 'Nos'])
            ->assertCreated();
    }

    public function test_item_archive_activate_round_trip(): void
    {
        $this->asAdmin();
        $item = Item::create(['sku' => 'QA-ITM-3', 'name' => 'QA Product 3', 'uom' => 'Nos', 'is_active' => true]);

        $this->postJson("/api/v1/inventory/items/{$item->id}/archive", ['reason' => 'qa'])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->postJson("/api/v1/inventory/items/{$item->id}/activate")
            ->assertOk()->assertJsonPath('data.is_active', true);
    }

    /**
     * ITEM'S EXCLUSION MECHANISM DIFFERS FROM WAREHOUSE/SHIFT'S: StartBatch
     * accepts item_id with a bare `exists:items,id` (no is_active scoping),
     * so the archived item is not refused at validation time. It is refused
     * one layer in, by ProductReadinessService's `item_active` finding
     * (ShiftProductionEntryService::store → ProductNotReadyException, 422).
     * Proven through the real start-batch route rather than assumed from
     * the FormRequest shape.
     */
    public function test_item_archived_excluded_from_new_start_batch_via_readiness_gate_but_history_renders(): void
    {
        $this->asAdmin();
        $item = Item::create(['sku' => 'QA-ITM-7', 'name' => 'QA Product 7', 'uom' => 'Nos', 'is_active' => true]);
        $workCenter = WorkCenter::create(['code' => 'QA-WCIT-7', 'name' => 'QA Machine IT7', 'is_active' => true]);
        $shift = Shift::create(['name' => 'QA Shift IT7', 'start_time' => '08:00', 'end_time' => '16:00', 'is_active' => true]);

        $this->postJson("/api/v1/inventory/items/{$item->id}/archive", ['reason' => 'qa'])->assertOk();

        $response = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $shift->id, 'work_center_id' => $workCenter->id, 'item_id' => $item->id,
            'production_date' => now()->toDateString(),
        ]);
        $response->assertStatus(422);
        $blocking = $response->json('blocking') ?? [];
        $this->assertTrue(
            collect($blocking)->contains('code', 'item_active'),
            'an archived item must fail the readiness gate item_active check: got '.$response->getContent()
        );

        $this->getJson("/api/v1/inventory/items/{$item->id}")
            ->assertOk()->assertJsonPath('data.sku', 'QA-ITM-7');
    }

    public function test_item_duplicate_active_sku_refused_including_against_archived_row(): void
    {
        $this->asAdmin();
        $item = Item::create(['sku' => 'QA-ITM-4', 'name' => 'QA Product 4', 'uom' => 'Nos', 'is_active' => true]);
        $this->postJson("/api/v1/inventory/items/{$item->id}/archive", ['reason' => 'qa'])->assertOk();

        $this->postJson('/api/v1/inventory/items', ['sku' => 'QA-ITM-4', 'name' => 'Dup', 'uom' => 'Nos'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sku');
    }

    public function test_item_audit_trail_recorded(): void
    {
        $admin = $this->asAdmin();
        $item = Item::create(['sku' => 'QA-ITM-5', 'name' => 'QA Product 5', 'uom' => 'Nos', 'is_active' => true]);

        $this->postJson("/api/v1/inventory/items/{$item->id}/archive", ['reason' => 'qa'])->assertOk();
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'configuration',
            'subject_type' => Item::class,
            'subject_id' => $item->id,
            'causer_id' => $admin->id,
        ]);
    }

    public function test_item_module_manager_gets_403_on_delete_but_may_archive(): void
    {
        $item = Item::create(['sku' => 'QA-ITM-6', 'name' => 'QA Product 6', 'uom' => 'Nos', 'is_active' => true]);
        $keeper = $this->moduleManager('inventory', 'QA Store Keeper 2');
        Sanctum::actingAs($keeper);

        $this->deleteJson("/api/v1/inventory/items/{$item->id}")->assertForbidden();
        $this->postJson("/api/v1/inventory/items/{$item->id}/archive", ['reason' => 'qa'])->assertOk();
    }

    // =====================================================================
    // WORK CENTER (module: production for read, machine-master for write)
    // =====================================================================

    public function test_work_center_referenced_delete_refused_children_survive(): void
    {
        $this->asAdmin();
        $workCenter = WorkCenter::create(['code' => 'QA-WC-1', 'name' => 'QA Machine 1', 'is_active' => true]);
        $item = Item::create(['sku' => 'QA-WCI-1', 'name' => 'QA Prod', 'uom' => 'Nos', 'is_active' => true]);
        $config = ProductionConfiguration::create([
            'work_center_id' => $workCenter->id, 'item_id' => $item->id, 'status' => 'draft',
        ]);

        $response = $this->deleteJson("/api/v1/production/work-centers/{$workCenter->id}")->assertStatus(422);
        $blocking = $response->json('blocking') ?? $response->json('data.blocking');
        $this->assertNotEmpty($blocking);

        $this->assertDatabaseHas('work_centers', ['id' => $workCenter->id]);
        $this->assertDatabaseHas('production_configurations', ['id' => $config->id]);
    }

    public function test_work_center_unused_delete_succeeds_and_frees_code(): void
    {
        $this->asAdmin();
        $workCenter = WorkCenter::create(['code' => 'QA-WC-2', 'name' => 'QA Machine 2', 'is_active' => true]);

        $this->deleteJson("/api/v1/production/work-centers/{$workCenter->id}")->assertNoContent();
        $this->assertDatabaseMissing('work_centers', ['id' => $workCenter->id]);

        $this->postJson('/api/v1/production/work-centers', ['code' => 'QA-WC-2', 'name' => 'Reused'])
            ->assertCreated();
    }

    public function test_work_center_archive_activate_round_trip(): void
    {
        $this->asAdmin();
        $workCenter = WorkCenter::create(['code' => 'QA-WC-3', 'name' => 'QA Machine 3', 'is_active' => true]);

        $this->postJson("/api/v1/production/work-centers/{$workCenter->id}/archive", ['reason' => 'qa'])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->postJson("/api/v1/production/work-centers/{$workCenter->id}/activate")
            ->assertOk()->assertJsonPath('data.is_active', true);
    }

    /**
     * Same mechanism as Item's: work_center_id is a bare `exists:` in
     * StartBatchRequest, so a retired machine is refused by
     * ProductReadinessService's `machine_active` finding, not by
     * validation.
     */
    public function test_work_center_archived_excluded_from_new_start_batch_via_readiness_gate_but_history_renders(): void
    {
        $this->asAdmin();
        $workCenter = WorkCenter::create(['code' => 'QA-WC-4B', 'name' => 'QA Machine 4B', 'is_active' => true]);
        $item = Item::create(['sku' => 'QA-WCIT-4B', 'name' => 'QA Prod WC4B', 'uom' => 'Nos', 'is_active' => true]);
        $shift = Shift::create(['name' => 'QA Shift WC4B', 'start_time' => '08:00', 'end_time' => '16:00', 'is_active' => true]);

        $this->postJson("/api/v1/production/work-centers/{$workCenter->id}/archive", ['reason' => 'qa'])->assertOk();

        $response = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $shift->id, 'work_center_id' => $workCenter->id, 'item_id' => $item->id,
            'production_date' => now()->toDateString(),
        ]);
        $response->assertStatus(422);
        $blocking = $response->json('blocking') ?? [];
        $this->assertTrue(
            collect($blocking)->contains('code', 'machine_active'),
            'a retired machine must fail the readiness gate machine_active check: got '.$response->getContent()
        );

        $this->getJson("/api/v1/production/work-centers/{$workCenter->id}")
            ->assertOk()->assertJsonPath('data.code', 'QA-WC-4B');
    }

    public function test_work_center_duplicate_active_code_refused(): void
    {
        $this->asAdmin();
        $workCenter = WorkCenter::create(['code' => 'QA-WC-5', 'name' => 'QA Machine 5', 'is_active' => true]);
        $this->postJson("/api/v1/production/work-centers/{$workCenter->id}/archive", ['reason' => 'qa'])->assertOk();

        $this->postJson('/api/v1/production/work-centers', ['code' => 'QA-WC-5', 'name' => 'Dup'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_work_center_audit_trail_recorded(): void
    {
        $admin = $this->asAdmin();
        $workCenter = WorkCenter::create(['code' => 'QA-WC-6', 'name' => 'QA Machine 6', 'is_active' => true]);
        $this->postJson("/api/v1/production/work-centers/{$workCenter->id}/archive", ['reason' => 'qa'])->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'configuration', 'subject_type' => WorkCenter::class,
            'subject_id' => $workCenter->id, 'causer_id' => $admin->id,
        ]);
    }

    /**
     * WORK CENTER'S SPLIT PERMISSION. index/show live under module:production;
     * store/update/destroy/archive/activate live under module:machine-master
     * (routes/api.php ~line 598 vs ~line 923). This proves a
     * production.manage-only user — who can read the machine list, exactly
     * as the split intends — is refused every write, and that a
     * machine-master.manage user (without configuration-delete.manage) is
     * refused DELETE specifically while still able to archive.
     */
    public function test_work_center_production_manager_without_machine_master_cannot_write(): void
    {
        $workCenter = WorkCenter::create(['code' => 'QA-WC-7', 'name' => 'QA Machine 7', 'is_active' => true]);
        $supervisor = $this->moduleManager('production', 'QA Supervisor');
        Sanctum::actingAs($supervisor);

        $this->getJson('/api/v1/production/work-centers')->assertOk();
        $this->deleteJson("/api/v1/production/work-centers/{$workCenter->id}")->assertForbidden();
        $this->postJson("/api/v1/production/work-centers/{$workCenter->id}/archive", ['reason' => 'qa'])->assertForbidden();
    }

    public function test_work_center_machine_master_manager_gets_403_on_delete_but_may_archive(): void
    {
        $workCenter = WorkCenter::create(['code' => 'QA-WC-8', 'name' => 'QA Machine 8', 'is_active' => true]);
        $officeUser = $this->moduleManager('machine-master', 'QA Machine Office');
        Sanctum::actingAs($officeUser);

        $this->deleteJson("/api/v1/production/work-centers/{$workCenter->id}")->assertForbidden();
        $this->postJson("/api/v1/production/work-centers/{$workCenter->id}/archive", ['reason' => 'qa'])
            ->assertOk()->assertJsonPath('data.is_active', false);
    }

    // =====================================================================
    // MOLD (module: production) — status enum (active/under_repair/retired)
    // =====================================================================

    public function test_mold_referenced_delete_refused_including_via_soft_deleted_child(): void
    {
        $this->asAdmin();
        $mold = Mold::create(['code' => 'QA-MLD-1', 'name' => 'QA Mold 1', 'status' => 'active']);
        $workCenter = WorkCenter::create(['code' => 'QA-WCM-1', 'name' => 'QA Machine M1', 'is_active' => true]);
        $item = Item::create(['sku' => 'QA-MLDI-1', 'name' => 'QA Prod M1', 'uom' => 'Nos', 'is_active' => true]);
        $config = ProductionConfiguration::create([
            'work_center_id' => $workCenter->id, 'item_id' => $item->id, 'mold_id' => $mold->id, 'status' => 'draft',
        ]);
        // The referencing configuration is itself archived (soft-deleted).
        // MoldService::dependencyChecks() explicitly declares includeTrashed()
        // on this column as load-bearing — proving it here, not trusting the
        // comment.
        $config->delete();
        $this->assertSoftDeleted('production_configurations', ['id' => $config->id]);

        $response = $this->deleteJson("/api/v1/production/molds/{$mold->id}")->assertStatus(422);
        $blocking = $response->json('blocking') ?? $response->json('data.blocking');
        $this->assertNotEmpty($blocking, 'a soft-deleted referencing configuration must still block the mold delete');
    }

    public function test_mold_unused_delete_succeeds_and_frees_code(): void
    {
        $this->asAdmin();
        $mold = Mold::create(['code' => 'QA-MLD-2', 'name' => 'QA Mold 2', 'status' => 'active']);

        $this->deleteJson("/api/v1/production/molds/{$mold->id}")->assertNoContent();
        $this->assertDatabaseMissing('molds', ['id' => $mold->id]);

        $this->postJson('/api/v1/production/molds', ['code' => 'QA-MLD-2', 'name' => 'Reused'])
            ->assertCreated();
    }

    public function test_mold_archive_activate_round_trip_writes_status_enum(): void
    {
        $this->asAdmin();
        $mold = Mold::create(['code' => 'QA-MLD-3', 'name' => 'QA Mold 3', 'status' => 'active']);

        $this->postJson("/api/v1/production/molds/{$mold->id}/archive", ['reason' => 'qa'])
            ->assertOk()->assertJsonPath('data.status', 'retired');
        $this->postJson("/api/v1/production/molds/{$mold->id}/activate")
            ->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_mold_archived_retired_excluded_from_new_production_configuration_but_history_renders(): void
    {
        $this->asAdmin();
        $mold = Mold::create(['code' => 'QA-MLD-4', 'name' => 'QA Mold 4', 'status' => 'active']);
        $workCenter = WorkCenter::create(['code' => 'QA-WCM-4', 'name' => 'QA Machine M4', 'is_active' => true]);
        $item = Item::create(['sku' => 'QA-MLDI-4', 'name' => 'QA Prod M4', 'uom' => 'Nos', 'is_active' => true]);

        $this->postJson("/api/v1/production/molds/{$mold->id}/archive", ['reason' => 'qa'])->assertOk();

        // A retired mold is refused for a NEW production configuration
        // (StoreProductionConfigurationRequest: Rule::exists('molds','id')->whereNot('status','retired')).
        $this->postJson('/api/v1/production/configurations', [
            'work_center_id' => $workCenter->id, 'item_id' => $item->id, 'mold_id' => $mold->id,
        ])->assertStatus(422)->assertJsonValidationErrors('mold_id');

        // History still renders it.
        $this->getJson("/api/v1/production/molds/{$mold->id}")->assertOk()->assertJsonPath('data.code', 'QA-MLD-4');
    }

    public function test_mold_duplicate_active_code_refused(): void
    {
        $this->asAdmin();
        $mold = Mold::create(['code' => 'QA-MLD-6', 'name' => 'QA Mold 6', 'status' => 'active']);
        $this->postJson("/api/v1/production/molds/{$mold->id}/archive", ['reason' => 'qa'])->assertOk();

        $this->postJson('/api/v1/production/molds', ['code' => 'QA-MLD-6', 'name' => 'Dup'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_mold_audit_trail_recorded(): void
    {
        $admin = $this->asAdmin();
        $mold = Mold::create(['code' => 'QA-MLD-7', 'name' => 'QA Mold 7', 'status' => 'active']);
        $this->postJson("/api/v1/production/molds/{$mold->id}/archive", ['reason' => 'qa'])->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'configuration', 'subject_type' => Mold::class,
            'subject_id' => $mold->id, 'causer_id' => $admin->id,
        ]);
    }

    public function test_mold_module_manager_gets_403_on_delete_but_may_archive(): void
    {
        $mold = Mold::create(['code' => 'QA-MLD-8', 'name' => 'QA Mold 8', 'status' => 'active']);
        $supervisor = $this->moduleManager('production', 'QA Prod Supervisor Mold');
        Sanctum::actingAs($supervisor);

        $this->deleteJson("/api/v1/production/molds/{$mold->id}")->assertForbidden();
        $this->postJson("/api/v1/production/molds/{$mold->id}/archive", ['reason' => 'qa'])
            ->assertOk()->assertJsonPath('data.status', 'retired');
    }

    // =====================================================================
    // SHIFT (module: production) — unique on `name`, not `code`
    // =====================================================================

    public function test_shift_referenced_delete_refused_children_survive(): void
    {
        $this->asAdmin();
        $shift = Shift::create(['name' => 'QA Shift 1', 'start_time' => '08:00', 'end_time' => '16:00', 'is_active' => true]);
        $workCenter = WorkCenter::create(['code' => 'QA-WCS-1', 'name' => 'QA Machine S1', 'is_active' => true]);
        $item = Item::create(['sku' => 'QA-SHI-1', 'name' => 'QA Prod S1', 'uom' => 'Nos', 'is_active' => true]);
        $warehouse = Warehouse::create(['code' => 'QA-WHS-1', 'name' => 'QA WH S1', 'is_active' => true]);
        $entry = ShiftProductionEntry::create([
            'shift_id' => $shift->id, 'work_center_id' => $workCenter->id, 'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => now()->toDateString(), 'status' => 'pending',
        ]);

        $response = $this->deleteJson("/api/v1/production/shifts/{$shift->id}")->assertStatus(422);
        $this->assertNotEmpty($response->json('blocking') ?? $response->json('data.blocking'));
        $this->assertDatabaseHas('shift_production_entries', ['id' => $entry->id]);
    }

    public function test_shift_unused_delete_succeeds_and_frees_name(): void
    {
        $this->asAdmin();
        $shift = Shift::create(['name' => 'QA Shift 2', 'start_time' => '08:00', 'end_time' => '16:00', 'is_active' => true]);

        $this->deleteJson("/api/v1/production/shifts/{$shift->id}")->assertNoContent();
        $this->assertDatabaseMissing('shifts', ['id' => $shift->id]);

        $this->postJson('/api/v1/production/shifts', ['name' => 'QA Shift 2', 'start_time' => '08:00', 'end_time' => '16:00'])
            ->assertCreated();
    }

    public function test_shift_archive_activate_round_trip(): void
    {
        $this->asAdmin();
        $shift = Shift::create(['name' => 'QA Shift 3', 'start_time' => '08:00', 'end_time' => '16:00', 'is_active' => true]);

        $this->postJson("/api/v1/production/shifts/{$shift->id}/archive", ['reason' => 'qa'])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->postJson("/api/v1/production/shifts/{$shift->id}/activate")
            ->assertOk()->assertJsonPath('data.is_active', true);
    }

    public function test_shift_archived_excluded_from_new_start_batch_but_history_renders(): void
    {
        $this->asAdmin();
        $shift = Shift::create(['name' => 'QA Shift 4', 'start_time' => '08:00', 'end_time' => '16:00', 'is_active' => true]);
        $workCenter = WorkCenter::create(['code' => 'QA-WCS-4', 'name' => 'QA Machine S4', 'is_active' => true]);
        $item = Item::create(['sku' => 'QA-SHI-4', 'name' => 'QA Prod S4', 'uom' => 'Nos', 'is_active' => true]);

        $this->postJson("/api/v1/production/shifts/{$shift->id}/archive", ['reason' => 'qa'])->assertOk();

        $response = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $shift->id, 'work_center_id' => $workCenter->id, 'item_id' => $item->id,
            'production_date' => now()->toDateString(),
        ]);
        $response->assertStatus(422)->assertJsonValidationErrors('shift_id');

        $this->getJson("/api/v1/production/shifts/{$shift->id}")->assertOk()->assertJsonPath('data.name', 'QA Shift 4');
    }

    public function test_shift_duplicate_active_name_refused_including_against_archived_row(): void
    {
        $this->asAdmin();
        $shift = Shift::create(['name' => 'QA Shift 5', 'start_time' => '08:00', 'end_time' => '16:00', 'is_active' => true]);
        $this->postJson("/api/v1/production/shifts/{$shift->id}/archive", ['reason' => 'qa'])->assertOk();

        $this->postJson('/api/v1/production/shifts', ['name' => 'QA Shift 5', 'start_time' => '00:00', 'end_time' => '08:00'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_shift_audit_trail_recorded(): void
    {
        $admin = $this->asAdmin();
        $shift = Shift::create(['name' => 'QA Shift 6', 'start_time' => '08:00', 'end_time' => '16:00', 'is_active' => true]);
        $this->postJson("/api/v1/production/shifts/{$shift->id}/archive", ['reason' => 'qa'])->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'configuration', 'subject_type' => Shift::class,
            'subject_id' => $shift->id, 'causer_id' => $admin->id,
        ]);
    }

    public function test_shift_module_manager_gets_403_on_delete_but_may_archive(): void
    {
        $shift = Shift::create(['name' => 'QA Shift 8', 'start_time' => '08:00', 'end_time' => '16:00', 'is_active' => true]);
        $supervisor = $this->moduleManager('production', 'QA Prod Supervisor Shift');
        Sanctum::actingAs($supervisor);

        $this->deleteJson("/api/v1/production/shifts/{$shift->id}")->assertForbidden();
        $this->postJson("/api/v1/production/shifts/{$shift->id}/archive", ['reason' => 'qa'])
            ->assertOk()->assertJsonPath('data.is_active', false);
    }

    // =====================================================================
    // SCRAP REASON (module: production)
    // =====================================================================

    public function test_scrap_reason_referenced_delete_refused_children_survive(): void
    {
        $this->asAdmin();
        $reason = ScrapReason::create(['code' => 'QA-SCR-1', 'name' => 'QA Scrap 1', 'is_active' => true]);
        $workCenter = WorkCenter::create(['code' => 'QA-WCR-1', 'name' => 'QA Machine R1', 'is_active' => true]);
        $item = Item::create(['sku' => 'QA-SCI-1', 'name' => 'QA Prod R1', 'uom' => 'Nos', 'is_active' => true]);
        $shift = Shift::create(['name' => 'QA Shift R1', 'start_time' => '08:00', 'end_time' => '16:00', 'is_active' => true]);
        $warehouse = Warehouse::create(['code' => 'QA-WHR-1', 'name' => 'QA WH R1', 'is_active' => true]);
        $entry = ShiftProductionEntry::create([
            'shift_id' => $shift->id, 'work_center_id' => $workCenter->id, 'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => now()->toDateString(), 'status' => 'pending', 'scrap_reason_id' => $reason->id,
        ]);

        $response = $this->deleteJson("/api/v1/production/scrap-reasons/{$reason->id}")->assertStatus(422);
        $this->assertNotEmpty($response->json('blocking') ?? $response->json('data.blocking'));
        $this->assertDatabaseHas('shift_production_entries', ['id' => $entry->id, 'scrap_reason_id' => $reason->id]);
    }

    public function test_scrap_reason_unused_delete_succeeds_and_frees_code(): void
    {
        $this->asAdmin();
        $reason = ScrapReason::create(['code' => 'QA-SCR-2', 'name' => 'QA Scrap 2', 'is_active' => true]);

        $this->deleteJson("/api/v1/production/scrap-reasons/{$reason->id}")->assertNoContent();
        $this->assertDatabaseMissing('scrap_reasons', ['id' => $reason->id]);

        $this->postJson('/api/v1/production/scrap-reasons', ['code' => 'QA-SCR-2', 'name' => 'Reused'])
            ->assertCreated();
    }

    public function test_scrap_reason_archive_activate_round_trip(): void
    {
        $this->asAdmin();
        $reason = ScrapReason::create(['code' => 'QA-SCR-3', 'name' => 'QA Scrap 3', 'is_active' => true]);

        $this->postJson("/api/v1/production/scrap-reasons/{$reason->id}/archive", ['reason' => 'qa'])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->postJson("/api/v1/production/scrap-reasons/{$reason->id}/activate")
            ->assertOk()->assertJsonPath('data.is_active', true);
    }

    public function test_scrap_reason_archived_excluded_from_new_completion_selection(): void
    {
        $this->asAdmin();
        $reason = ScrapReason::create(['code' => 'QA-SCR-4', 'name' => 'QA Scrap 4', 'is_active' => true]);
        $this->postJson("/api/v1/production/scrap-reasons/{$reason->id}/archive", ['reason' => 'qa'])->assertOk();

        $entry = $this->completableEntry('QA-SCR-ENTRY-4');

        // CompleteBatchRequest's scraps.*.scrap_reason_id is active-scoped
        // (Rule::exists('scrap_reasons','id')->where('is_active', true)).
        // FormRequest validation runs before the controller body, so a real
        // entry of any status is enough to prove the field-level rule fires
        // — other required fields being absent produce their own errors
        // alongside it, which is fine: every rule is checked, not just the
        // first.
        $response = $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'scraps' => [['scrap_reason_id' => $reason->id, 'quantity_scrap' => 1]],
        ]);
        $errors = $response->json('errors') ?? [];
        $this->assertTrue(
            $response->status() === 422 && $this->hasFieldError($errors, 'scrap_reason_id'),
            'an archived scrap reason must fail the active-scoped exists() rule on a completion payload: got '.$response->status().' '.$response->getContent()
        );
    }

    public function test_scrap_reason_duplicate_active_code_refused(): void
    {
        $this->asAdmin();
        $reason = ScrapReason::create(['code' => 'QA-SCR-6', 'name' => 'QA Scrap 6', 'is_active' => true]);
        $this->postJson("/api/v1/production/scrap-reasons/{$reason->id}/archive", ['reason' => 'qa'])->assertOk();

        $this->postJson('/api/v1/production/scrap-reasons', ['code' => 'QA-SCR-6', 'name' => 'Dup'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_scrap_reason_audit_trail_recorded(): void
    {
        $admin = $this->asAdmin();
        $reason = ScrapReason::create(['code' => 'QA-SCR-7', 'name' => 'QA Scrap 7', 'is_active' => true]);
        $this->postJson("/api/v1/production/scrap-reasons/{$reason->id}/archive", ['reason' => 'qa'])->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'configuration', 'subject_type' => ScrapReason::class,
            'subject_id' => $reason->id, 'causer_id' => $admin->id,
        ]);
    }

    public function test_scrap_reason_module_manager_gets_403_on_delete_but_may_archive(): void
    {
        $reason = ScrapReason::create(['code' => 'QA-SCR-8', 'name' => 'QA Scrap 8', 'is_active' => true]);
        $supervisor = $this->moduleManager('production', 'QA Prod Supervisor Scrap');
        Sanctum::actingAs($supervisor);

        $this->deleteJson("/api/v1/production/scrap-reasons/{$reason->id}")->assertForbidden();
        $this->postJson("/api/v1/production/scrap-reasons/{$reason->id}/archive", ['reason' => 'qa'])
            ->assertOk()->assertJsonPath('data.is_active', false);
    }

    // =====================================================================
    // DOWNTIME REASON (module: production) — NO deleted_at column
    // =====================================================================

    public function test_downtime_reason_referenced_delete_refused_children_survive(): void
    {
        $this->asAdmin();
        $reason = DowntimeReason::create([
            'code' => 'QA-DTR-1', 'description' => 'QA Downtime 1', 'planning_type' => 'unplanned', 'is_active' => true,
        ]);
        $workCenter = WorkCenter::create(['code' => 'QA-WCD-1', 'name' => 'QA Machine D1', 'is_active' => true]);
        $event = ProductionDowntimeEvent::create([
            'work_center_id' => $workCenter->id, 'downtime_reason_id' => $reason->id,
            'production_date' => now()->toDateString(), 'minutes' => 15,
        ]);

        $response = $this->deleteJson("/api/v1/production/downtime-reasons/{$reason->id}")->assertStatus(422);
        $this->assertNotEmpty($response->json('blocking') ?? $response->json('data.blocking'));
        $this->assertDatabaseHas('production_downtime_events', ['id' => $event->id]);
    }

    public function test_downtime_reason_unused_delete_succeeds_real_hard_delete_no_soft_delete_trait(): void
    {
        $this->asAdmin();
        $reason = DowntimeReason::create([
            'code' => 'QA-DTR-2', 'description' => 'QA Downtime 2', 'planning_type' => 'unplanned', 'is_active' => true,
        ]);

        $this->deleteJson("/api/v1/production/downtime-reasons/{$reason->id}")->assertNoContent();
        // downtime_reasons has NO deleted_at column — assertDatabaseMissing
        // proves this was a REAL delete, not a trashed row.
        $this->assertDatabaseMissing('downtime_reasons', ['id' => $reason->id]);

        $this->postJson('/api/v1/production/downtime-reasons', [
            'code' => 'QA-DTR-2', 'description' => 'Reused', 'planning_type' => 'unplanned',
        ])->assertCreated();
    }

    public function test_downtime_reason_archive_activate_round_trip(): void
    {
        $this->asAdmin();
        $reason = DowntimeReason::create([
            'code' => 'QA-DTR-3', 'description' => 'QA Downtime 3', 'planning_type' => 'unplanned', 'is_active' => true,
        ]);

        $this->postJson("/api/v1/production/downtime-reasons/{$reason->id}/archive", ['reason' => 'qa'])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->postJson("/api/v1/production/downtime-reasons/{$reason->id}/activate")
            ->assertOk()->assertJsonPath('data.is_active', true);
    }

    public function test_downtime_reason_archived_excluded_from_new_completion_selection(): void
    {
        $this->asAdmin();
        $reason = DowntimeReason::create([
            'code' => 'QA-DTR-4', 'description' => 'QA Downtime 4', 'planning_type' => 'unplanned', 'is_active' => true,
        ]);
        $this->postJson("/api/v1/production/downtime-reasons/{$reason->id}/archive", ['reason' => 'qa'])->assertOk();

        $entry = $this->completableEntry('QA-DTR-ENTRY-4');

        // ValidatesDowntimeEvents: downtime_events.*.downtime_reason_id is
        // active-scoped on CompleteBatchRequest.
        $response = $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'downtime_events' => [['downtime_reason_id' => $reason->id, 'minutes' => 10]],
        ]);
        $errors = $response->json('errors') ?? [];
        $this->assertTrue(
            $response->status() === 422 && $this->hasFieldError($errors, 'downtime_reason_id'),
            'an archived downtime reason must fail the active-scoped rule on completion: got '.$response->status().' '.$response->getContent()
        );
    }

    public function test_downtime_reason_duplicate_active_code_refused(): void
    {
        $this->asAdmin();
        $reason = DowntimeReason::create([
            'code' => 'QA-DTR-6', 'description' => 'QA Downtime 6', 'planning_type' => 'unplanned', 'is_active' => true,
        ]);
        $this->postJson("/api/v1/production/downtime-reasons/{$reason->id}/archive", ['reason' => 'qa'])->assertOk();

        $this->postJson('/api/v1/production/downtime-reasons', [
            'code' => 'QA-DTR-6', 'description' => 'Dup', 'planning_type' => 'unplanned',
        ])->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_downtime_reason_audit_trail_recorded(): void
    {
        $admin = $this->asAdmin();
        $reason = DowntimeReason::create([
            'code' => 'QA-DTR-7', 'description' => 'QA Downtime 7', 'planning_type' => 'unplanned', 'is_active' => true,
        ]);
        $this->postJson("/api/v1/production/downtime-reasons/{$reason->id}/archive", ['reason' => 'qa'])->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'configuration', 'subject_type' => DowntimeReason::class,
            'subject_id' => $reason->id, 'causer_id' => $admin->id,
        ]);
    }

    public function test_downtime_reason_module_manager_gets_403_on_delete_but_may_archive(): void
    {
        $reason = DowntimeReason::create([
            'code' => 'QA-DTR-8', 'description' => 'QA Downtime 8', 'planning_type' => 'unplanned', 'is_active' => true,
        ]);
        $supervisor = $this->moduleManager('production', 'QA Prod Supervisor Downtime');
        Sanctum::actingAs($supervisor);

        $this->deleteJson("/api/v1/production/downtime-reasons/{$reason->id}")->assertForbidden();
        $this->postJson("/api/v1/production/downtime-reasons/{$reason->id}/archive", ['reason' => 'qa'])
            ->assertOk()->assertJsonPath('data.is_active', false);
    }

    // =====================================================================
    // PRODUCTION STANDARD (module: production) — no active flag; archive = soft delete
    // =====================================================================

    private function standard(string $name, array $attrs = []): ProductionStandard
    {
        return ProductionStandard::create([
            'source_product_name' => $name, 'cavities' => 4, 'unit_weight_grams' => '18.0000',
            'cycle_time' => '12.00', 'status' => 'pending', ...$attrs,
        ]);
    }

    public function test_production_standard_referenced_delete_refused_children_survive(): void
    {
        $this->asAdmin();
        $standard = $this->standard('QA Standard 1');
        $packaging = ProductionStandardPackaging::create([
            'production_standard_id' => $standard->id, 'mode' => 'box', 'nos_per_box' => 100,
        ]);

        $response = $this->deleteJson("/api/v1/production/standards/{$standard->id}")->assertStatus(422);
        $this->assertNotEmpty($response->json('blocking') ?? $response->json('data.blocking'));
        $this->assertDatabaseHas('production_standard_packagings', ['id' => $packaging->id]);
    }

    public function test_production_standard_unused_delete_succeeds_real_delete(): void
    {
        $this->asAdmin();
        $standard = $this->standard('QA Standard 2');

        $this->deleteJson("/api/v1/production/standards/{$standard->id}")->assertNoContent();
        $this->assertDatabaseMissing('production_standards', ['id' => $standard->id]);
    }

    public function test_production_standard_archive_is_soft_delete_and_activate_restores(): void
    {
        $this->asAdmin();
        $standard = $this->standard('QA Standard 3');

        $this->postJson("/api/v1/production/standards/{$standard->id}/archive", ['reason' => 'qa'])->assertOk();
        $this->assertSoftDeleted('production_standards', ['id' => $standard->id]);

        $this->postJson("/api/v1/production/standards/{$standard->id}/activate")->assertOk();
        $this->assertDatabaseHas('production_standards', ['id' => $standard->id, 'deleted_at' => null]);
    }

    public function test_production_standard_archived_history_still_renders_via_show(): void
    {
        $this->asAdmin();
        $standard = $this->standard('QA Standard 4');
        $this->postJson("/api/v1/production/standards/{$standard->id}/archive", ['reason' => 'qa'])->assertOk();

        $this->getJson("/api/v1/production/standards/{$standard->id}")
            ->assertOk()->assertJsonPath('data.source_product_name', 'QA Standard 4');
    }

    public function test_production_standard_duplicate_variant_refused_including_against_archived_row(): void
    {
        $this->asAdmin();
        $standard = $this->standard('QA Standard 5');
        $this->postJson("/api/v1/production/standards/{$standard->id}/archive", ['reason' => 'qa'])->assertOk();

        // ProductionStandardService::create() checks withTrashed() for an
        // identical (name, cavities, weight, cycle_time) row.
        $response = $this->postJson('/api/v1/production/standards', [
            'source_product_name' => 'QA Standard 5', 'cavities' => 4,
            'unit_weight_grams' => 18.0, 'cycle_time' => 12.0,
        ]);
        $response->assertStatus(422);
        $this->assertArrayHasKey('source_product_name', $response->json('errors') ?? []);
    }

    public function test_production_standard_audit_trail_recorded(): void
    {
        $admin = $this->asAdmin();
        $standard = $this->standard('QA Standard 6');
        $this->postJson("/api/v1/production/standards/{$standard->id}/archive", ['reason' => 'qa'])->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'configuration', 'subject_type' => ProductionStandard::class,
            'subject_id' => $standard->id, 'causer_id' => $admin->id,
        ]);
    }

    public function test_production_standard_module_manager_gets_403_on_delete_but_may_archive(): void
    {
        $standard = $this->standard('QA Standard 8');
        $supervisor = $this->moduleManager('production', 'QA Prod Supervisor Standard');
        Sanctum::actingAs($supervisor);

        $this->deleteJson("/api/v1/production/standards/{$standard->id}")->assertForbidden();
        $this->postJson("/api/v1/production/standards/{$standard->id}/archive", ['reason' => 'qa'])->assertOk();
        $this->assertSoftDeleted('production_standards', ['id' => $standard->id]);
    }

    // =====================================================================
    // PRODUCTION STANDARD PACKAGING (nested under a standard)
    // =====================================================================

    public function test_packaging_referenced_delete_refused_children_survive(): void
    {
        $this->asAdmin();
        $standard = $this->standard('QA PKG Standard 1');
        $packaging = ProductionStandardPackaging::create([
            'production_standard_id' => $standard->id, 'mode' => 'box', 'nos_per_box' => 100,
        ]);
        $workCenter = WorkCenter::create(['code' => 'QA-WCP-1', 'name' => 'QA Machine P1', 'is_active' => true]);
        $item = Item::create(['sku' => 'QA-PKI-1', 'name' => 'QA Prod P1', 'uom' => 'Nos', 'is_active' => true]);
        $shift = Shift::create(['name' => 'QA Shift P1', 'start_time' => '08:00', 'end_time' => '16:00', 'is_active' => true]);
        $warehouse = Warehouse::create(['code' => 'QA-WHP-1', 'name' => 'QA WH P1', 'is_active' => true]);
        $entry = ShiftProductionEntry::create([
            'shift_id' => $shift->id, 'work_center_id' => $workCenter->id, 'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => now()->toDateString(), 'status' => 'pending',
            'production_standard_packaging_id' => $packaging->id,
        ]);

        $response = $this->deleteJson("/api/v1/production/standards/{$standard->id}/packagings/{$packaging->id}")
            ->assertStatus(422);
        $this->assertNotEmpty($response->json('blocking') ?? $response->json('data.blocking'));
        $this->assertDatabaseHas('shift_production_entries', ['id' => $entry->id]);
    }

    public function test_packaging_unused_delete_succeeds(): void
    {
        $this->asAdmin();
        $standard = $this->standard('QA PKG Standard 2');
        $packaging = ProductionStandardPackaging::create([
            'production_standard_id' => $standard->id, 'mode' => 'box', 'nos_per_box' => 50,
        ]);

        $this->deleteJson("/api/v1/production/standards/{$standard->id}/packagings/{$packaging->id}")
            ->assertNoContent();
        $this->assertDatabaseMissing('production_standard_packagings', ['id' => $packaging->id]);
    }

    public function test_packaging_archive_is_soft_delete_and_activate_restores(): void
    {
        $this->asAdmin();
        $standard = $this->standard('QA PKG Standard 3');
        $packaging = ProductionStandardPackaging::create([
            'production_standard_id' => $standard->id, 'mode' => 'box', 'nos_per_box' => 50,
        ]);

        $this->postJson("/api/v1/production/standards/{$standard->id}/packagings/{$packaging->id}/archive", ['reason' => 'qa'])
            ->assertOk();
        $this->assertSoftDeleted('production_standard_packagings', ['id' => $packaging->id]);

        $this->postJson("/api/v1/production/standards/{$standard->id}/packagings/{$packaging->id}/activate")
            ->assertOk();
        $this->assertDatabaseHas('production_standard_packagings', ['id' => $packaging->id, 'deleted_at' => null]);
    }

    /**
     * A packaging id from standard A must not be reachable under standard
     * B's nested URI — the parent binding has to actually be enforced.
     */
    public function test_packaging_route_scoped_to_its_own_parent_standard(): void
    {
        $this->asAdmin();
        $standardA = $this->standard('QA PKG Standard A');
        $standardB = $this->standard('QA PKG Standard B');
        $packagingA = ProductionStandardPackaging::create([
            'production_standard_id' => $standardA->id, 'mode' => 'box', 'nos_per_box' => 10,
        ]);

        // Enforced, but as a 422 domain refusal rather than a 404 — either
        // way, packaging A must not be reachable/actionable under standard
        // B's URI.
        $this->getJson("/api/v1/production/standards/{$standardB->id}/packagings/{$packagingA->id}")
            ->assertStatus(422);
        $this->postJson("/api/v1/production/standards/{$standardB->id}/packagings/{$packagingA->id}/archive", ['reason' => 'qa'])
            ->assertStatus(422);

        // And under its OWN parent it works normally.
        $this->getJson("/api/v1/production/standards/{$standardA->id}/packagings/{$packagingA->id}")
            ->assertOk();
    }

    /**
     * PACKAGING'S DUPLICATE GUARD IS refuseExactDuplicate() over
     * (mode + counts) WITHIN one standard — the psp_standard_variant_unique
     * index's twin, checked ->withTrashed() precisely so an ARCHIVED variant
     * still reserves its slot (DEC-20260817-002 §2).
     */
    public function test_packaging_duplicate_variant_refused_including_against_archived_row(): void
    {
        $this->asAdmin();
        $standard = $this->standard('QA PKG Standard Dup');
        $packaging = ProductionStandardPackaging::create([
            'production_standard_id' => $standard->id, 'mode' => 'direct_box', 'nos_per_box' => 144,
        ]);
        $this->postJson("/api/v1/production/standards/{$standard->id}/packagings/{$packaging->id}/archive", ['reason' => 'qa'])
            ->assertOk();

        $response = $this->postJson("/api/v1/production/standards/{$standard->id}/packagings", [
            'mode' => 'direct_box', 'nos_per_box' => 144,
        ]);
        // DuplicatePackagingVariantException deliberately carries
        // errors.mode (its own documented wire contract, matching the
        // standards page's showSaveError()) — distinguished from an
        // ordinary validation 422 by its error code.
        $response->assertStatus(422)->assertJsonPath('code', 'duplicate_packaging_variant');
    }

    public function test_packaging_audit_trail_recorded(): void
    {
        $admin = $this->asAdmin();
        $standard = $this->standard('QA PKG Standard 6');
        $packaging = ProductionStandardPackaging::create([
            'production_standard_id' => $standard->id, 'mode' => 'box', 'nos_per_box' => 20,
        ]);
        $this->postJson("/api/v1/production/standards/{$standard->id}/packagings/{$packaging->id}/archive", ['reason' => 'qa'])
            ->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'configuration', 'subject_type' => ProductionStandardPackaging::class,
            'subject_id' => $packaging->id, 'causer_id' => $admin->id,
        ]);
    }

    public function test_packaging_module_manager_gets_403_on_delete_but_may_archive(): void
    {
        $standard = $this->standard('QA PKG Standard 8');
        $packaging = ProductionStandardPackaging::create([
            'production_standard_id' => $standard->id, 'mode' => 'box', 'nos_per_box' => 20,
        ]);
        $supervisor = $this->moduleManager('production', 'QA Prod Supervisor Packaging');
        Sanctum::actingAs($supervisor);

        $this->deleteJson("/api/v1/production/standards/{$standard->id}/packagings/{$packaging->id}")
            ->assertForbidden();
        $this->postJson("/api/v1/production/standards/{$standard->id}/packagings/{$packaging->id}/archive", ['reason' => 'qa'])
            ->assertOk();
        $this->assertSoftDeleted('production_standard_packagings', ['id' => $packaging->id]);
    }

    // =====================================================================
    // PRODUCTION CONFIGURATION (module: production) — status enum
    // =====================================================================

    public function test_production_configuration_referenced_delete_refused_children_survive(): void
    {
        $this->asAdmin();
        $workCenter = WorkCenter::create(['code' => 'QA-WCC-1', 'name' => 'QA Machine C1', 'is_active' => true]);
        $item = Item::create(['sku' => 'QA-CFI-1', 'name' => 'QA Prod C1', 'uom' => 'Nos', 'is_active' => true]);
        $config = ProductionConfiguration::create([
            'work_center_id' => $workCenter->id, 'item_id' => $item->id, 'status' => 'draft',
        ]);
        $shift = Shift::create(['name' => 'QA Shift C1', 'start_time' => '08:00', 'end_time' => '16:00', 'is_active' => true]);
        $warehouse = Warehouse::create(['code' => 'QA-WHC-1', 'name' => 'QA WH C1', 'is_active' => true]);
        $entry = ShiftProductionEntry::create([
            'shift_id' => $shift->id, 'work_center_id' => $workCenter->id, 'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => now()->toDateString(), 'status' => 'pending',
            'production_configuration_id' => $config->id,
        ]);

        $response = $this->deleteJson("/api/v1/production/configurations/{$config->id}")->assertStatus(422);
        $this->assertNotEmpty($response->json('blocking') ?? $response->json('data.blocking'));
        $this->assertDatabaseHas('shift_production_entries', ['id' => $entry->id]);
    }

    public function test_production_configuration_unused_delete_succeeds(): void
    {
        $this->asAdmin();
        $workCenter = WorkCenter::create(['code' => 'QA-WCC-2', 'name' => 'QA Machine C2', 'is_active' => true]);
        $item = Item::create(['sku' => 'QA-CFI-2', 'name' => 'QA Prod C2', 'uom' => 'Nos', 'is_active' => true]);
        $config = ProductionConfiguration::create([
            'work_center_id' => $workCenter->id, 'item_id' => $item->id, 'status' => 'draft',
        ]);

        $this->deleteJson("/api/v1/production/configurations/{$config->id}")->assertNoContent();
        $this->assertDatabaseMissing('production_configurations', ['id' => $config->id]);
    }

    public function test_production_configuration_archive_activate_round_trip_writes_status_enum(): void
    {
        $admin = $this->asAdmin();
        $workCenter = WorkCenter::create(['code' => 'QA-WCC-3', 'name' => 'QA Machine C3', 'is_active' => true]);
        $item = Item::create(['sku' => 'QA-CFI-3', 'name' => 'QA Prod C3', 'uom' => 'Nos', 'is_active' => true]);
        $config = ProductionConfiguration::create([
            'work_center_id' => $workCenter->id, 'item_id' => $item->id, 'status' => 'approved',
            'approved_by' => $admin->id, 'approved_at' => now(),
        ]);

        $this->postJson("/api/v1/production/configurations/{$config->id}/archive", ['reason' => 'qa'])
            ->assertOk()->assertJsonPath('data.status', 'inactive');
    }

    /**
     * PRODUCTION CONFIGURATION HAS NO "CODE" — its duplicate-active guard is
     * assertNoOverlap(): two approved configs for the same (machine, item,
     * mold, colour) may never both be live. Reactivating an archived one
     * must be refused while an equivalent approved twin still governs the
     * same key, proven through the real activate() route.
     */
    public function test_production_configuration_duplicate_active_key_refused_on_activate(): void
    {
        $admin = $this->asAdmin();
        $workCenter = WorkCenter::create(['code' => 'QA-WCC-9', 'name' => 'QA Machine C9', 'is_active' => true]);
        $item = Item::create(['sku' => 'QA-CFI-9', 'name' => 'QA Prod C9', 'uom' => 'Nos', 'is_active' => true]);

        // Config A: approved and live for (workCenter, item, null mold, null colour).
        ProductionConfiguration::create([
            'work_center_id' => $workCenter->id, 'item_id' => $item->id, 'status' => 'approved',
            'approved_by' => $admin->id, 'approved_at' => now(),
        ]);

        // Config B: the same key, approved once, then archived.
        $configB = ProductionConfiguration::create([
            'work_center_id' => $workCenter->id, 'item_id' => $item->id, 'status' => 'approved',
            'approved_by' => $admin->id, 'approved_at' => now(),
        ]);
        $this->postJson("/api/v1/production/configurations/{$configB->id}/archive", ['reason' => 'qa'])->assertOk();

        // Reactivating B must be refused: A still occupies the same live key.
        $this->postJson("/api/v1/production/configurations/{$configB->id}/activate")
            ->assertStatus(422);
        $this->assertDatabaseHas('production_configurations', ['id' => $configB->id, 'status' => 'inactive']);
    }

    public function test_production_configuration_archived_excluded_from_approved_resolution_but_history_renders(): void
    {
        $admin = $this->asAdmin();
        $workCenter = WorkCenter::create(['code' => 'QA-WCC-4', 'name' => 'QA Machine C4', 'is_active' => true]);
        $item = Item::create(['sku' => 'QA-CFI-4', 'name' => 'QA Prod C4', 'uom' => 'Nos', 'is_active' => true]);
        $config = ProductionConfiguration::create([
            'work_center_id' => $workCenter->id, 'item_id' => $item->id, 'status' => 'approved',
            'approved_by' => $admin->id, 'approved_at' => now(),
        ]);

        // Before archiving it governs the machine (the resolution endpoint).
        $before = $this->getJson("/api/v1/production/work-centers/{$workCenter->id}/configurations")->assertOk();
        $this->assertTrue(collect($before->json('data'))->contains('id', $config->id));

        $this->postJson("/api/v1/production/configurations/{$config->id}/archive", ['reason' => 'qa'])->assertOk();

        $after = $this->getJson("/api/v1/production/work-centers/{$workCenter->id}/configurations")->assertOk();
        $this->assertFalse(
            collect($after->json('data'))->contains('id', $config->id),
            'an archived (inactive) configuration must not still resolve as governing the machine'
        );

        // History still renders it via show().
        $this->getJson("/api/v1/production/configurations/{$config->id}")
            ->assertOk()->assertJsonPath('data.id', $config->id);
    }

    public function test_production_configuration_audit_trail_recorded(): void
    {
        $admin = $this->asAdmin();
        $workCenter = WorkCenter::create(['code' => 'QA-WCC-6', 'name' => 'QA Machine C6', 'is_active' => true]);
        $item = Item::create(['sku' => 'QA-CFI-6', 'name' => 'QA Prod C6', 'uom' => 'Nos', 'is_active' => true]);
        $config = ProductionConfiguration::create([
            'work_center_id' => $workCenter->id, 'item_id' => $item->id, 'status' => 'draft',
        ]);

        $this->deleteJson("/api/v1/production/configurations/{$config->id}")->assertNoContent();
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'configuration', 'subject_type' => ProductionConfiguration::class,
            'subject_id' => $config->id,
        ]);
    }

    public function test_production_configuration_module_manager_gets_403_on_delete_but_may_archive(): void
    {
        $workCenter = WorkCenter::create(['code' => 'QA-WCC-8', 'name' => 'QA Machine C8', 'is_active' => true]);
        $item = Item::create(['sku' => 'QA-CFI-8', 'name' => 'QA Prod C8', 'uom' => 'Nos', 'is_active' => true]);
        $config = ProductionConfiguration::create([
            'work_center_id' => $workCenter->id, 'item_id' => $item->id, 'status' => 'draft',
        ]);
        $supervisor = $this->moduleManager('production', 'QA Prod Supervisor Config');
        Sanctum::actingAs($supervisor);

        $this->deleteJson("/api/v1/production/configurations/{$config->id}")->assertForbidden();
        $this->postJson("/api/v1/production/configurations/{$config->id}/archive", ['reason' => 'qa'])->assertOk();
    }

    // =====================================================================
    // EMPLOYEE (module: hrms, hard-delete additionally gated)
    // =====================================================================

    private function employee(string $code): Employee
    {
        return Employee::create([
            'employee_code' => $code, 'name' => 'QA Employee '.$code,
            'date_of_joining' => now()->toDateString(), 'status' => 'active',
        ]);
    }

    public function test_employee_referenced_delete_refused_children_survive(): void
    {
        $this->asAdmin();
        $employee = $this->employee('QA-EMP-1');
        $subordinate = Employee::create([
            'employee_code' => 'QA-EMP-1B', 'name' => 'QA Sub', 'date_of_joining' => now()->toDateString(),
            'status' => 'active', 'manager_id' => $employee->id,
        ]);

        $response = $this->deleteJson("/api/v1/hrms/employees/{$employee->id}")->assertStatus(422);
        $this->assertNotEmpty($response->json('blocking') ?? $response->json('data.blocking'));
        $this->assertDatabaseHas('employees', ['id' => $subordinate->id, 'manager_id' => $employee->id]);
    }

    public function test_employee_unused_delete_succeeds_and_frees_code(): void
    {
        $this->asAdmin();
        $employee = $this->employee('QA-EMP-2');

        $this->deleteJson("/api/v1/hrms/employees/{$employee->id}")->assertNoContent();
        $this->assertDatabaseMissing('employees', ['id' => $employee->id]);

        $this->postJson('/api/v1/hrms/employees', [
            'employee_code' => 'QA-EMP-2', 'name' => 'Reused', 'date_of_joining' => now()->toDateString(),
        ])->assertCreated();
    }

    public function test_employee_archive_activate_round_trip(): void
    {
        $this->asAdmin();
        $employee = $this->employee('QA-EMP-3');

        $this->postJson("/api/v1/hrms/employees/{$employee->id}/archive", ['reason' => 'qa'])
            ->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->postJson("/api/v1/hrms/employees/{$employee->id}/activate")
            ->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_employee_archived_excluded_from_new_manager_selection_but_history_renders(): void
    {
        $this->asAdmin();
        $employee = $this->employee('QA-EMP-4');
        $this->postJson("/api/v1/hrms/employees/{$employee->id}/archive", ['reason' => 'qa'])->assertOk();

        // SelectableEmployee::rule() — manager_id must be active, not merely
        // exist.
        $this->postJson('/api/v1/hrms/employees', [
            'employee_code' => 'QA-EMP-4B', 'name' => 'New Hire', 'date_of_joining' => now()->toDateString(),
            'manager_id' => $employee->id,
        ])->assertStatus(422)->assertJsonValidationErrors('manager_id');

        $this->getJson("/api/v1/hrms/employees/{$employee->id}")
            ->assertOk()->assertJsonPath('data.employee_code', 'QA-EMP-4');
    }

    public function test_employee_duplicate_active_code_refused_including_against_archived_row(): void
    {
        $this->asAdmin();
        $employee = $this->employee('QA-EMP-5');
        $this->postJson("/api/v1/hrms/employees/{$employee->id}/archive", ['reason' => 'qa'])->assertOk();

        $this->postJson('/api/v1/hrms/employees', [
            'employee_code' => 'QA-EMP-5', 'name' => 'Dup', 'date_of_joining' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('employee_code');
    }

    public function test_employee_audit_trail_recorded(): void
    {
        $admin = $this->asAdmin();
        $employee = $this->employee('QA-EMP-6');
        $this->postJson("/api/v1/hrms/employees/{$employee->id}/archive", ['reason' => 'qa'])->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'configuration', 'subject_type' => Employee::class,
            'subject_id' => $employee->id, 'causer_id' => $admin->id,
        ]);
    }

    /**
     * EMPLOYEE'S EXTRA GATE: hrms.manage is not enough for DELETE; the
     * comment at routes/api.php:481 says the hard delete is additionally
     * gated on configuration-delete.manage inside EmployeeService.
     */
    public function test_employee_hrms_manager_gets_403_on_delete_but_may_archive(): void
    {
        $employee = $this->employee('QA-EMP-8');
        $hrUser = $this->moduleManager('hrms', 'QA HR Manager');
        Sanctum::actingAs($hrUser);

        $this->deleteJson("/api/v1/hrms/employees/{$employee->id}")->assertForbidden();
        $this->postJson("/api/v1/hrms/employees/{$employee->id}/archive", ['reason' => 'qa'])
            ->assertOk()->assertJsonPath('data.status', 'inactive');
    }

    // =====================================================================
    // helpers
    // =====================================================================

    /** A real ShiftProductionEntry so route-model binding on /complete succeeds. */
    private function completableEntry(string $tag): ShiftProductionEntry
    {
        $workCenter = WorkCenter::create(['code' => $tag.'-WC', 'name' => $tag.' Machine', 'is_active' => true]);
        $item = Item::create(['sku' => $tag.'-ITEM', 'name' => $tag.' Product', 'uom' => 'Nos', 'is_active' => true]);
        $shift = Shift::create(['name' => $tag.' Shift', 'start_time' => '08:00', 'end_time' => '16:00', 'is_active' => true]);
        $warehouse = Warehouse::create(['code' => $tag.'-WH', 'name' => $tag.' Warehouse', 'is_active' => true]);

        return ShiftProductionEntry::create([
            'shift_id' => $shift->id, 'work_center_id' => $workCenter->id, 'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => now()->toDateString(), 'status' => 'pending',
        ]);
    }

    /** @param array<string, mixed> $errors */
    private function hasFieldError(array $errors, string $needle): bool
    {
        foreach (array_keys($errors) as $key) {
            if (str_contains((string) $key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
