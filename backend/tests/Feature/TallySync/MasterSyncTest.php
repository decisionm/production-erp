<?php

namespace Tests\Feature\TallySync;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemGroup;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\TallySync\Models\Ledger;
use App\Modules\TallySync\Models\LedgerGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MasterSyncTest extends TestCase
{
    use RefreshDatabase;

    private function actAsAgent(array $abilities = ['tally-sync:masters']): void
    {
        Sanctum::actingAs(User::factory()->create(['is_active' => true]), $abilities);
    }

    public function test_it_builds_the_item_group_hierarchy_and_links_items(): void
    {
        $this->actAsAgent();

        // Child listed BEFORE parent on purpose — resolution must be order-independent.
        $response = $this->postJson('/api/v1/tally-sync/masters', [
            'item_groups' => [
                ['guid' => 'g-bottles', 'name' => 'Bottles', 'parent' => 'Finished Goods'],
                ['guid' => 'g-fg', 'name' => 'Finished Goods', 'parent' => 'Primary'],
            ],
            'items' => [
                ['guid' => 'i-500', 'name' => '500ml PET', 'base_unit' => 'Nos', 'parent' => 'Bottles', 'alter_id' => 5],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.item_groups.created', 2)
            ->assertJsonPath('data.items.created', 1);

        $fg = ItemGroup::where('name', 'Finished Goods')->firstOrFail();
        $bottles = ItemGroup::where('name', 'Bottles')->firstOrFail();

        $this->assertNull($fg->parent_id, 'Root group (parent "Primary") should have no parent.');
        $this->assertSame($fg->id, $bottles->parent_id, 'Child should link to its parent by name.');

        $item = Item::where('tally_stock_item_guid', 'i-500')->firstOrFail();
        $this->assertSame($bottles->id, $item->item_group_id, 'Item should link to its stock group.');
    }

    public function test_it_upserts_godowns_as_warehouses(): void
    {
        $this->actAsAgent();

        $this->postJson('/api/v1/tally-sync/masters', [
            'godowns' => [
                ['guid' => 'gd-main', 'name' => 'Main Store'],
                ['guid' => 'gd-fg', 'name' => 'FG Store', 'parent' => 'Main Store'],
            ],
        ])->assertOk()->assertJsonPath('data.godowns.created', 2);

        $main = Warehouse::where('tally_guid', 'gd-main')->firstOrFail();
        $fg = Warehouse::where('tally_guid', 'gd-fg')->firstOrFail();
        $this->assertSame($main->id, $fg->parent_id);
        $this->assertNotEmpty($main->code, 'A unique code should be generated for a Tally godown.');
    }

    public function test_it_mirrors_ledger_groups_and_ledgers(): void
    {
        $this->actAsAgent();

        $this->postJson('/api/v1/tally-sync/masters', [
            'ledger_groups' => [
                ['guid' => 'lg-ca', 'name' => 'Current Assets', 'parent' => 'Primary'],
                ['guid' => 'lg-sd', 'name' => 'Sundry Debtors', 'parent' => 'Current Assets'],
            ],
            'ledgers' => [
                ['guid' => 'l-acme', 'name' => 'Acme Traders', 'group' => 'Sundry Debtors'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.ledger_groups.created', 2)
            ->assertJsonPath('data.ledgers.created', 1);

        $sd = LedgerGroup::where('name', 'Sundry Debtors')->firstOrFail();
        $this->assertSame(
            LedgerGroup::where('name', 'Current Assets')->value('id'),
            $sd->parent_id
        );
        $this->assertSame($sd->id, Ledger::where('tally_guid', 'l-acme')->value('ledger_group_id'));
    }

    public function test_it_is_idempotent_on_re_pull(): void
    {
        $this->actAsAgent();

        $payload = [
            'item_groups' => [['guid' => 'g-1', 'name' => 'Raw Material', 'parent' => 'Primary']],
            'items' => [['guid' => 'i-1', 'name' => 'PET Resin', 'base_unit' => 'Kg', 'parent' => 'Raw Material']],
        ];

        $this->postJson('/api/v1/tally-sync/masters', $payload)->assertOk();
        $this->postJson('/api/v1/tally-sync/masters', $payload)
            ->assertOk()
            ->assertJsonPath('data.item_groups.updated', 1)
            ->assertJsonPath('data.items.updated', 1);

        $this->assertSame(1, ItemGroup::count());
        $this->assertSame(1, Item::whereNotNull('tally_stock_item_guid')->count());
    }

    public function test_it_forbids_a_token_without_the_masters_ability(): void
    {
        $this->actAsAgent(['tally-sync:poll']);

        $this->postJson('/api/v1/tally-sync/masters', [
            'item_groups' => [['guid' => 'g', 'name' => 'X']],
        ])->assertForbidden();

        $this->assertDatabaseCount('item_groups', 0);
    }
}
