<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialRequest;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Services\FactoryWarehouseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A DRAFT IS PRODUCTION'S OWN PAPER. THE STORE NEVER SEES IT.
 *
 * "All requests" on the store's queue means every request that has ENTERED THE
 * STORE'S WORKFLOW — not every row in the table. The floor writes a request,
 * changes its mind, adds a line, and none of that is the store's business until
 * the floor presses Submit.
 *
 * The rule is `submitted_at`, not a list of statuses: it is set the moment the
 * floor sends the request and never before, so it cannot be reasoned around.
 * And it is applied in the SERVICE, closed by default, so it survives a direct
 * API call, every filter combination, every page, and the "All" option — the
 * three routes the owner named.
 */
class StoreNeverSeesProductionDraftsTest extends TestCase
{
    use RefreshDatabase;

    private Item $material;

    private User $storekeeper;

    private User $floor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->floor = $this->userWith(['production.manage']);
        $this->storekeeper = $this->userWith(['inventory.manage']);

        $this->actAs($this->floor);

        $this->material = Item::create([
            'sku' => 'RM-PET', 'name' => 'Relpet PET Resin', 'uom' => 'Kgs',
            'is_active' => true, 'is_production_input' => true,
        ]);

        $store = Warehouse::create(['code' => 'DR-RM', 'name' => 'DR Store', 'is_active' => true, 'tally_guid' => 'dr-gd']);
        $wip = Warehouse::create(['code' => 'DR-WIP', 'name' => 'DR WIP', 'is_active' => false]);
        app(ProductionWipLocationResolver::class)->setWarehouseId($wip->id);
        app(FactoryWarehouseResolver::class)->setRawMaterialWarehouseId($store->id);

        app(StockMovementService::class)->recordReceipt(
            itemId: $this->material->id, warehouseId: $store->id,
            quantity: '1000', unitCost: '1.00', reference: 'DR opening',
        );
    }

    /** Every request number the STORE can see, however it asks. */
    private function storeSees(array $params = []): array
    {
        $this->actAs($this->storekeeper);

        return collect($this->getJson('/api/v1/inventory/material-requests?'.http_build_query($params))
            ->assertOk()->json('data'))->pluck('request_number')->all();
    }

    public function test_a_draft_is_invisible_to_the_store(): void
    {
        $draft = $this->raise();

        $this->assertNotContains($draft['request_number'], $this->storeSees(), 'an unsubmitted request reached the store queue');
    }

    public function test_a_submitted_request_is_visible(): void
    {
        $request = $this->submitted();

        $this->assertContains($request['request_number'], $this->storeSees());
    }

    public function test_a_partially_issued_request_is_visible(): void
    {
        $request = $this->submitted();
        $this->issue($request, '40'); // of 100

        $this->assertSame('partially_issued', MaterialRequest::findOrFail($request['id'])->status->value);
        $this->assertContains($request['request_number'], $this->storeSees());
    }

    public function test_a_fully_issued_request_is_visible_under_all(): void
    {
        $request = $this->submitted();
        $this->issue($request, '100');

        $this->assertSame('issued', MaterialRequest::findOrFail($request['id'])->status->value);

        // "All" is the absence of a status filter — the same shape the screen
        // sends, and the one that used to sweep drafts in with it.
        $this->assertContains($request['request_number'], $this->storeSees());
    }

    /**
     * THE THREE ROUTES THE OWNER NAMED. A draft must not appear through the All
     * option, through a direct API call, or through any filter/paging/search
     * combination that might have been assumed to bypass the rule.
     */
    public function test_no_filter_paging_or_search_combination_reveals_a_draft(): void
    {
        $draft = $this->raise();
        $submitted = $this->submitted();

        $attempts = [
            [],
            ['per_page' => 1000],
            ['per_page' => 1, 'page' => 1],
            ['status' => 'draft'],                                  // asking for them outright
            ['status' => ['draft', 'submitted']],
            ['q' => $draft['request_number']],                               // searching by its own number
            ['item_id' => $this->material->id],
            ['sort' => 'id'],
            ['include_unsubmitted' => 1],                            // the flag itself, from the store
            ['include_unsubmitted' => true, 'status' => 'draft'],
        ];

        foreach ($attempts as $params) {
            $seen = $this->storeSees($params);

            $this->assertNotContains($draft['request_number'], $seen,
                'a draft was reachable with params: '.json_encode($params));
        }

        // ...and the guard is not simply returning nothing.
        $this->assertContains($submitted['request_number'], $this->storeSees());
    }

    /**
     * The flag is granted by PERMISSION, not by query string. The store asking
     * for drafts is refused; the floor asking for its own is served.
     */
    public function test_the_floor_can_still_see_its_own_drafts(): void
    {
        $draft = $this->raise();

        $this->actAs($this->floor);
        $seen = collect($this->getJson('/api/v1/inventory/material-requests?include_unsubmitted=1')
            ->assertOk()->json('data'))->pluck('request_number')->all();

        $this->assertContains($draft['request_number'], $seen, 'production must still see its own working papers');
    }

    public function test_even_the_floor_gets_no_drafts_unless_it_asks(): void
    {
        $draft = $this->raise();

        $this->actAs($this->floor);
        $seen = collect($this->getJson('/api/v1/inventory/material-requests')->assertOk()->json('data'))
            ->pluck('request_number')->all();

        $this->assertNotContains($draft['request_number'], $seen, 'the default is closed for everyone');
    }

    /* ------------------------------ helpers ------------------------------ */

    private function raise(): array
    {
        $this->actAs($this->floor);

        return $this->postJson('/api/v1/inventory/material-requests', [
            'lines' => [['item_id' => $this->material->id, 'quantity' => '100']],
        ])->assertCreated()->json('data');
    }

    private function submitted(): array
    {
        $request = $this->raise();
        $this->postJson("/api/v1/inventory/material-requests/{$request['id']}/submit")->assertOk();

        return $request;
    }

    private function issue(array $request, string $quantity): void
    {
        $this->actAs($this->storekeeper);
        $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $request['id'],
            'lines' => [[
                'material_request_line_id' => $request['lines'][0]['id'],
                'item_id' => $this->material->id,
                'quantity' => $quantity,
            ]],
        ])->assertCreated();
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
    }
}
