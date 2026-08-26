<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemGroup;
use App\Modules\Inventory\Services\ItemIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `suggested_category` IS A SUGGESTION AND IS NEVER WRITTEN.
 *
 * Q60 — which ItemCategory each Tally stock group maps to — is an OPEN owner
 * question, and its two hardest cases are named in it as the question
 * itself: `Caps & Closures` (the largest group, defensibly raw OR packing)
 * and `Scrap` (produced, not purchased, and `sellable()` turns on the
 * answer). This class pins that the mapping SUGGESTS for the groups the
 * evidence covers, REFUSES for those two, and never touches the stored
 * column either way.
 *
 * The group names are Tally's, read from the 26-Aug-2026 stock-master
 * export. They are stock-group names, not party names — no customer, vendor
 * or rate appears here.
 */
class ItemIdentitySuggestedCategoryTest extends TestCase
{
    use RefreshDatabase;

    private function itemInGroup(string $sku, ?string $groupName): Item
    {
        $groupId = $groupName === null
            ? null
            : ItemGroup::firstOrCreate(['name' => $groupName])->id;

        return Item::create([
            'sku' => $sku,
            'name' => "Synthetic {$sku}",
            'uom' => 'Nos.',
            'item_group_id' => $groupId,
        ]);
    }

    /** @return array{category: ?string, confidence: ?string} */
    private function suggestion(Item $item): array
    {
        $result = app(ItemIdentityService::class)->suggestedCategoryFor($item);

        return ['category' => $result['category']?->value, 'confidence' => $result['confidence']];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function firmGroups(): array
    {
        return [
            'Raw Material' => ['Raw Material', 'raw_material'],
            'PET' => ['PET', 'raw_material'],
            'Packing Material' => ['Packing Material', 'packing_material'],
            'Carton Box' => ['Carton Box', 'packing_material'],
            'Tray' => ['Tray', 'packing_material'],
            'BOPP TAPE' => ['BOPP TAPE', 'packing_material'],
            'SHRINK ROLLS' => ['SHRINK ROLLS', 'packing_material'],
            'Finished Goods' => ['Finished Goods', 'finished_good'],
            'Amber Pet Bottle' => ['Amber Pet Bottle', 'finished_good'],
            'Clear Pet Bottle' => ['Clear Pet Bottle', 'finished_good'],
            'Green Pet Bottle' => ['Green Pet Bottle', 'finished_good'],
            'Liquor Pet Bottle' => ['Liquor Pet Bottle', 'finished_good'],
            'Milk White Pet Bottle' => ['Milk White Pet Bottle', 'finished_good'],
            'Orange Pet Bottle' => ['Orange Pet Bottle', 'finished_good'],
            'Tablet Container' => ['Tablet Container', 'finished_good'],
            'HDPE Bottles & Container' => ['HDPE Bottles & Container', 'finished_good'],
        ];
    }

    #[DataProvider('firmGroups')]
    public function test_each_evidenced_group_suggests_its_category_firmly(string $group, string $expected): void
    {
        $item = $this->itemInGroup('SYN-'.md5($group), $group);

        $this->assertSame(
            ['category' => $expected, 'confidence' => ItemIdentityService::CONFIDENCE_FIRM],
            $this->suggestion($item),
        );
    }

    /**
     * Masterbatch is dosed into a run, so it is an input — but "input" is not
     * automatically "raw material" in a taxonomy that also has consumables,
     * and Q60 has not said. Suggested, and marked a judgement call.
     */
    public function test_master_batch_is_suggested_raw_material_but_marked_low_confidence(): void
    {
        $item = $this->itemInGroup('SYN-MB', 'Master Batch');

        $this->assertSame(
            ['category' => ItemCategory::RawMaterial->value, 'confidence' => ItemIdentityService::CONFIDENCE_LOW],
            $this->suggestion($item),
        );
    }

    /**
     * Q60(a). 132 items, and the single largest open case: a cap is fitted TO
     * the bottle and a measuring cup is packed WITH it. Both answers are
     * defensible, so this suggests NEITHER.
     */
    public function test_caps_and_closures_gets_no_suggestion_at_all(): void
    {
        $item = $this->itemInGroup('SYN-CAP', 'Caps & Closures');

        $this->assertSame(['category' => null, 'confidence' => null], $this->suggestion($item));
    }

    /**
     * Q60(b). Scrap is produced, not purchased, and whether it may ever go on
     * a sales order is exactly what `sellable()` turns on. No suggestion.
     */
    public function test_scrap_gets_no_suggestion_at_all(): void
    {
        $item = $this->itemInGroup('SYN-SCRAP', 'Scrap');

        $this->assertSame(['category' => null, 'confidence' => null], $this->suggestion($item));
    }

    public function test_an_item_with_no_tally_group_gets_no_suggestion(): void
    {
        $item = $this->itemInGroup('SYN-NOGROUP', null);

        $this->assertSame(['category' => null, 'confidence' => null], $this->suggestion($item));
    }

    /**
     * There is deliberately NO walk up to a parent group: a group nobody has
     * evidence for gets nothing, rather than inheriting a suggestion.
     */
    public function test_a_group_that_is_not_in_the_table_gets_nothing_even_under_a_mapped_parent(): void
    {
        $parent = ItemGroup::create(['name' => 'Finished Goods']);
        $child = ItemGroup::create(['name' => 'Synthetic New Bottle Colour', 'parent_id' => $parent->id]);

        $item = Item::create([
            'sku' => 'SYN-NEWGROUP',
            'name' => 'Synthetic New Colour Bottle',
            'uom' => 'Nos.',
            'item_group_id' => $child->id,
        ]);

        $this->assertSame(['category' => null, 'confidence' => null], $this->suggestion($item));
    }

    public function test_the_group_name_is_matched_case_and_spacing_insensitively(): void
    {
        $item = $this->itemInGroup('SYN-CASE', 'bopp   tape');

        $this->assertSame(
            ['category' => ItemCategory::PackingMaterial->value, 'confidence' => ItemIdentityService::CONFIDENCE_FIRM],
            $this->suggestion($item),
        );
    }

    public function test_a_suggestion_is_never_persisted_onto_the_item(): void
    {
        $item = $this->itemInGroup('SYN-NOWRITE', 'Raw Material');

        $this->suggestion($item);
        app(ItemIdentityService::class)->itemsWithWarnings();

        $this->assertNull($item->fresh()->category);
        $this->assertDatabaseHas('items', ['sku' => 'SYN-NOWRITE', 'category' => null]);
    }
}
