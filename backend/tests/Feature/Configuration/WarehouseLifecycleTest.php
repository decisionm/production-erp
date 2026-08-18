<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Services\FactoryWarehouseResolver;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * WAREHOUSE on the Configuration Lifecycle Contract (DEC-20260817-002):
 * Create -> View -> Edit -> Activate/Deactivate -> Safe Delete -> Audit.
 *
 * Warehouse is one of the two most-referenced masters in the schema —
 * fourteen foreign keys, one of them a CASCADE with no database backstop
 * (`stock_balances`) — plus three references no foreign key expresses at
 * all: the five app_settings keys that name a warehouse by id, the WIP
 * location resolved by CODE, and the Tally godown identity.
 *
 * DEC-20260817-001 IS NOT TOUCHED HERE. The duplicate FG / FG-STORE / RM /
 * RM-STORE rows are owner-gated and no test names them: the general rules
 * proved below — a Tally-linked warehouse is refused, a warehouse carrying
 * stock history is refused — are exactly what refuses that pair, since the
 * decision itself records that the Tally identity sits on one row of each
 * pair and the transactional history on the other.
 */
class WarehouseLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        $owner = User::factory()->create(['name' => 'Lifecycle Owner', 'is_active' => true]);
        $owner->assignRole('Administrator');
        Sanctum::actingAs($owner);
    }

    private function warehouse(string $code, array $attributes = []): Warehouse
    {
        return Warehouse::create([
            'code' => $code,
            'name' => 'Store '.$code,
            'is_active' => true,
            ...$attributes,
        ]);
    }

    private function item(): Item
    {
        return Item::create(['sku' => 'RES-1', 'name' => 'Relpet', 'uom' => 'Kgs', 'is_active' => true]);
    }

    // ---- Create / View / Edit ------------------------------------------

    public function test_a_warehouse_is_created_edited_and_read_back_with_its_abilities(): void
    {
        $created = $this->postJson('/api/v1/inventory/warehouses', [
            'code' => 'STORE-A', 'name' => 'Store A',
        ])->assertCreated()->json('data');

        $this->putJson("/api/v1/inventory/warehouses/{$created['id']}", ['name' => 'Store A (renamed)'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Store A (renamed)');

        $this->getJson("/api/v1/inventory/warehouses/{$created['id']}")
            ->assertOk()
            ->assertJsonPath('data.code', 'STORE-A')
            ->assertJsonPath('data.can.edit', true)
            ->assertJsonPath('data.can.archive', true)
            ->assertJsonPath('data.can.activate', false)
            // Nothing references it yet, and this user holds the tier.
            ->assertJsonPath('data.can.delete', true);
    }

    // ---- Activate / Deactivate -----------------------------------------

    /**
     * ARCHIVE IS THE ANSWER FOR A REFERENCED MASTER — that is the whole
     * point of the contract. It must not depend on the record being unused.
     */
    public function test_a_referenced_warehouse_can_still_be_archived_and_reactivated(): void
    {
        $warehouse = $this->warehouse('STORE-B');
        $item = $this->item();
        StockBalance::create([
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id,
            'quantity' => '25.0000', 'average_cost' => '0.0000',
        ]);

        $this->postJson("/api/v1/inventory/warehouses/{$warehouse->id}/archive", ['reason' => 'consolidating stores'])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.can.activate', true)
            ->assertJsonPath('data.can.archive', false)
            // Referenced, so still not deletable — Archive is not a delete.
            ->assertJsonPath('data.can.delete', false);

        // Archiving DELETED NOTHING and moved no stock.
        $this->assertDatabaseHas('stock_balances', [
            'warehouse_id' => $warehouse->id, 'quantity' => '25.0000',
        ]);

        $this->postJson("/api/v1/inventory/warehouses/{$warehouse->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    /**
     * The archived state the ENDPOINT writes is the same state the floor
     * already refuses to select (ActiveSelectionTest pins the full matrix) —
     * while every document that already names it still renders.
     */
    public function test_an_archived_warehouse_leaves_selection_but_history_still_renders(): void
    {
        $warehouse = $this->warehouse('STORE-C');
        $item = $this->item();

        app(StockMovementService::class)->recordReceipt(
            itemId: $item->id, warehouseId: $warehouse->id,
            quantity: '10', unitCost: '1.00', reference: 'history',
        );

        $this->postJson("/api/v1/inventory/warehouses/{$warehouse->id}/archive")->assertOk();

        $this->postJson('/api/v1/inventory/stock-movements/receipts', [
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id,
            'quantity' => '5', 'unit_cost' => '1.00', 'reference' => 'after',
        ])->assertStatus(422)->assertJsonValidationErrors(['warehouse_id']);

        $this->getJson('/api/v1/inventory/stock-movements?reference=history')
            ->assertOk()
            ->assertJsonPath('data.0.warehouse.name', $warehouse->name);
    }

    /** A stale Archive button on an already-archived row is a 422, not a 500. */
    public function test_archiving_an_already_archived_warehouse_is_refused_cleanly(): void
    {
        $warehouse = $this->warehouse('STORE-D', ['is_active' => false]);

        $this->postJson("/api/v1/inventory/warehouses/{$warehouse->id}/archive")
            ->assertStatus(422);
    }

    // ---- the code an archived record keeps ------------------------------

    /**
     * DEC-20260817-002 §2: an archived record RETAINS AND RESERVES its code.
     * The repo's uniqueness is global — soft-deleted rows included — and this
     * test exists so nobody "fixes" it into active-only uniqueness later.
     */
    public function test_an_archived_code_is_still_taken_by_both_kinds_of_archive(): void
    {
        $deactivated = $this->warehouse('STORE-E');
        $this->postJson("/api/v1/inventory/warehouses/{$deactivated->id}/archive")->assertOk();

        $this->postJson('/api/v1/inventory/warehouses', ['code' => 'STORE-E', 'name' => 'Another'])
            ->assertStatus(422)->assertJsonValidationErrors(['code']);

        // And the soft-deleted kind, which a naive unique rule would miss.
        $trashed = $this->warehouse('STORE-F');
        $trashed->delete();

        $this->postJson('/api/v1/inventory/warehouses', ['code' => 'STORE-F', 'name' => 'Another'])
            ->assertStatus(422)->assertJsonValidationErrors(['code']);
    }

    // ---- Safe delete ----------------------------------------------------

    /**
     * THE CASCADE ASYMMETRY, end to end. `stock_balances.warehouse_id` is
     * ON DELETE CASCADE: there is no database backstop, so this refusal is
     * the only thing standing between the delete and destroyed stock.
     */
    public function test_deleting_a_referenced_warehouse_is_refused_with_counts_and_the_children_survive(): void
    {
        $warehouse = $this->warehouse('STORE-G');
        $item = $this->item();
        StockBalance::create([
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id,
            'quantity' => '25.0000', 'average_cost' => '0.0000',
        ]);
        app(StockMovementService::class)->recordReceipt(
            itemId: $item->id, warehouseId: $warehouse->id,
            quantity: '10', unitCost: '1.00', reference: 'history',
        );

        $response = $this->deleteJson("/api/v1/inventory/warehouses/{$warehouse->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'configuration_in_use')
            ->assertJsonPath('alternative', 'archive');

        $blocking = collect($response->json('blocking'))->keyBy('code');

        $this->assertSame(1, $blocking['stock_balances']['count']);
        $this->assertSame('stock balance', $blocking['stock_balances']['label']);
        $this->assertGreaterThan(0, $blocking['stock_movements']['count']);
        $this->assertStringContainsString('Deactivate instead', $response->json('message'));

        // NOTHING was destroyed to make the check pass.
        $this->assertDatabaseHas('warehouses', ['id' => $warehouse->id]);
        $this->assertDatabaseHas('stock_balances', ['warehouse_id' => $warehouse->id]);
        $this->assertDatabaseHas('stock_movements', ['warehouse_id' => $warehouse->id]);
    }

    public function test_deleting_a_provably_unused_warehouse_succeeds_and_frees_its_code(): void
    {
        $warehouse = $this->warehouse('STORE-H');

        $this->deleteJson("/api/v1/inventory/warehouses/{$warehouse->id}")->assertNoContent();

        // A REAL delete: the row is gone, not soft-deleted, so the code is
        // genuinely released (DEC-20260817-002 §§1-2).
        $this->assertSame(0, Warehouse::withTrashed()->whereKey($warehouse->id)->count());

        $this->postJson('/api/v1/inventory/warehouses', ['code' => 'STORE-H', 'name' => 'Reused'])
            ->assertCreated();
    }

    /** An ARCHIVED warehouse that is provably unused is still deletable. */
    public function test_an_archived_unused_warehouse_can_still_be_deleted(): void
    {
        $warehouse = $this->warehouse('STORE-I');
        $this->postJson("/api/v1/inventory/warehouses/{$warehouse->id}/archive")->assertOk();

        $this->deleteJson("/api/v1/inventory/warehouses/{$warehouse->id}")->assertNoContent();
    }

    /**
     * `store_issue_lines` is the one child whose foreign keys are NO ACTION,
     * which SQLite may defer and MySQL treats as RESTRICT — so on the driver
     * the suite runs, the DECLARATION is doing all the work. Both columns are
     * OR-ed into one check, and each side is asserted, because a check that
     * counted only `from_warehouse_id` would say nothing about a warehouse
     * named solely as the destination.
     */
    public function test_a_store_issue_line_on_either_side_refuses_the_delete(): void
    {
        $from = $this->warehouse('STORE-Q');
        $to = $this->warehouse('STORE-R');
        $item = $this->item();

        $issueId = DB::table('store_issues')->insertGetId([
            'issue_number' => 'SI-0001', 'status' => 'issued',
            'issued_by' => User::query()->value('id'), 'issued_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('store_issue_lines')->insert([
            'store_issue_id' => $issueId, 'item_id' => $item->id,
            'from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id,
            'quantity_issued' => '5.0000', 'quantity_returned' => '0.0000',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([$from, $to] as $warehouse) {
            $response = $this->deleteJson("/api/v1/inventory/warehouses/{$warehouse->id}")->assertStatus(422);

            $this->assertSame(
                1,
                collect($response->json('blocking'))->firstWhere('code', 'store_issue_lines')['count'],
            );
        }

        $this->assertDatabaseHas('store_issue_lines', ['store_issue_id' => $issueId]);
    }

    // ---- the references no foreign key expresses ------------------------

    public function test_a_warehouse_a_production_setting_names_is_refused(): void
    {
        $warehouse = $this->warehouse('STORE-J');
        app(FactoryWarehouseResolver::class)->setPackingMaterialWarehouseId($warehouse->id);

        $response = $this->deleteJson("/api/v1/inventory/warehouses/{$warehouse->id}")->assertStatus(422);

        $this->assertContains(
            'production_warehouse_setting',
            array_column($response->json('blocking'), 'code'),
            'the packing-material store is one of the five settings keys the 01-Aug migration did not list',
        );
    }

    public function test_the_wip_location_resolved_by_code_is_refused(): void
    {
        $warehouse = $this->warehouse(ProductionWipLocationResolver::CANONICAL_CODE);

        $response = $this->deleteJson("/api/v1/inventory/warehouses/{$warehouse->id}")->assertStatus(422);

        $this->assertContains('wip_location_by_code', array_column($response->json('blocking'), 'code'));
    }

    /**
     * A godown Tally vouches for is Tally's identity, not ours to drop
     * (DEC-20260817-002 §4) — and nothing here reads or writes Tally to
     * decide that.
     */
    public function test_a_tally_linked_warehouse_is_refused_even_with_no_other_reference(): void
    {
        $warehouse = $this->warehouse('STORE-K', ['tally_guid' => 'gd-1234']);

        $response = $this->deleteJson("/api/v1/inventory/warehouses/{$warehouse->id}")->assertStatus(422);

        $this->assertContains('tally_identity', array_column($response->json('blocking'), 'code'));
        $this->assertDatabaseHas('warehouses', ['id' => $warehouse->id, 'tally_guid' => 'gd-1234']);
    }

    /**
     * `warehouses.parent_id` is a PLAIN NULLABLE COLUMN with no database
     * foreign key (the 23-Jul Tally migration says so), so a nested godown's
     * parent has no protection of any kind but this check.
     */
    public function test_a_warehouse_with_a_child_or_a_tally_named_child_is_refused(): void
    {
        $parent = $this->warehouse('STORE-L');
        $this->warehouse('STORE-M', ['parent_id' => $parent->id]);

        $byId = $this->deleteJson("/api/v1/inventory/warehouses/{$parent->id}")->assertStatus(422);
        $this->assertContains('child_warehouses', array_column($byId->json('blocking'), 'code'));

        // And the name-only half: HierarchyUpsert re-links on the next pull,
        // so an unresolved tally_parent_name is a live reference too.
        $unlinkedParent = $this->warehouse('STORE-N');
        $this->warehouse('STORE-O', ['tally_parent_name' => $unlinkedParent->name]);

        $byName = $this->deleteJson("/api/v1/inventory/warehouses/{$unlinkedParent->id}")->assertStatus(422);
        $this->assertContains(
            'child_warehouses_by_tally_name',
            array_column($byName->json('blocking'), 'code'),
        );
    }

    // ---- Audit ----------------------------------------------------------

    public function test_the_whole_lifecycle_is_written_to_the_configuration_audit_trail(): void
    {
        $created = $this->postJson('/api/v1/inventory/warehouses', ['code' => 'STORE-P', 'name' => 'Store P'])
            ->assertCreated()->json('data');

        $this->putJson("/api/v1/inventory/warehouses/{$created['id']}", ['name' => 'Store P renamed'])->assertOk();
        $this->postJson("/api/v1/inventory/warehouses/{$created['id']}/archive")->assertOk();

        $events = DB::table('activity_log')
            ->where('subject_type', Warehouse::class)
            ->where('subject_id', $created['id'])
            ->where('log_name', 'configuration')
            ->pluck('event')
            ->all();

        $this->assertContains('created', $events);
        $this->assertContains('updated', $events);

        $this->assertDatabaseHas('warehouses', [
            'id' => $created['id'],
            'created_by' => User::query()->value('id'),
            'updated_by' => User::query()->value('id'),
        ]);
    }
}
