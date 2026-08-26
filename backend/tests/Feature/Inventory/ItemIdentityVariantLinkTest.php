<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE PACK-VARIANT LINK, THROUGH THE API — one level, from both sides.
 *
 * DEC-20260821-001 made a pack variant that carries its own Tally stock item
 * a SEPARATE ERP master related to a base product. That is a flat pair, and
 * `items.variant_of_item_id` is a self-referencing FK a database cannot hold
 * to one level, so the write path does — and the three ways a chain forms
 * are each pinned here, including the one that is easy to miss: pointing a
 * BASE at something while its own variants still point at it.
 *
 * The `category` cases are here too, because they share the same requests:
 * the column was fillable and validated nowhere, so every category sent to
 * these endpoints was silently dropped before this build.
 */
class ItemIdentityVariantLinkTest extends TestCase
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

    // ---- the link that is allowed --------------------------------------------

    public function test_a_variant_may_be_linked_to_a_base_and_carries_its_label(): void
    {
        $this->actingAsInventoryManager();

        $base = $this->item('SYN-BASE', ['name' => 'Synthetic Bottle 200ML']);
        $variant = $this->item('SYN-POUCH', ['name' => 'Synthetic Bottle 200ML Pouch']);

        $this->putJson("/api/v1/inventory/items/{$variant->id}", [
            'variant_of_item_id' => $base->id,
            'variant_label' => '840/box pouch',
            'display_name' => '200ML bottle — pouch pack',
        ])->assertSuccessful()
            ->assertJsonPath('data.variant_of_item_id', $base->id)
            ->assertJsonPath('data.variant_label', '840/box pouch')
            ->assertJsonPath('data.display_name', '200ML bottle — pouch pack');

        $this->assertSame($base->id, $variant->fresh()->variant_of_item_id);
        // The wire key is untouched: a display name renames nothing Tally sees.
        $this->assertSame('Synthetic Bottle 200ML Pouch', $variant->fresh()->name);
    }

    public function test_the_link_may_be_cleared_which_makes_the_item_a_base_again(): void
    {
        $this->actingAsInventoryManager();

        $base = $this->item('SYN-BASE-2');
        $variant = $this->item('SYN-VAR-2', ['variant_of_item_id' => $base->id]);

        $this->putJson("/api/v1/inventory/items/{$variant->id}", ['variant_of_item_id' => null])
            ->assertSuccessful();

        $this->assertNull($variant->fresh()->variant_of_item_id);
    }

    // ---- the three cycles ----------------------------------------------------

    public function test_an_item_may_not_be_a_variant_of_itself(): void
    {
        $this->actingAsInventoryManager();

        $item = $this->item('SYN-SELF');

        $this->putJson("/api/v1/inventory/items/{$item->id}", ['variant_of_item_id' => $item->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('variant_of_item_id');

        $this->assertNull($item->fresh()->variant_of_item_id);
    }

    public function test_a_variant_may_not_be_the_base_of_another_variant(): void
    {
        $this->actingAsInventoryManager();

        $base = $this->item('SYN-CHAIN-BASE');
        $middle = $this->item('SYN-CHAIN-MID', ['variant_of_item_id' => $base->id]);
        $third = $this->item('SYN-CHAIN-THIRD');

        $this->putJson("/api/v1/inventory/items/{$third->id}", ['variant_of_item_id' => $middle->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('variant_of_item_id');

        $this->assertNull($third->fresh()->variant_of_item_id);
    }

    /**
     * THE ONE FROM THE OTHER SIDE. Neither of the two rules above catches
     * it: A is a perfectly good base, C is a perfectly good base, and
     * pointing A at C is only wrong because B is already pointing at A.
     */
    public function test_a_base_that_already_has_variants_may_not_become_a_variant_itself(): void
    {
        $this->actingAsInventoryManager();

        $base = $this->item('SYN-OTHERSIDE-A');
        $this->item('SYN-OTHERSIDE-B', ['variant_of_item_id' => $base->id]);
        $elsewhere = $this->item('SYN-OTHERSIDE-C');

        $this->putJson("/api/v1/inventory/items/{$base->id}", ['variant_of_item_id' => $elsewhere->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('variant_of_item_id');

        $this->assertNull($base->fresh()->variant_of_item_id);
    }

    /**
     * THE SAME ONE, WITH THE VARIANT ARCHIVED — and it must still refuse.
     *
     * `items` soft-deletes, so an archived variant is invisible to the plain
     * relation while still being a physical row that points at its base. If
     * the guard only counted live variants, this sequence would build the
     * chain the trait promises cannot exist: archive B (pointing at A),
     * repoint A at C unopposed, then let the Tally masters pull restore B —
     * ItemService::upsertFromTally() looks items up withTrashed() and calls
     * restore() — leaving B -> A -> C live. ItemService's dependency check
     * for this same column already counts archived variants; this pins the
     * write path to the same definition.
     */
    public function test_an_archived_variant_still_blocks_its_base_from_becoming_a_variant(): void
    {
        $this->actingAsInventoryManager();

        $base = $this->item('SYN-ARCHIVED-A');
        $variant = $this->item('SYN-ARCHIVED-B', ['variant_of_item_id' => $base->id]);
        $elsewhere = $this->item('SYN-ARCHIVED-C');

        $variant->delete();
        $this->assertSoftDeleted('items', ['id' => $variant->id]);

        $this->putJson("/api/v1/inventory/items/{$base->id}", ['variant_of_item_id' => $elsewhere->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('variant_of_item_id');

        $this->assertNull($base->fresh()->variant_of_item_id);

        // And the chain the restore would have completed never forms.
        $variant->restore();
        $this->assertSame($base->id, $variant->fresh()->variant_of_item_id);
        $this->assertNull($base->fresh()->variant_of_item_id);
    }

    public function test_a_variant_of_an_item_that_does_not_exist_is_refused(): void
    {
        $this->actingAsInventoryManager();

        $item = $this->item('SYN-GHOST');

        $this->putJson("/api/v1/inventory/items/{$item->id}", ['variant_of_item_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('variant_of_item_id');
    }

    public function test_a_new_item_may_not_be_created_as_a_variant_of_a_variant(): void
    {
        $this->actingAsInventoryManager();

        $base = $this->item('SYN-CREATE-BASE');
        $variant = $this->item('SYN-CREATE-VAR', ['variant_of_item_id' => $base->id]);

        $this->postJson('/api/v1/inventory/items', [
            'sku' => 'SYN-CREATE-NEW',
            'name' => 'Synthetic Created Variant',
            'uom' => 'Nos.',
            'variant_of_item_id' => $variant->id,
        ])->assertStatus(422)->assertJsonValidationErrors('variant_of_item_id');

        $this->assertDatabaseMissing('items', ['sku' => 'SYN-CREATE-NEW']);
    }

    public function test_a_new_item_may_be_created_as_a_variant_of_a_base(): void
    {
        $this->actingAsInventoryManager();

        $base = $this->item('SYN-OKBASE');

        $this->postJson('/api/v1/inventory/items', [
            'sku' => 'SYN-OKVAR',
            'name' => 'Synthetic Created Ok Variant',
            'uom' => 'Nos.',
            'variant_of_item_id' => $base->id,
            'variant_label' => '490/box tray',
        ])->assertSuccessful()->assertJsonPath('data.variant_label', '490/box tray');

        $this->assertDatabaseHas('items', ['sku' => 'SYN-OKVAR', 'variant_of_item_id' => $base->id]);
    }

    // ---- the base is not deletable while variants point at it ----------------

    public function test_deleting_a_base_with_variants_is_the_contracts_refusal_not_a_database_error(): void
    {
        $owner = $this->actingAsInventoryManager();
        Permission::findOrCreate('configuration-delete.manage', 'web');
        $owner->givePermissionTo('configuration-delete.manage');

        $base = $this->item('SYN-DEL-BASE');
        $this->item('SYN-DEL-VAR', ['variant_of_item_id' => $base->id]);

        $response = $this->deleteJson("/api/v1/inventory/items/{$base->id}");

        $response->assertStatus(422);
        $this->assertContains('pack_variants', collect($response->json('blocking'))->pluck('code')->all());
        $this->assertDatabaseHas('items', ['sku' => 'SYN-DEL-BASE']);
    }

    // ---- category, which was being dropped silently --------------------------

    public function test_a_category_sent_to_the_update_endpoint_is_actually_stored(): void
    {
        $this->actingAsInventoryManager();

        $item = $this->item('SYN-CAT');

        $this->putJson("/api/v1/inventory/items/{$item->id}", ['category' => ItemCategory::PackingMaterial->value])
            ->assertSuccessful()
            ->assertJsonPath('data.category', 'packing_material');

        $this->assertSame(ItemCategory::PackingMaterial, $item->fresh()->category);
    }

    public function test_the_three_new_categories_are_accepted_and_a_made_up_one_is_not(): void
    {
        $this->actingAsInventoryManager();

        foreach ([ItemCategory::WorkInProgress, ItemCategory::Consumable, ItemCategory::SpareTooling] as $index => $case) {
            $item = $this->item("SYN-NEWCAT-{$index}");

            $this->putJson("/api/v1/inventory/items/{$item->id}", ['category' => $case->value])
                ->assertSuccessful()
                ->assertJsonPath('data.category', $case->value);

            $this->assertSame($case, $item->fresh()->category);
        }

        $item = $this->item('SYN-BADCAT');
        $this->putJson("/api/v1/inventory/items/{$item->id}", ['category' => 'semi_finished'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');
    }

    public function test_a_category_may_be_cleared_back_to_the_unsaid_state(): void
    {
        $this->actingAsInventoryManager();

        $item = $this->item('SYN-UNSAY', ['category' => ItemCategory::FinishedGood->value]);

        $this->putJson("/api/v1/inventory/items/{$item->id}", ['category' => null])->assertSuccessful();

        // NULL is "nobody has said yet" and is not ItemCategory::Other.
        $this->assertNull($item->fresh()->category);
    }

    public function test_the_item_list_carries_the_identity_fields_and_the_tally_group_name(): void
    {
        $this->actingAsInventoryManager();

        $group = ItemGroup::create(['name' => 'Carton Box']);
        $base = $this->item('SYN-LIST-BASE', ['name' => 'Synthetic Listed Base']);
        $this->item('SYN-LIST-VAR', [
            'name' => 'Synthetic Listed Variant',
            'display_name' => 'Listed variant, pouch',
            'variant_of_item_id' => $base->id,
            'variant_label' => '840/box pouch',
            'category' => ItemCategory::PackingMaterial->value,
            'item_group_id' => $group->id,
        ]);

        $rows = collect($this->getJson('/api/v1/inventory/items')->assertSuccessful()->json('data'))
            ->keyBy('sku');

        $this->assertSame('Listed variant, pouch', $rows['SYN-LIST-VAR']['display_name']);
        $this->assertSame($base->id, $rows['SYN-LIST-VAR']['variant_of_item_id']);
        $this->assertSame('840/box pouch', $rows['SYN-LIST-VAR']['variant_label']);
        $this->assertSame('packing_material', $rows['SYN-LIST-VAR']['category']);
        $this->assertSame('Carton Box', $rows['SYN-LIST-VAR']['item_group']);
        $this->assertSame('none', $rows['SYN-LIST-VAR']['tracking_type']);

        $this->assertNull($rows['SYN-LIST-BASE']['display_name']);
        $this->assertNull($rows['SYN-LIST-BASE']['variant_of_item_id']);
        $this->assertNull($rows['SYN-LIST-BASE']['category']);
        $this->assertNull($rows['SYN-LIST-BASE']['item_group']);
    }

    /**
     * The list renders the Tally group per row, which is exactly the shape
     * that becomes an N+1 the moment somebody drops the eager load.
     */
    public function test_the_item_list_does_not_query_once_per_row_for_the_group(): void
    {
        $this->actingAsInventoryManager();

        $group = ItemGroup::create(['name' => 'Tray']);
        foreach (range(1, 12) as $index) {
            $this->item("SYN-N1-{$index}", ['item_group_id' => $group->id]);
        }

        DB::enableQueryLog();
        $this->getJson('/api/v1/inventory/items')->assertSuccessful();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $groupQueries = collect($queries)->filter(
            fn (array $query): bool => str_contains($query['query'], 'from "item_groups"'),
        );

        $this->assertLessThanOrEqual(1, $groupQueries->count(), 'The item list must eager-load its groups.');
    }
}
