<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE ITEM NAME IS THE TALLY WIRE KEY.
 *
 * Every voucher line carries <STOCKITEMNAME>; no GUID ever reaches Tally. So a
 * rename of a Tally-linked item in the ERP does not rename anything in Tally —
 * it makes the next voucher name an item Tally does not have, and the failure
 * surfaces days later as a rejection instead of at the edit. Tally owns the
 * name; the masters pull (POST /tally-sync/masters, not this endpoint) brings a
 * Tally-side rename across.
 *
 * The second guard is the sibling hazard: Item::isLocalFixture() treats a
 * "LOCAL-" SKU as a fixture on the prefix alone, so typing one onto a real
 * item silently stops its vouchers posting.
 */
class ItemNameGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['is_active' => true]);
        foreach (['inventory.view', 'inventory.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);
    }

    private function tallyLinkedItem(): Item
    {
        return Item::create([
            'sku' => 'BTL-500-RIB-CLR-30',
            'name' => '500ml PET Bottle',
            'uom' => 'Nos',
            'tally_stock_item_guid' => 'guid-500ml',
        ]);
    }

    private function erpOnlyItem(array $attributes = []): Item
    {
        return Item::create([
            'sku' => 'BOX-200-RND',
            'name' => 'Round Box 200',
            'uom' => 'Nos',
            ...$attributes,
        ]);
    }

    public function test_a_tally_linked_item_cannot_be_renamed_through_the_api(): void
    {
        $item = $this->tallyLinkedItem();

        $this->putJson("/api/v1/inventory/items/{$item->id}", ['name' => '500ml Bottle (new)'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name'])
            ->assertJsonPath('message', '"500ml PET Bottle" is Tally\'s name for this item; rename it in Tally and pull masters, and the ERP follows.');

        $this->assertSame('500ml PET Bottle', $item->fresh()->name, 'the Tally name must survive the refused edit');
    }

    public function test_resending_the_same_name_with_other_edits_is_fine(): void
    {
        // Edit forms echo every field back, name included; an unchanged name
        // is not a rename.
        $item = $this->tallyLinkedItem();

        $this->putJson("/api/v1/inventory/items/{$item->id}", [
            'name' => '500ml PET Bottle',
            'description' => 'Clear ribbed 500ml, 30g',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', '500ml PET Bottle')
            ->assertJsonPath('data.description', 'Clear ribbed 500ml, 30g');
    }

    public function test_an_erp_only_item_keeps_an_editable_name(): void
    {
        $item = $this->erpOnlyItem();

        $this->putJson("/api/v1/inventory/items/{$item->id}", ['name' => 'Round Box 200 (amber)'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Round Box 200 (amber)');

        $this->assertSame('Round Box 200 (amber)', $item->fresh()->name);
    }

    public function test_a_local_fixture_sku_is_refused_on_a_real_item(): void
    {
        $item = $this->erpOnlyItem();

        $this->putJson("/api/v1/inventory/items/{$item->id}", ['sku' => Item::LOCAL_FIXTURE_SKU_PREFIX.'BOX-200'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sku']);

        $this->assertSame('BOX-200-RND', $item->fresh()->sku, 'a refused SKU must not land');
        $this->assertFalse($item->fresh()->isLocalFixture(), 'the real item must keep posting');
    }

    public function test_a_flagged_fixture_may_carry_the_prefix(): void
    {
        $item = $this->erpOnlyItem(['sku' => 'LOCAL-OLD-NAME', 'is_local_fixture' => true]);

        $this->putJson("/api/v1/inventory/items/{$item->id}", ['sku' => Item::LOCAL_FIXTURE_SKU_PREFIX.'ROUND-BOX-200'])
            ->assertOk()
            ->assertJsonPath('data.sku', 'LOCAL-ROUND-BOX-200');
    }

    public function test_a_prefix_only_fixture_can_still_be_edited_through_its_own_form(): void
    {
        // The product-master import fabricates its fixtures on the prefix
        // ALONE — localFixtureItem() never sets is_local_fixture, and the
        // 13-Aug backfill reached only the rows that existed then. The Items
        // form echoes the SKU back on every save, so if this guard read the
        // raw column instead of Item::isLocalFixture(), every one of those
        // fixtures would be locked out of its own edit modal by a message
        // claiming it "is not one".
        $item = $this->erpOnlyItem(['sku' => 'LOCAL-X', 'is_local_fixture' => false]);
        $this->assertTrue($item->isLocalFixture(), 'fixture by the prefix signal alone');

        $this->putJson("/api/v1/inventory/items/{$item->id}", ['sku' => 'LOCAL-X', 'uom' => 'Kgs'])
            ->assertOk()
            ->assertJsonPath('data.sku', 'LOCAL-X')
            ->assertJsonPath('data.uom', 'Kgs');
    }

    public function test_a_new_real_item_cannot_be_created_with_the_fixture_prefix(): void
    {
        $this->postJson('/api/v1/inventory/items', [
            'sku' => Item::LOCAL_FIXTURE_SKU_PREFIX.'TRAY-100',
            'name' => 'Tray 100',
            'uom' => 'Nos',
            'is_local_fixture' => false,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sku']);

        $this->assertDatabaseCount('items', 0);
    }
}
