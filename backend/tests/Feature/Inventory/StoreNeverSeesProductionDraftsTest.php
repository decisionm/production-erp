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
use Illuminate\Testing\TestResponse;
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

    /**
     * THE `status=draft` FILTER STILL SELECTS A DRAFT — for the desk allowed
     * one. Both reviewers named this gap: MaterialRequestQueueFiltersTest no
     * longer seeds a draft at all (its "draft" fixture had always been a
     * submitted row through a dead comparison), so nothing pinned that the
     * filter value still does its job for a permitted login.
     */
    public function test_the_floor_can_filter_for_drafts_specifically(): void
    {
        $draft = $this->raise();
        $sent = $this->submitted();

        $this->actAs($this->floor);
        $seen = collect($this->getJson('/api/v1/inventory/material-requests?include_unsubmitted=1&status=draft')
            ->assertOk()->json('data'))->pluck('request_number')->all();

        $this->assertContains($draft['request_number'], $seen);
        $this->assertNotContains($sent['request_number'], $seen, 'the status filter still narrows');
    }

    public function test_even_the_floor_gets_no_drafts_unless_it_asks(): void
    {
        $draft = $this->raise();

        $this->actAs($this->floor);
        $seen = collect($this->getJson('/api/v1/inventory/material-requests')->assertOk()->json('data'))
            ->pluck('request_number')->all();

        $this->assertNotContains($draft['request_number'], $seen, 'the default is closed for everyone');
    }

    /**
     * THE FLAG AS A BROWSER ACTUALLY SPELLS IT.
     *
     * `include_unsubmitted` was validated with Laravel's `boolean` rule, which
     * takes `1`, `0`, `"1"` and `"0"` and NOT `"true"` — which is exactly what
     * axios puts on the wire for a JS `true`, and exactly what the floor's own
     * Material Requests page was sending. The page asked for its drafts, got a
     * 422, rendered an empty table with no error surfaced, and because Submit
     * is a ROW ACTION on that table a raised request could never be sent to
     * the store at all.
     *
     * Three verification rounds missed it because every test built its query
     * with `http_build_query()`, which encodes PHP `true` as `"1"` — the one
     * spelling that worked. So this test writes the query string BY HAND. A
     * test that cannot send what the browser sends cannot see what the browser
     * sees.
     */
    public function test_the_include_unsubmitted_flag_accepts_every_spelling_a_caller_uses(): void
    {
        $draft = $this->raise();
        $this->actAs($this->floor);

        foreach (['true', '1', 'TRUE'] as $spelling) {
            $seen = collect($this->getJson("/api/v1/inventory/material-requests?include_unsubmitted={$spelling}")
                ->assertOk("spelling: {$spelling}")->json('data'))->pluck('request_number')->all();

            $this->assertContains($draft['request_number'], $seen, "spelling: {$spelling}");
        }

        foreach (['false', '0'] as $spelling) {
            $seen = collect($this->getJson("/api/v1/inventory/material-requests?include_unsubmitted={$spelling}")
                ->assertOk("spelling: {$spelling}")->json('data'))->pluck('request_number')->all();

            $this->assertNotContains($draft['request_number'], $seen, "spelling: {$spelling}");
        }
    }

    /** ...and the string spelling still buys the store nothing. */
    public function test_the_string_spelling_does_not_let_the_store_in(): void
    {
        $draft = $this->raise();
        $this->actAs($this->storekeeper);

        $seen = collect($this->getJson('/api/v1/inventory/material-requests?include_unsubmitted=true')
            ->assertOk()->json('data'))->pluck('request_number')->all();

        $this->assertNotContains($draft['request_number'], $seen);
    }

    /**
     * A REFUSAL MUST NOT TELL YOU THE ROW IS THERE — INCLUDING BY BEING A
     * DIFFERENT REFUSAL.
     *
     * The gate was first written in the CONTROLLER, which runs after the
     * FormRequest. So a cancel carrying a too-short reason answered 422 for a
     * draft that exists and 404 for an id that does not, and a store login
     * could walk the id space with one ordinary request and no side effects —
     * defeating the 404 that was added for exactly this reason.
     *
     * It is middleware now, so it runs before anything that could answer
     * differently, and it throws the same exception route-model binding throws
     * so the body is byte-identical too.
     */
    public function test_a_refusal_cannot_be_told_apart_from_a_row_that_is_not_there(): void
    {
        $draft = $this->raise();
        $ghostId = $draft['id'] + 9_999;

        $this->actAs($this->storekeeper);

        // An invalid payload must not become an oracle: the gate has to fire
        // before the reason is ever validated.
        $onDraft = $this->postJson("/api/v1/inventory/material-requests/{$draft['id']}/cancel", ['reason' => 'x']);
        $onGhost = $this->postJson("/api/v1/inventory/material-requests/{$ghostId}/cancel", ['reason' => 'x']);

        $onDraft->assertStatus(404);
        $onGhost->assertStatus(404);
        // Compared with the caller's OWN id normalised out: the framework
        // echoes the id you asked for, which tells you nothing you did not
        // already know. What must not differ is anything else.
        $this->assertSame(
            $this->refusal($onGhost, $ghostId),
            $this->refusal($onDraft, $draft['id']),
            'the two refusals must be indistinguishable',
        );

        // ...and the same for a well-formed reason, and for the plain read.
        $this->postJson("/api/v1/inventory/material-requests/{$draft['id']}/cancel", ['reason' => 'a proper reason'])
            ->assertStatus(404);

        $readDraft = $this->getJson("/api/v1/inventory/material-requests/{$draft['id']}");
        $readGhost = $this->getJson("/api/v1/inventory/material-requests/{$ghostId}");

        $readDraft->assertStatus(404);
        $this->assertSame(
            $this->refusal($readGhost, $ghostId),
            $this->refusal($readDraft, $draft['id']),
            'a read must not be an oracle either',
        );

        $this->assertSame('draft', MaterialRequest::query()->whereKey($draft['id'])->value('status')->value);
    }

    /** The refusal's substance, with the caller's own id normalised out. */
    private function refusal(TestResponse $response, int $id): string
    {
        return str_replace((string) $id, '{id}', (string) $response->json('message'));
    }

    /**
     * SUBMIT WAS THE THIRD DOOR, AND IT DEFEATED THE OTHER TWO.
     *
     * The gate went on `show` and `cancel`. `submit` is the only other route
     * carrying a `{material_request}`, and it sat in a production-only group —
     * where Laravel runs SubstituteBindings AHEAD of the unprioritised `module`
     * alias. So binding answered 404 for an id that does not exist while the
     * permission check answered 403 for one that does, and a store login walked
     * the id space one ordinary POST at a time and found production's drafts.
     *
     * Closing two doors out of three closes nothing: it is the same bit about
     * the same rows at the same cost.
     */
    public function test_submit_does_not_tell_the_store_which_ids_exist(): void
    {
        $draft = $this->raise();
        $sent = $this->submitted();
        $ghostId = $sent['id'] + 9_999;

        $this->actAs($this->storekeeper);

        $onDraft = $this->postJson("/api/v1/inventory/material-requests/{$draft['id']}/submit");
        $onGhost = $this->postJson("/api/v1/inventory/material-requests/{$ghostId}/submit");

        $onDraft->assertStatus(404);
        $onGhost->assertStatus(404);
        $this->assertSame(
            $this->refusal($onGhost, $ghostId),
            $this->refusal($onDraft, $draft['id']),
            'a draft must not be tellable from an id that is not there',
        );

        // A SUBMITTED request may answer 403 — the store already sees it in its
        // own queue, so "it exists" is not a disclosure.
        $this->postJson("/api/v1/inventory/material-requests/{$sent['id']}/submit")->assertStatus(403);

        $this->assertNull(
            MaterialRequest::query()->whereKey($draft['id'])->value('submitted_at'),
            'and nothing was submitted on production\'s behalf',
        );
    }

    /** ...while the floor still submits its own, which is the whole point. */
    public function test_the_floor_still_submits_its_own_draft(): void
    {
        $draft = $this->raise();

        $this->postJson("/api/v1/inventory/material-requests/{$draft['id']}/submit")->assertOk();

        $this->assertNotNull(MaterialRequest::query()->whereKey($draft['id'])->value('submitted_at'));
    }

    /* ------------------------------ helpers ------------------------------ */

    /**
     * THE QUEUE'S FILTER WAS ONLY HALF THE RULE.
     *
     * `show` and `cancel` are route-model-bound in the group BOTH desks read,
     * and neither asked about `submitted_at` — the closure lived in queue()
     * alone. Request numbers are sequential, so a store-only login did not even
     * need to guess: it could read a draft in full, and CANCEL it, killing
     * production's working paper before the floor had ever sent it. `cancel`'s
     * only guard is the lifecycle one (`! isFinal()`), and a draft is not
     * final.
     *
     * 404 rather than 403 — a 403 would confirm the row is there, which is the
     * thing being kept private.
     */
    public function test_the_store_can_neither_read_nor_cancel_a_draft_by_its_id(): void
    {
        $draft = $this->raise();

        $this->actAs($this->storekeeper);

        $this->getJson("/api/v1/inventory/material-requests/{$draft['id']}")->assertStatus(404);

        $this->postJson("/api/v1/inventory/material-requests/{$draft['id']}/cancel", [
            'reason' => 'not my paper to tear up',
        ])->assertStatus(404);

        $this->assertSame(
            'draft',
            MaterialRequest::query()->whereKey($draft['id'])->value('status')->value,
            'the draft survived the refusal',
        );
    }

    /** ...while the floor keeps full control of its own paper. */
    public function test_the_floor_can_read_and_cancel_its_own_draft(): void
    {
        $draft = $this->raise();

        $this->getJson("/api/v1/inventory/material-requests/{$draft['id']}")->assertOk();

        $this->postJson("/api/v1/inventory/material-requests/{$draft['id']}/cancel", [
            'reason' => 'run pulled',
        ])->assertOk();
    }

    /** And once it IS sent, it is the store's business in the ordinary way. */
    public function test_a_submitted_request_is_readable_and_cancellable_by_the_store(): void
    {
        $sent = $this->submitted();

        $this->actAs($this->storekeeper);

        $this->getJson("/api/v1/inventory/material-requests/{$sent['id']}")->assertOk();
        $this->postJson("/api/v1/inventory/material-requests/{$sent['id']}/cancel", [
            'reason' => 'cannot fulfil',
        ])->assertOk();
    }

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
