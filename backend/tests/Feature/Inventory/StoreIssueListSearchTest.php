<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StoreIssue;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE STORE-ISSUE LIST'S SEARCH — GET /inventory/store-issues?q=…
 *
 * `q` matches a handover's IDENTITY and nothing else: its issue number, the
 * request it fulfils in any spelling ("MR-12", "mr 12", "12"), and the
 * material on any of its lines by SKU, Tally name or display name. Notes
 * and cancellation reasons are deliberately outside it.
 *
 * "Server-side" is the contract and is asserted the way the queue's filter
 * test asserts it: the rows come back narrowed AND `meta.total` is the
 * narrowed count while `per_page=1` — a total that survives paging can only
 * have been counted in SQL. The ordering is pinned too, because a list that
 * reshuffles between two loads of the same page is a list nobody can work.
 */
class StoreIssueListSearchTest extends TestCase
{
    use RefreshDatabase;

    private Item $resin;

    private Item $carton;

    private Warehouse $store;

    private Warehouse $wip;

    /** @var array<int, StoreIssue> keyed by the number in the issue number */
    private array $issues = [];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('inventory.view', 'web');
        $user->givePermissionTo('inventory.view');
        Sanctum::actingAs($user);

        $this->store = Warehouse::create(['code' => 'RM-STORE', 'name' => 'Raw Material Store', 'is_active' => true]);
        $this->wip = Warehouse::create(['code' => 'WIP', 'name' => 'Work In Progress', 'is_active' => true]);

        $this->resin = Item::create([
            'sku' => 'RM-PET', 'name' => 'Relpet PET Resin', 'display_name' => 'Bottle Grade Resin', 'uom' => 'Kgs',
        ]);
        $this->carton = Item::create(['sku' => 'PKG-CTN', 'name' => 'Carton 500ml', 'uom' => 'Nos']);

        // #1 · against MR-7 · 10-Aug · resin
        $this->issues[1] = $this->issue(1, 7, '2026-08-10 07:00:00', [$this->resin]);
        // #2 · against MR-8 · 11-Aug 07:00 · carton
        $this->issues[2] = $this->issue(2, 8, '2026-08-11 07:00:00', [$this->carton]);
        // #3 · a verbal ask (no request) · 11-Aug 07:00, the SAME second as #2 · resin AND carton
        $this->issues[3] = $this->issue(3, null, '2026-08-11 07:00:00', [$this->resin, $this->carton]);
    }

    public function test_no_search_is_the_whole_list_newest_first_with_id_breaking_the_tie(): void
    {
        // #2 and #3 share an issued_at; the higher id comes first, and it
        // comes first on every load.
        $this->assertNumbers([3, 2, 1], $this->list());
        $this->assertNumbers([3, 2, 1], $this->list());
    }

    public function test_the_issue_number_matches_in_any_case_and_by_a_fragment(): void
    {
        $this->assertNumbers([2], $this->list(['q' => 'si-000002']));
        $this->assertNumbers([1], $this->list(['q' => '000001']));
    }

    public function test_the_request_number_matches_in_any_spelling(): void
    {
        foreach (['MR-7', 'mr 7', 'MR7', 'mr-7', '7'] as $spelling) {
            $this->assertNumbers([1], $this->list(['q' => $spelling]), "spelling {$spelling}");
        }

        $this->assertNumbers([2], $this->list(['q' => '8']));
    }

    public function test_the_material_on_any_line_matches_by_sku_name_or_display_name(): void
    {
        $this->assertNumbers([3, 2], $this->list(['q' => 'pkg-ctn']), 'sku');
        $this->assertNumbers([3, 2], $this->list(['q' => 'Carton']), 'name');
        $this->assertNumbers([3, 1], $this->list(['q' => 'relpet']), 'name');
        $this->assertNumbers([3, 1], $this->list(['q' => 'bottle grade']), 'display name');
    }

    public function test_a_two_line_issue_is_one_row_however_many_of_its_lines_match(): void
    {
        // "-" is in every SKU and every issue number: each row once, three in all.
        $response = $this->list(['q' => '-']);

        $this->assertNumbers([3, 2, 1], $response);
        $this->assertSame(3, $response->json('meta.total'));
    }

    public function test_the_total_is_counted_in_sql_and_survives_paging(): void
    {
        $first = $this->list(['q' => 'resin', 'per_page' => 1]);

        $this->assertNumbers([3], $first);
        $this->assertSame(2, $first->json('meta.total'));
        $this->assertSame(2, $first->json('meta.last_page'));
        $this->assertSame(1, $first->json('meta.per_page'));

        $second = $this->list(['q' => 'resin', 'per_page' => 1, 'page' => 2]);

        $this->assertNumbers([1], $second);
        $this->assertSame(2, $second->json('meta.current_page'));
        $this->assertSame(2, $second->json('meta.total'));
    }

    public function test_a_term_nothing_carries_is_an_empty_page_with_a_zero_total(): void
    {
        $response = $this->list(['q' => 'nothing-here']);

        $this->assertSame([], $response->json('data'));
        $this->assertSame(0, $response->json('meta.total'));
    }

    public function test_a_typed_wildcard_is_a_character_not_a_wildcard(): void
    {
        // No identity contains a literal % or _, so an unescaped LIKE would
        // return everything here and an escaped one returns nothing.
        $this->assertSame(0, $this->list(['q' => '%'])->json('meta.total'));
        $this->assertSame(0, $this->list(['q' => '_'])->json('meta.total'));
    }

    public function test_notes_and_reasons_are_not_searched(): void
    {
        StoreIssue::query()->whereKey($this->issues[1]->id)->update(['notes' => 'urgent night shift top-up']);

        $this->assertSame(0, $this->list(['q' => 'night shift'])->json('meta.total'));
    }

    public function test_a_search_that_could_only_be_a_mistake_is_a_422(): void
    {
        $this->getJson('/api/v1/inventory/store-issues?q[]=x')->assertStatus(422);
        $this->getJson('/api/v1/inventory/store-issues?q='.str_repeat('a', 101))->assertStatus(422);
        $this->getJson('/api/v1/inventory/store-issues?page=0')->assertStatus(422);
    }

    public function test_the_search_narrows_the_other_filters_rather_than_replacing_them(): void
    {
        // "-" alone is every row; with the request filter it is that request's rows.
        $this->assertNumbers([2], $this->list(['q' => '-', 'material_request_id' => 8]));
        $this->assertNumbers([3, 1], $this->list(['q' => '-', 'item_id' => $this->resin->id]));
    }

    /* ---------------------------------------------------------------- *
     * fixture
     * ---------------------------------------------------------------- */

    /** @param  list<Item>  $items */
    private function issue(int $number, ?int $requestId, string $issuedAt, array $items): StoreIssue
    {
        $issue = StoreIssue::create([
            'issue_number' => sprintf('SI-%06d', $number),
            'material_request_id' => $requestId,
            'status' => 'issued',
            'issued_by' => User::factory()->create()->id,
            'issued_at' => $issuedAt,
        ]);

        foreach ($items as $item) {
            $issue->lines()->create([
                'item_id' => $item->id,
                'from_warehouse_id' => $this->store->id,
                'to_warehouse_id' => $this->wip->id,
                'quantity_issued' => '10.0000',
                'uom' => $item->uom,
            ]);
        }

        return $issue;
    }

    private function list(array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/inventory/store-issues'.($query === [] ? '' : '?'.http_build_query($query)))->assertOk();
    }

    /** @param  list<int>  $expectedNumbers */
    private function assertNumbers(array $expectedNumbers, TestResponse $response, string $message = ''): void
    {
        $this->assertSame(
            array_map(fn (int $number) => $this->issues[$number]->id, $expectedNumbers),
            collect($response->json('data'))->pluck('id')->all(),
            $message,
        );
    }
}
