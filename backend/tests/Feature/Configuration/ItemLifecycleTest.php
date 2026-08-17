<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Models\MasterbatchDosing;
use App\Modules\Production\Services\RunMaterialSuggestionService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ITEM — the SKU master, materials and finished products alike — on the
 * Configuration Lifecycle Contract (DEC-20260817-002).
 *
 * The most-referenced table in this schema: forty-three foreign-key columns
 * across thirty-nine tables, SIX of them ON DELETE CASCADE with no database
 * backstop whatsoever. A mis-scoped delete would take a product's entire
 * production recipe — its stock balances, its masterbatch dosings, its
 * packing mappings, its machine configurations — silently. So the refusal
 * tests below assert not only the 422 but that every child row SURVIVED it.
 *
 * Three references have no foreign key at all: the `masterbatch_colour_map`
 * factory setting (item ids inside a JSON value), the scrap item named by
 * SKU-or-exact-name in configuration, and the Tally stock item identity.
 */
class ItemLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        $owner = User::factory()->create(['name' => 'Lifecycle Owner', 'is_active' => true]);
        $owner->assignRole('Administrator');
        Sanctum::actingAs($owner);

        // The scrap item is named by SKU-or-name in config and defaults to a
        // real spelling; blanked here so it cannot silently block an unrelated
        // fixture. Its own test sets it deliberately.
        config()->set('production.scrap.rejected_item_sku', '');
    }

    private function item(string $sku, array $attributes = []): Item
    {
        return Item::create([
            'sku' => $sku,
            'name' => 'Item '.$sku,
            'uom' => 'Nos',
            'is_active' => true,
            ...$attributes,
        ]);
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::create(['code' => 'ST-1', 'name' => 'Store', 'is_active' => true]);
    }

    // ---- Create / View / Edit ------------------------------------------

    public function test_an_item_is_created_edited_and_read_back_with_its_abilities(): void
    {
        $created = $this->postJson('/api/v1/inventory/items', [
            'sku' => 'SKU-A', 'name' => 'Bottle A', 'uom' => 'Nos',
        ])->assertCreated()->json('data');

        $this->putJson("/api/v1/inventory/items/{$created['id']}", ['name' => 'Bottle A (renamed)'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Bottle A (renamed)');

        $this->getJson("/api/v1/inventory/items/{$created['id']}")
            ->assertOk()
            ->assertJsonPath('data.can.edit', true)
            ->assertJsonPath('data.can.archive', true)
            ->assertJsonPath('data.can.activate', false)
            ->assertJsonPath('data.can.delete', true);

        // A list never pays forty COUNTs a row for an answer nobody asked
        // for. The key must be PRESENT and null — assertJsonPath(null) also
        // passes for an absent key, which would make this vacuous.
        $list = $this->getJson('/api/v1/inventory/items')
            ->assertOk()
            ->assertJsonStructure(['data' => [['can' => ['edit', 'activate', 'archive', 'delete']]]])
            ->assertJsonPath('data.0.can.delete', null);

        $this->assertArrayHasKey('delete', $list->json('data.0.can'));
    }

    // ---- Activate / Deactivate -----------------------------------------

    public function test_a_referenced_item_can_still_be_archived_and_reactivated(): void
    {
        $item = $this->item('SKU-B');
        StockBalance::create([
            'item_id' => $item->id, 'warehouse_id' => $this->warehouse()->id,
            'quantity' => '40.0000', 'average_cost' => '0.0000',
        ]);

        $this->postJson("/api/v1/inventory/items/{$item->id}/archive", ['reason' => 'discontinued'])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.can.activate', true)
            ->assertJsonPath('data.can.delete', false);

        $this->assertDatabaseHas('stock_balances', ['item_id' => $item->id, 'quantity' => '40.0000']);

        $this->postJson("/api/v1/inventory/items/{$item->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_an_archived_item_leaves_selection_but_history_still_renders(): void
    {
        $item = $this->item('SKU-C', ['uom' => 'Kgs']);
        $warehouse = $this->warehouse();

        app(StockMovementService::class)->recordReceipt(
            itemId: $item->id, warehouseId: $warehouse->id,
            quantity: '10', unitCost: '1.00', reference: 'history',
        );

        $this->postJson("/api/v1/inventory/items/{$item->id}/archive")->assertOk();

        $this->postJson('/api/v1/inventory/stock-movements/receipts', [
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id,
            'quantity' => '5', 'unit_cost' => '1.00', 'reference' => 'after',
        ])->assertStatus(422)->assertJsonValidationErrors(['item_id']);

        $this->getJson('/api/v1/inventory/stock-movements?reference=history')
            ->assertOk()
            ->assertJsonPath('data.0.item.name', $item->name);
    }

    // ---- the code an archived record keeps ------------------------------

    public function test_an_archived_sku_is_still_taken_by_both_kinds_of_archive(): void
    {
        $deactivated = $this->item('SKU-D');
        $this->postJson("/api/v1/inventory/items/{$deactivated->id}/archive")->assertOk();

        $this->postJson('/api/v1/inventory/items', ['sku' => 'SKU-D', 'name' => 'Another', 'uom' => 'Nos'])
            ->assertStatus(422)->assertJsonValidationErrors(['sku']);

        $trashed = $this->item('SKU-E');
        $trashed->delete();

        $this->postJson('/api/v1/inventory/items', ['sku' => 'SKU-E', 'name' => 'Another', 'uom' => 'Nos'])
            ->assertStatus(422)->assertJsonValidationErrors(['sku']);
    }

    // ---- Safe delete ----------------------------------------------------

    /**
     * THE ONE THAT MATTERS. Two CASCADE children and one RESTRICT child, all
     * of them the product's own configuration, and every one still standing
     * after the refusal. `masterbatch_dosings` cascades through TWO columns,
     * so the product and the masterbatch are each asserted separately —
     * counting one proves nothing about the other.
     */
    public function test_deleting_a_referenced_item_is_refused_with_counts_and_every_child_survives(): void
    {
        $product = $this->item('SKU-F');
        $masterbatch = $this->item('SKU-G', ['uom' => 'Kgs']);
        $warehouse = $this->warehouse();

        StockBalance::create([
            'item_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'quantity' => '12.0000', 'average_cost' => '0.0000',
        ]);
        $dosing = MasterbatchDosing::create([
            'masterbatch_item_id' => $masterbatch->id,
            'product_item_id' => $product->id,
            'grams_per_bottle' => '0.2500',
        ]);
        app(StockMovementService::class)->recordReceipt(
            itemId: $product->id, warehouseId: $warehouse->id,
            quantity: '10', unitCost: '1.00', reference: 'history',
        );

        $response = $this->deleteJson("/api/v1/inventory/items/{$product->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'configuration_in_use')
            ->assertJsonPath('alternative', 'archive');

        $blocking = collect($response->json('blocking'))->keyBy('code');
        $this->assertSame(1, $blocking['stock_balances']['count']);
        $this->assertSame(1, $blocking['masterbatch_dosings']['count']);
        $this->assertGreaterThan(0, $blocking['stock_movements']['count']);

        // Nothing was destroyed to make a check pass.
        $this->assertDatabaseHas('items', ['id' => $product->id]);
        $this->assertDatabaseHas('stock_balances', ['item_id' => $product->id]);
        $this->assertDatabaseHas('masterbatch_dosings', ['id' => $dosing->id]);
        $this->assertDatabaseHas('stock_movements', ['item_id' => $product->id]);

        // The SECOND cascading column of the same table: the masterbatch is a
        // different item and is blocked by the same row.
        $second = $this->deleteJson("/api/v1/inventory/items/{$masterbatch->id}")->assertStatus(422);
        $this->assertSame(
            1,
            collect($second->json('blocking'))->firstWhere('code', 'masterbatch_dosings')['count'],
        );
        $this->assertDatabaseHas('masterbatch_dosings', ['id' => $dosing->id]);
    }

    /** An ARCHIVED (soft-deleted) child still blocks — it is still a physical row. */
    public function test_an_archived_dosing_still_blocks_the_delete(): void
    {
        $product = $this->item('SKU-H');
        $masterbatch = $this->item('SKU-I', ['uom' => 'Kgs']);

        $dosing = MasterbatchDosing::create([
            'masterbatch_item_id' => $masterbatch->id,
            'product_item_id' => $product->id,
            'grams_per_bottle' => '0.2500',
        ]);
        $dosing->delete();

        $this->deleteJson("/api/v1/inventory/items/{$product->id}")->assertStatus(422);
        $this->assertDatabaseHas('masterbatch_dosings', ['id' => $dosing->id]);
    }

    public function test_deleting_a_provably_unused_item_succeeds_and_frees_its_sku(): void
    {
        $item = $this->item('SKU-J');

        $this->deleteJson("/api/v1/inventory/items/{$item->id}")->assertNoContent();

        $this->assertSame(0, Item::withTrashed()->whereKey($item->id)->count());

        $this->postJson('/api/v1/inventory/items', ['sku' => 'SKU-J', 'name' => 'Reused', 'uom' => 'Nos'])
            ->assertCreated();
    }

    // ---- the references no foreign key expresses ------------------------

    /**
     * The colour -> masterbatch map lives in a `json` factory_settings value.
     * Nothing in the database connects it to `items`, so without this check
     * the delete succeeds and the map is left naming an id that is gone.
     *
     * EVERY row of the key counts, including a superseded one: the active
     * filter RunMaterialSuggestionService applies is a suggestion rule, not a
     * dependency rule, and a past shift's prefill has to stay explainable.
     */
    public function test_an_item_named_by_the_masterbatch_colour_map_is_refused_even_when_superseded(): void
    {
        $masterbatch = $this->item('SKU-K', ['uom' => 'Kgs', 'colour' => 'Amber']);

        DB::table('factory_settings')->insert([
            'key' => RunMaterialSuggestionService::COLOUR_MAP_KEY,
            'value' => json_encode(['Amber' => $masterbatch->id]),
            'data_type' => 'json',
            'scope' => 'production',
            'label' => 'Colour map (superseded)',
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->deleteJson("/api/v1/inventory/items/{$masterbatch->id}")->assertStatus(422);

        $this->assertContains('masterbatch_colour_map', array_column($response->json('blocking'), 'code'));
        $this->assertDatabaseHas('items', ['id' => $masterbatch->id]);
    }

    /**
     * The scrap item is named in configuration by SKU or EXACT NAME. Matched
     * on the raw columns, so an item that is already archived — the one case
     * a resolver would silently skip — is still caught.
     */
    public function test_the_configured_scrap_item_is_refused_even_when_already_archived(): void
    {
        $scrap = $this->item('PET-SCRAP', ['uom' => 'Kgs', 'is_active' => false]);
        config()->set('production.scrap.rejected_item_sku', 'PET-SCRAP');

        $response = $this->deleteJson("/api/v1/inventory/items/{$scrap->id}")->assertStatus(422);

        $this->assertContains('scrap_item_setting', array_column($response->json('blocking'), 'code'));
    }

    /**
     * A Tally-sourced item's GUID is the mapping every past voucher line was
     * posted against (DEC-20260817-002 §4). Nothing here reads or writes
     * Tally to decide it.
     */
    public function test_a_tally_sourced_item_is_refused_even_with_no_other_reference(): void
    {
        $item = $this->item('SKU-L', ['tally_stock_item_guid' => 'si-9999']);

        $response = $this->deleteJson("/api/v1/inventory/items/{$item->id}")->assertStatus(422);

        $this->assertContains('tally_identity', array_column($response->json('blocking'), 'code'));
        $this->assertDatabaseHas('items', ['id' => $item->id, 'tally_stock_item_guid' => 'si-9999']);
    }

    // ---- Audit ----------------------------------------------------------

    public function test_the_whole_lifecycle_is_written_to_the_configuration_audit_trail(): void
    {
        $created = $this->postJson('/api/v1/inventory/items', ['sku' => 'SKU-M', 'name' => 'Bottle M', 'uom' => 'Nos'])
            ->assertCreated()->json('data');

        $this->putJson("/api/v1/inventory/items/{$created['id']}", ['name' => 'Bottle M renamed'])->assertOk();
        $this->postJson("/api/v1/inventory/items/{$created['id']}/archive")->assertOk();

        $events = DB::table('activity_log')
            ->where('subject_type', Item::class)
            ->where('subject_id', $created['id'])
            ->where('log_name', 'configuration')
            ->pluck('event')
            ->all();

        $this->assertContains('created', $events);
        $this->assertContains('updated', $events);

        $this->assertDatabaseHas('items', [
            'id' => $created['id'],
            'created_by' => User::query()->value('id'),
            'updated_by' => User::query()->value('id'),
        ]);
    }
}
