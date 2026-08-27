<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE LABEL LIVES AND DIES WITH THE LINK.
 *
 * A `variant_label` names WHICH pack variant an item is ("840/box pouch") —
 * it is meaningless on a base product, and the migration and resource both
 * describe base items as carrying null for both fields. Codex's review of
 * 19b9f67 found the gap: clearing the link while leaving the label untouched
 * persisted a base item with a stale hidden label, which silently resurrected
 * the old packing identity the next time anyone linked the item again.
 *
 * The contract pinned here: unlinking clears the label even when the payload
 * does not mention it, and a label offered WITHOUT a link — on create or
 * update, explicit or implied — is refused, not stored.
 */
class VariantLabelLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsInventoryManager(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['inventory.view', 'inventory.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    private function item(string $sku, array $attributes = []): Item
    {
        return Item::create([
            'sku' => $sku,
            'name' => $attributes['name'] ?? "Synthetic {$sku}",
            'uom' => 'Nos.',
            ...$attributes,
        ]);
    }

    public function test_clearing_the_link_clears_the_label_even_when_the_payload_omits_it(): void
    {
        $this->actingAsInventoryManager();

        $base = $this->item('SYN-BASE');
        $variant = $this->item('SYN-POUCH', [
            'variant_of_item_id' => $base->id,
            'variant_label' => '840/box pouch',
        ]);

        $this->putJson("/api/v1/inventory/items/{$variant->id}", [
            'variant_of_item_id' => null,
        ])->assertSuccessful()
            ->assertJsonPath('data.variant_of_item_id', null)
            ->assertJsonPath('data.variant_label', null);

        $this->assertNull($variant->fresh()->variant_label);
    }

    public function test_clearing_the_link_while_sending_a_label_is_refused(): void
    {
        $this->actingAsInventoryManager();

        $base = $this->item('SYN-BASE');
        $variant = $this->item('SYN-TRAY', [
            'variant_of_item_id' => $base->id,
            'variant_label' => '490/box tray',
        ]);

        $this->putJson("/api/v1/inventory/items/{$variant->id}", [
            'variant_of_item_id' => null,
            'variant_label' => '490/box tray',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['variant_label']);

        // The refusal changed nothing.
        $this->assertSame($base->id, $variant->fresh()->variant_of_item_id);
    }

    public function test_a_label_on_an_unlinked_item_is_refused_on_update(): void
    {
        $this->actingAsInventoryManager();

        $item = $this->item('SYN-LONE');

        $this->putJson("/api/v1/inventory/items/{$item->id}", [
            'variant_label' => '840/box pouch',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['variant_label']);

        $this->assertNull($item->fresh()->variant_label);
    }

    public function test_a_label_without_a_link_is_refused_on_create(): void
    {
        $this->actingAsInventoryManager();

        $this->postJson('/api/v1/inventory/items', [
            'sku' => 'SYN-NEW',
            'name' => 'Synthetic New Bottle',
            'uom' => 'Nos.',
            'variant_label' => '840/box pouch',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['variant_label']);

        $this->assertNull(Item::where('sku', 'SYN-NEW')->first());
    }

    public function test_a_label_kept_while_the_link_stands_is_untouched(): void
    {
        $this->actingAsInventoryManager();

        $base = $this->item('SYN-BASE');
        $variant = $this->item('SYN-POUCH', [
            'variant_of_item_id' => $base->id,
            'variant_label' => '840/box pouch',
        ]);

        // An edit that never mentions the variant fields leaves both alone.
        $this->putJson("/api/v1/inventory/items/{$variant->id}", [
            'reorder_level' => '10.0000',
        ])->assertSuccessful()
            ->assertJsonPath('data.variant_label', '840/box pouch');
    }
}
