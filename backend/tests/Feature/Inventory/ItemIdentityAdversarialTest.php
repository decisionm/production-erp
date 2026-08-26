<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\ItemIdentityWarning;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemGroup;
use App\Modules\Inventory\Services\ItemIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * INDEPENDENT ADVERSARIAL COVERAGE for Phase 2A (Product Identification).
 *
 * This file does NOT edit the builder's own tests (ItemIdentityWarningsTest,
 * ItemIdentityVariantLinkTest, ItemIdentitySuggestedCategoryTest,
 * ItemIdentityEndpointsTest, ItemIdentityCategoryEnumTest) — it probes the
 * same six areas the build spec called out for adversarial review, but with
 * scenarios those files do not already exercise: soft-delete exclusion on
 * the OTHER two name-shaped classes (not just duplicate_name), the fixture
 * exclusion's OR-signal from the flag side rather than the SKU-prefix side,
 * a three-way fold collision, linking a variant to a SOFT-DELETED base, and
 * confirming the identity machinery does not leak into the plain item list.
 *
 * ALL DATA HERE IS SYNTHETIC — no real customer, vendor or product name, and
 * no purchase rate anywhere (AGENTS.md, FC-06).
 */
class ItemIdentityAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private function identity(): ItemIdentityService
    {
        // Fresh instance per assertion block — the sweep is memoised for the
        // lifetime of one instance, so a test that writes and re-reads must
        // ask a new one (same discipline as the builder's own test file).
        return app(ItemIdentityService::class);
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

    /** @return list<string> */
    private function warningKeys(Item $item): array
    {
        return array_column($this->identity()->warningsFor($item->fresh()), 'class');
    }

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

    // ---- 1. soft delete, beyond duplicate_name --------------------------------

    /**
     * The builder pinned this for `duplicate_name`. The SAME name pass feeds
     * `possible_duplicate_master` and `outbound_ambiguity` too, and each
     * reads a different slice of the fold groups — an exclusion proven for
     * one is not automatically proven for the other two.
     */
    public function test_a_soft_deleted_master_does_not_trip_possible_duplicate_master_for_the_survivor(): void
    {
        $kept = $this->item('SYN-ADV-FOLD-KEPT', ['name' => 'Synthetic Bottle 500 ML']);
        $archived = $this->item('SYN-ADV-FOLD-GONE', ['name' => 'synthetic-bottle,  500 ml.']);
        $archived->delete();

        $this->assertNotContains(
            ItemIdentityWarning::PossibleDuplicateMaster->value,
            $this->warningKeys($kept),
        );
    }

    public function test_a_soft_deleted_tally_linked_master_does_not_trip_outbound_ambiguity_for_the_survivor(): void
    {
        $kept = $this->item('SYN-ADV-AMB-KEPT', ['name' => 'Synthetic Ambiguous Jar']);
        $archived = $this->item('SYN-ADV-AMB-GONE', [
            'name' => 'Synthetic Ambiguous Jar',
            'tally_stock_item_guid' => 'guid-syn-adv-gone',
        ]);
        $archived->delete();

        // Only one live master carries the name now, so it cannot be
        // ambiguous to a voucher — the deleted row is not "the other one".
        $this->assertNotContains(
            ItemIdentityWarning::OutboundAmbiguity->value,
            $this->warningKeys($kept),
        );
        $this->assertNotContains(
            ItemIdentityWarning::DuplicateName->value,
            $this->warningKeys($kept),
        );
    }

    /** A trashed row must never surface as one of the warned items, on any class. */
    public function test_a_soft_deleted_item_never_appears_in_the_warnings_list_even_though_it_is_unclassified_and_unlinked(): void
    {
        $trashed = $this->item('SYN-ADV-TRASHED', ['name' => 'Synthetic Trashed Unclassified']);
        $trashedId = $trashed->id;
        $trashed->delete();

        $allWarned = $this->identity()->itemsWithWarnings()->pluck('id')->all();

        $this->assertNotContains($trashedId, $allWarned);
    }

    // ---- 2. local fixture exclusion from the FLAG side -------------------------

    /**
     * Item::isLocalFixture() is documented as an OR of two signals — the flag
     * and the SKU prefix. The builder's fixture case used the SKU-prefix
     * signal (SKU begins "LOCAL-", flag left false). This proves the OTHER
     * direction: flagged a fixture, but the SKU carries no such prefix.
     */
    public function test_missing_tally_mapping_excludes_a_fixture_flagged_by_the_column_alone_not_the_sku(): void
    {
        $fixture = $this->item('SYN-ADV-FIX', [
            'name' => 'Synthetic Flag-Only Fixture',
            'is_local_fixture' => true,
        ]);

        $this->assertNotContains(
            ItemIdentityWarning::MissingTallyMapping->value,
            $this->warningKeys($fixture),
        );
    }

    // ---- 3. a three-way fold collision, not just a pair -------------------------

    public function test_possible_duplicate_master_catches_all_three_rows_of_a_three_way_spelling_collision(): void
    {
        $a = $this->item('SYN-ADV-3A', ['name' => 'Synthetic Cap 28MM']);
        $b = $this->item('SYN-ADV-3B', ['name' => 'synthetic   cap, 28mm']);
        $c = $this->item('SYN-ADV-3C', ['name' => 'SYNTHETIC-CAP-28MM.']);
        $unrelated = $this->item('SYN-ADV-3D', ['name' => 'Synthetic Cap 30MM']);

        foreach ([$a, $b, $c] as $row) {
            $this->assertContains(
                ItemIdentityWarning::PossibleDuplicateMaster->value,
                $this->warningKeys($row),
                "Expected {$row->sku} to be caught in the three-way fold collision.",
            );
        }
        $this->assertNotContains(
            ItemIdentityWarning::PossibleDuplicateMaster->value,
            $this->warningKeys($unrelated),
        );
    }

    // ---- 4. variant link vs a soft-deleted base ----------------------------------

    /**
     * `variant_of_item_id` validates via
     * `Rule::exists('items','id')->whereNull('deleted_at')`. This is the
     * cycle guard's companion rule — a base that no longer exists in the
     * live catalogue must not silently become a valid link target, on
     * EITHER write path.
     */
    public function test_a_new_item_may_not_be_created_as_a_variant_of_a_soft_deleted_base(): void
    {
        $this->actingAsInventoryManager();

        $base = $this->item('SYN-ADV-DELBASE-1');
        $base->delete();

        $this->postJson('/api/v1/inventory/items', [
            'sku' => 'SYN-ADV-DELVAR-1',
            'name' => 'Synthetic Variant Of Deleted Base',
            'uom' => 'Nos.',
            'variant_of_item_id' => $base->id,
        ])->assertStatus(422)->assertJsonValidationErrors('variant_of_item_id');

        $this->assertDatabaseMissing('items', ['sku' => 'SYN-ADV-DELVAR-1']);
    }

    public function test_an_existing_item_may_not_be_repointed_at_a_soft_deleted_base(): void
    {
        $this->actingAsInventoryManager();

        $base = $this->item('SYN-ADV-DELBASE-2');
        $base->delete();
        $item = $this->item('SYN-ADV-DELVAR-2');

        $this->putJson("/api/v1/inventory/items/{$item->id}", ['variant_of_item_id' => $base->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('variant_of_item_id');

        $this->assertNull($item->fresh()->variant_of_item_id);
    }

    // ---- 5. suggested_category never leaks into the plain item list -------------

    /**
     * ItemIdentityService is never constructed for `GET /inventory/items` —
     * proving `suggested_category` is never PERSISTED (the builder's own
     * test) is a different claim from proving it never even APPEARS on a
     * response an ordinary item-list caller reads. Both matter: a stamped,
     * unsaved attribute on an Eloquent model still serializes through a
     * resource that reads `$this->resource->getAttributes()` naively, so
     * this pins the boundary at the HTTP contract, not just the database.
     */
    public function test_the_plain_item_list_never_carries_a_suggested_category_or_identity_warnings_key(): void
    {
        $this->actingAsInventoryManager();

        $group = ItemGroup::create(['name' => 'Raw Material']);
        $this->item('SYN-ADV-NOLEAK', ['item_group_id' => $group->id, 'uom' => 'Kgs.']);

        // Exercise the identity sweep first, in case a shared container
        // instance could bleed a stamped attribute across requests.
        $this->getJson('/api/v1/inventory/identity/items')->assertSuccessful();

        $row = collect($this->getJson('/api/v1/inventory/items')->assertSuccessful()->json('data'))
            ->firstWhere('sku', 'SYN-ADV-NOLEAK');

        $this->assertIsArray($row);
        $this->assertArrayNotHasKey('suggested_category', $row);
        $this->assertArrayNotHasKey('suggested_category_confidence', $row);
        $this->assertArrayNotHasKey('identity_warnings', $row);
        $this->assertArrayNotHasKey('identity_suggested_category', $row);
        $this->assertArrayNotHasKey('warnings', $row);
    }

    // ---- 6. unauthenticated, with the parameters an attacker would actually send --

    /**
     * ItemIdentityEndpointsTest already proves a bare unauthenticated GET is
     * refused. This proves the refusal holds when a `warning=` filter is
     * present too — an unauthenticated caller must be turned away by
     * `auth:sanctum` BEFORE `ListItemWarningsRequest::authorize()` (which
     * returns true unconditionally) gets a chance to run and validate the
     * filter into a 200.
     */
    public function test_the_items_endpoint_refuses_an_unauthenticated_caller_even_with_a_valid_warning_filter(): void
    {
        $this->getJson('/api/v1/inventory/identity/items?warning='.ItemIdentityWarning::Unclassified->value)
            ->assertUnauthorized();
    }
}
