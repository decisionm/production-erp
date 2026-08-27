<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Enums\ItemIdentityWarning;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GET /inventory/identity/health and /inventory/identity/items.
 *
 * Two reads and nothing else — there is no write action on the controller,
 * and that is the point: every answer these give is a warning about an OPEN
 * owner question (Q43, Q59, Q60), and an endpoint able to act on one would
 * be this repo deciding a factory question on the owner's behalf.
 */
class ItemIdentityEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsInventoryReader(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('inventory.view', 'web');
        $user->givePermissionTo('inventory.view');
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

    // ---- the gate ------------------------------------------------------------

    public function test_both_endpoints_are_behind_the_inventory_module_guard(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_active' => true]));

        $this->getJson('/api/v1/inventory/identity/health')->assertForbidden();
        $this->getJson('/api/v1/inventory/identity/items')->assertForbidden();
    }

    public function test_both_endpoints_refuse_an_unauthenticated_caller(): void
    {
        $this->getJson('/api/v1/inventory/identity/health')->assertUnauthorized();
        $this->getJson('/api/v1/inventory/identity/items')->assertUnauthorized();
    }

    /** Reading the catalogue's health needs no more than reading the catalogue. */
    public function test_inventory_view_alone_is_enough_to_read_them(): void
    {
        $this->actingAsInventoryReader();

        $this->getJson('/api/v1/inventory/identity/health')->assertSuccessful();
        $this->getJson('/api/v1/inventory/identity/items')->assertSuccessful();
    }

    // ---- health --------------------------------------------------------------

    public function test_health_lists_every_warning_class_with_its_label_and_count(): void
    {
        $this->actingAsInventoryReader();

        $this->item('SYN-A', ['name' => 'Synthetic Shared Name']);
        $this->item('SYN-B', ['name' => 'Synthetic Shared Name']);

        $response = $this->getJson('/api/v1/inventory/identity/health')->assertSuccessful();

        $this->assertSame(ItemIdentityWarning::keys(), array_column($response->json('data.warnings'), 'class'));
        $this->assertSame(2, $response->json('data.items'));
        $this->assertSame(2, $response->json('data.items_with_any_warning'));

        $counts = array_column($response->json('data.warnings'), 'count', 'class');
        $this->assertSame(2, $counts[ItemIdentityWarning::DuplicateName->value]);

        $labels = array_column($response->json('data.warnings'), 'label', 'class');
        $this->assertSame('Duplicate name', $labels[ItemIdentityWarning::DuplicateName->value]);
    }

    // ---- the list ------------------------------------------------------------

    public function test_the_list_filters_to_one_warning_class_when_asked(): void
    {
        $this->actingAsInventoryReader();

        // Unclassified AND unlinked.
        $this->item('SYN-BOTH', ['name' => 'Synthetic Both Problems']);
        // Classified, linked, uniquely named: nothing wrong with it.
        $this->item('SYN-FINE', [
            'name' => 'Synthetic Fine',
            'category' => ItemCategory::FinishedGood->value,
            'tally_stock_item_guid' => 'guid-fine',
        ]);
        // Classified but unlinked: only one of the two classes.
        $this->item('SYN-NOGUID', [
            'name' => 'Synthetic No Guid',
            'category' => ItemCategory::FinishedGood->value,
        ]);

        $all = collect($this->getJson('/api/v1/inventory/identity/items')->assertSuccessful()->json('data'))
            ->pluck('sku')->all();
        $this->assertEqualsCanonicalizing(['SYN-BOTH', 'SYN-NOGUID'], $all);

        $unclassified = collect(
            $this->getJson('/api/v1/inventory/identity/items?warning='.ItemIdentityWarning::Unclassified->value)
                ->assertSuccessful()->json('data')
        )->pluck('sku')->all();
        $this->assertSame(['SYN-BOTH'], $unclassified);
    }

    public function test_a_warning_class_nobody_defined_is_a_422_not_a_silently_empty_table(): void
    {
        $this->actingAsInventoryReader();

        $this->getJson('/api/v1/inventory/identity/items?warning=looks_odd')
            ->assertStatus(422)
            ->assertJsonValidationErrors('warning');
    }

    public function test_a_page_size_beyond_the_ceiling_is_refused(): void
    {
        $this->actingAsInventoryReader();

        $this->getJson('/api/v1/inventory/identity/items?per_page=5000')
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    public function test_each_row_carries_its_warnings_its_group_its_variant_base_and_the_suggestion(): void
    {
        $this->actingAsInventoryReader();

        $group = ItemGroup::create(['name' => 'Caps & Closures']);
        $trayGroup = ItemGroup::create(['name' => 'Tray']);

        $base = $this->item('SYN-ROW-BASE', ['name' => 'Synthetic Row Base', 'item_group_id' => $trayGroup->id]);
        $this->item('SYN-ROW-VAR', [
            'name' => 'Synthetic Row Variant',
            'display_name' => 'Row variant, pouch',
            'variant_of_item_id' => $base->id,
            'variant_label' => '840/box pouch',
            'item_group_id' => $group->id,
        ]);

        $rows = collect($this->getJson('/api/v1/inventory/identity/items')->assertSuccessful()->json('data'))
            ->keyBy('sku');

        $variant = $rows['SYN-ROW-VAR'];
        $this->assertSame('Row variant, pouch', $variant['display_name']);
        $this->assertSame('Caps & Closures', $variant['item_group']);
        $this->assertSame('840/box pouch', $variant['variant_label']);
        $this->assertSame($base->id, $variant['variant_of']['id']);
        $this->assertSame('SYN-ROW-BASE', $variant['variant_of']['sku']);
        // Q60's largest case, answered by DEC-20260827-001: caps are a
        // finished good, because the factory sells them and only a finished
        // good is sellable.
        $this->assertSame('finished_good', $variant['suggested_category']);
        $this->assertSame('firm', $variant['suggested_category_confidence']);
        $this->assertContains(
            ItemIdentityWarning::Unclassified->value,
            array_column($variant['warnings'], 'class'),
        );

        // The base sits in a group the evidence does cover.
        $this->assertSame('packing_material', $rows['SYN-ROW-BASE']['suggested_category']);
        $this->assertSame('firm', $rows['SYN-ROW-BASE']['suggested_category_confidence']);
        $this->assertNull($rows['SYN-ROW-BASE']['variant_of']);
    }

    /**
     * The row shape is rendered once per item on a page, so a suggestion or
     * a warning resolved per row would re-run the whole sweep twenty-five
     * times. Pinned by counting queries rather than by reading the code.
     */
    public function test_a_page_of_rows_costs_a_flat_number_of_queries(): void
    {
        $this->actingAsInventoryReader();

        $group = ItemGroup::create(['name' => 'Master Batch']);
        $base = $this->item('SYN-FLAT-BASE', ['item_group_id' => $group->id]);
        foreach (range(1, 20) as $index) {
            $this->item("SYN-FLAT-{$index}", ['item_group_id' => $group->id, 'variant_of_item_id' => $base->id]);
        }

        DB::enableQueryLog();
        $this->getJson('/api/v1/inventory/identity/items?per_page=25')->assertSuccessful();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Generous, and still an order of magnitude below one-per-row.
        $this->assertLessThan(25, $count, "The identity list ran {$count} queries for 21 rows.");
    }

    public function test_the_endpoints_write_nothing(): void
    {
        $this->actingAsInventoryReader();

        $group = ItemGroup::create(['name' => 'Raw Material']);
        $item = $this->item('SYN-READONLY', ['item_group_id' => $group->id, 'uom' => 'Kgs.']);
        $before = $item->fresh()->toArray();

        $this->getJson('/api/v1/inventory/identity/health')->assertSuccessful();
        $this->getJson('/api/v1/inventory/identity/items')->assertSuccessful();

        $this->assertSame($before, $item->fresh()->toArray());
        $this->assertNull($item->fresh()->category);
    }
}
