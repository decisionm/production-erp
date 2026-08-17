<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\ItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * items.sku_provisional — Phase 5 (P5-02).
 *
 * Every NEW item the masters pull creates arrives with a SKU seeded from its
 * Tally name (ItemService::uniqueSkuFrom). That SKU is a placeholder, not a
 * decision — the SKU format programme is the owner's and is HELD — so the
 * row now SAYS so, and stops saying so the moment a person types a SKU of
 * their own through the existing item update. No format is invented here:
 * the flag records provenance, nothing else.
 */
class ProvisionalSkuTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsInventoryManager(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('inventory.view', 'web');
        Permission::findOrCreate('inventory.manage', 'web');
        $user->givePermissionTo(['inventory.view', 'inventory.manage']);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_the_masters_pull_create_path_marks_the_name_derived_sku_provisional(): void
    {
        $result = app(ItemService::class)->upsertFromTally([
            'guid' => 'itm-new-1', 'name' => 'B.90ml Rib Pet Bottle Clear', 'base_unit' => 'Nos',
        ]);

        $this->assertTrue($result['created']);
        $item = $result['item']->fresh();
        // The SKU is the name (the seed rule, unchanged) — and the row says
        // it was seeded, not chosen.
        $this->assertSame('B.90ml Rib Pet Bottle Clear', $item->sku);
        $this->assertTrue((bool) $item->sku_provisional);
    }

    public function test_the_masters_pull_update_path_leaves_the_flag_alone(): void
    {
        $service = app(ItemService::class);
        $service->upsertFromTally(['guid' => 'itm-1', 'name' => 'B.90ml Rib Pet Bottle Clear', 'base_unit' => 'Nos']);

        // A person has since given it a real SKU: the flag is off.
        Item::query()->where('tally_stock_item_guid', 'itm-1')->update(['sku' => 'BTL-90R-C', 'sku_provisional' => false]);

        $result = $service->upsertFromTally(['guid' => 'itm-1', 'name' => 'B.90ml Rib Pet Bottle Clear (renamed)', 'base_unit' => 'Nos']);

        $this->assertFalse($result['created']);
        $item = $result['item']->fresh();
        // The pull never touches the SKU (existing rule) and must not
        // re-provisionalise a SKU a person chose.
        $this->assertSame('BTL-90R-C', $item->sku);
        $this->assertFalse((bool) $item->sku_provisional);
    }

    public function test_a_manual_sku_edit_through_the_item_update_clears_the_flag(): void
    {
        $this->actingAsInventoryManager();
        $item = app(ItemService::class)->upsertFromTally(['guid' => 'itm-2', 'name' => 'B.170ml Pet Bottle', 'base_unit' => 'Nos'])['item'];
        $this->assertTrue((bool) $item->fresh()->sku_provisional);

        $this->putJson("/api/v1/inventory/items/{$item->id}", ['sku' => 'BTL-170'])
            ->assertOk()
            ->assertJsonPath('data.sku', 'BTL-170')
            ->assertJsonPath('data.sku_provisional', false);

        $this->assertFalse((bool) $item->fresh()->sku_provisional);
    }

    public function test_an_edit_that_echoes_the_same_sku_does_not_clear_the_flag(): void
    {
        $this->actingAsInventoryManager();
        $item = app(ItemService::class)->upsertFromTally(['guid' => 'itm-3', 'name' => 'B.200ml Pet Bottle', 'base_unit' => 'Nos'])['item'];

        // The Items form echoes every field back on save; touching the
        // colour must not silently declare the placeholder SKU chosen.
        $this->putJson("/api/v1/inventory/items/{$item->id}", ['sku' => 'B.200ml Pet Bottle', 'colour' => 'Amber'])
            ->assertOk()
            ->assertJsonPath('data.sku_provisional', true);

        $this->assertTrue((bool) $item->fresh()->sku_provisional);
    }

    public function test_the_flag_defaults_false_for_items_created_in_the_erp_and_is_never_client_writable(): void
    {
        $this->actingAsInventoryManager();

        $this->postJson('/api/v1/inventory/items', [
            'sku' => 'MAN-1', 'name' => 'Hand-made item', 'uom' => 'Nos',
            // A client cannot claim (or clear) provenance it does not own.
            'sku_provisional' => true,
        ])->assertCreated()->assertJsonPath('data.sku_provisional', false);

        $item = Item::query()->where('sku', 'MAN-1')->sole();
        $this->assertFalse((bool) $item->sku_provisional);

        $this->putJson("/api/v1/inventory/items/{$item->id}", ['sku_provisional' => true])
            ->assertOk()
            ->assertJsonPath('data.sku_provisional', false);
    }

    public function test_the_migration_is_reversible(): void
    {
        $migration = require base_path('database/migrations/2026_08_17_131000_add_sku_provisional_to_items_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('items', 'sku_provisional'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('items', 'sku_provisional'));
    }
}
