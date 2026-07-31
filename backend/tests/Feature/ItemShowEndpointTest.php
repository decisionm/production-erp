<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The item detail page used to resolve its item out of the first page of the
 * items list, so with 600+ items in the master every "Details" click on
 * anything past row 20 rendered "Item not found". The page now loads the one
 * item by id — this covers that endpoint.
 */
class ItemShowEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsInventoryUser(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['inventory.view', 'inventory.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_it_returns_one_item_even_when_it_is_far_past_the_first_page_of_the_list(): void
    {
        $this->actingAsInventoryUser();

        // The list is ordered by name and paginated 20 at a time; "Zz…" is
        // last, i.e. exactly the item the old page could never find.
        foreach (range(1, 25) as $index) {
            Item::create(['sku' => "AAA-{$index}", 'name' => "Aaa Filler {$index}", 'uom' => 'PCS']);
        }
        $target = Item::create([
            'sku' => 'RM-PET',
            'name' => 'Zz PET Resin',
            'uom' => 'Kgs',
            'hsn_sac_code' => '39072100',
        ]);

        $this->getJson('/api/v1/inventory/items')
            ->assertSuccessful()
            ->assertJsonMissing(['sku' => 'RM-PET']);

        $this->getJson("/api/v1/inventory/items/{$target->id}")
            ->assertSuccessful()
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.sku', 'RM-PET')
            ->assertJsonPath('data.name', 'Zz PET Resin')
            ->assertJsonPath('data.uom', 'Kgs')
            ->assertJsonPath('data.hsn_sac_code', '39072100');
    }

    public function test_an_unknown_or_deleted_item_is_a_clean_404(): void
    {
        $this->actingAsInventoryUser();

        $item = Item::create(['sku' => 'RM-MB', 'name' => 'Masterbatch', 'uom' => 'Kgs']);
        $item->delete();

        $this->getJson("/api/v1/inventory/items/{$item->id}")->assertNotFound();
        $this->getJson('/api/v1/inventory/items/999999')->assertNotFound();
    }

    public function test_it_is_behind_the_inventory_module_guard(): void
    {
        $item = Item::create(['sku' => 'RM-PET', 'name' => 'PET Resin', 'uom' => 'Kgs']);

        $user = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/inventory/items/{$item->id}")->assertForbidden();
    }
}
