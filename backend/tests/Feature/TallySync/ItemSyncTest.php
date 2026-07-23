<?php

namespace Tests\Feature\TallySync;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ItemSyncTest extends TestCase
{
    use RefreshDatabase;

    private function actAsAgent(array $abilities = ['tally-sync:items']): void
    {
        Sanctum::actingAs(User::factory()->create(['is_active' => true]), $abilities);
    }

    public function test_it_creates_items_from_a_tally_masters_pull(): void
    {
        $this->actAsAgent();

        $response = $this->postJson('/api/v1/tally-sync/items', [
            'items' => [
                ['guid' => 'guid-100ml', 'name' => '100ml', 'base_unit' => 'Nos', 'alter_id' => 12],
                ['guid' => 'guid-mb-amber', 'name' => 'Master Batch – Amber', 'base_unit' => 'Kg', 'alter_id' => 40],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.total', 2);

        $this->assertDatabaseHas('items', [
            'tally_stock_item_guid' => 'guid-100ml',
            'name' => '100ml',
            'uom' => 'Nos',
            'tally_alter_id' => 12,
        ]);
        // SKU seeded from the (special-char) Tally name, still ERP-editable.
        $this->assertDatabaseHas('items', ['tally_stock_item_guid' => 'guid-mb-amber', 'sku' => 'Master Batch – Amber']);
    }

    public function test_it_upserts_on_guid_without_duplicating(): void
    {
        $this->actAsAgent();

        $this->postJson('/api/v1/tally-sync/items', [
            'items' => [['guid' => 'guid-x', 'name' => 'Old Name', 'base_unit' => 'Nos']],
        ])->assertOk();

        // Same GUID, renamed in Tally → update in place, not a second row.
        $response = $this->postJson('/api/v1/tally-sync/items', [
            'items' => [['guid' => 'guid-x', 'name' => 'New Name', 'base_unit' => 'Kg', 'alter_id' => 99]],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 1);

        $this->assertSame(1, Item::where('tally_stock_item_guid', 'guid-x')->count());
        $this->assertDatabaseHas('items', [
            'tally_stock_item_guid' => 'guid-x',
            'name' => 'New Name',
            'uom' => 'Kg',
            'tally_alter_id' => 99,
        ]);
    }

    public function test_it_forbids_a_token_without_the_items_ability(): void
    {
        $this->actAsAgent(['tally-sync:poll']); // can poll, but not push items

        $this->postJson('/api/v1/tally-sync/items', [
            'items' => [['guid' => 'guid-1', 'name' => 'Nope']],
        ])->assertForbidden();

        $this->assertDatabaseCount('items', 0);
    }

    public function test_it_validates_the_payload(): void
    {
        $this->actAsAgent();

        $this->postJson('/api/v1/tally-sync/items', [
            'items' => [['name' => 'missing guid']],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.guid');
    }
}
