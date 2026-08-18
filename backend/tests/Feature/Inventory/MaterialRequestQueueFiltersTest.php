<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\MaterialRequestStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialRequest;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE STORE'S QUEUE — GET /inventory/material-requests, the one list the
 * storekeeper works from, filtered SERVER-SIDE by status, shift, date
 * range, work centre and material.
 *
 * "Server-side" is the contract, not an implementation detail, so it is
 * asserted twice over: the rows come back narrowed, AND the paginator's
 * `meta.total` is the narrowed count while `per_page=1` — a total that
 * survives paging can only have been counted in SQL, never by filtering a
 * page of rows in PHP. The last test reads the query log and pins the
 * WHERE clauses themselves.
 *
 * A resin request appears in the queue with NO work centre (FC-01 /
 * DEC-20260807-006) and must therefore never be swept up by a
 * `work_center_id` filter, nor hidden from the unfiltered queue.
 */
class MaterialRequestQueueFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Shift $shiftA;

    private Shift $shiftB;

    private WorkCenter $m1;

    private WorkCenter $m2;

    private Item $resin;

    private Item $carton;

    private Item $tape;

    /** @var array<string, MaterialRequest> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingWith(['inventory.view']);

        $this->shiftA = Shift::create(['name' => 'A', 'start_time' => '06:00:00', 'end_time' => '14:00:00', 'is_active' => true]);
        $this->shiftB = Shift::create(['name' => 'B', 'start_time' => '14:00:00', 'end_time' => '22:00:00', 'is_active' => true]);
        $this->m1 = WorkCenter::create(['code' => 'M-01', 'name' => 'Machine 1', 'is_active' => true]);
        $this->m2 = WorkCenter::create(['code' => 'M-02', 'name' => 'Machine 2', 'is_active' => true]);

        $this->resin = Item::create(['sku' => 'RM-PET', 'name' => 'Relpet PET Resin', 'uom' => 'Kgs']);
        $this->carton = Item::create(['sku' => 'PKG-CTN', 'name' => 'Carton 500ml', 'uom' => 'Nos']);
        $this->tape = Item::create(['sku' => 'PKG-TAPE', 'name' => 'Packing Tape', 'uom' => 'Nos']);

        // resin  · no machine (FC-01) · shift A · 10-Aug · submitted
        $this->requests['resin'] = $this->request(MaterialRequestStatus::Submitted, $this->shiftA, null, '2026-08-10 07:00:00', $this->resin, '500');
        // carton · machine 1 · shift A · 11-Aug · draft
        $this->requests['carton'] = $this->request(MaterialRequestStatus::Draft, $this->shiftA, $this->m1, '2026-08-11 07:00:00', $this->carton, '40');
        // tape   · machine 2 · shift B · 12-Aug · partially issued
        $this->requests['tape'] = $this->request(MaterialRequestStatus::PartiallyIssued, $this->shiftB, $this->m2, '2026-08-12 15:00:00', $this->tape, '12');
        // carton · machine 1 · shift B · 13-Aug · cancelled
        $this->requests['stale'] = $this->request(MaterialRequestStatus::Cancelled, $this->shiftB, $this->m1, '2026-08-13 15:00:00', $this->carton, '10');
    }

    public function test_an_empty_query_string_is_the_whole_queue_newest_first(): void
    {
        $response = $this->queue();

        $this->assertIds(['stale', 'tape', 'carton', 'resin'], $response);
        $this->assertSame(4, $response->json('meta.total'));
    }

    public function test_status_takes_one_value_or_a_list(): void
    {
        $this->assertIds(['carton'], $this->queue(['status' => 'draft']));
        $this->assertIds(
            ['tape', 'resin'],
            $this->queue(['status' => ['submitted', 'partially_issued']]),
        );
    }

    public function test_a_status_that_does_not_exist_is_a_422_not_an_empty_list(): void
    {
        $this->getJson('/api/v1/inventory/material-requests?status=approved')->assertStatus(422);
        $this->getJson('/api/v1/inventory/material-requests?status[]=draft&status[]=approved')->assertStatus(422);
    }

    public function test_shift_narrows_the_queue(): void
    {
        $this->assertIds(['carton', 'resin'], $this->queue(['shift_id' => $this->shiftA->id]));
        $this->assertIds(['stale', 'tape'], $this->queue(['shift_id' => $this->shiftB->id]));
    }

    public function test_a_date_range_is_inclusive_of_both_ends_of_requested_at(): void
    {
        $this->assertIds(['tape', 'carton'], $this->queue(['from' => '2026-08-11', 'to' => '2026-08-12']));
        $this->assertIds(['stale', 'tape', 'carton'], $this->queue(['from' => '2026-08-11']));
        $this->assertIds(['carton', 'resin'], $this->queue(['to' => '2026-08-11']));
    }

    public function test_the_date_range_is_the_factory_day_not_the_utc_day(): void
    {
        // The app clock is UTC; the factory's day is IST (UTC+5:30). A
        // night-shift request raised at 02:00 IST on the 17th is STORED as
        // 20:30 UTC on the 16th. The storekeeper asking for "the 17th" must
        // get it, and the storekeeper asking for "the 16th" must not — a
        // filter that compared the column against the raw wall-clock string
        // would get both backwards and lose every night-shift request.
        $night = $this->request(
            MaterialRequestStatus::Submitted,
            $this->shiftB,
            null,
            '2026-08-16 20:30:00', // = 2026-08-17 02:00 IST
            $this->resin,
            '300',
        );
        $this->requests['night'] = $night;

        $ids = fn (array $query) => collect($this->queue($query)->json('data'))->pluck('id')->all();

        $this->assertContains($night->id, $ids(['from' => '2026-08-17', 'to' => '2026-08-17']));
        $this->assertNotContains($night->id, $ids(['from' => '2026-08-16', 'to' => '2026-08-16']));

        // And the last instant of a factory day is still inside it: 18:29:59
        // UTC is 23:59:59 IST on the same date.
        $lastSecond = $this->request(
            MaterialRequestStatus::Submitted,
            $this->shiftB,
            null,
            '2026-08-17 18:29:59',
            $this->resin,
            '10',
        );

        $this->assertContains($lastSecond->id, $ids(['from' => '2026-08-17', 'to' => '2026-08-17']));
        $this->assertNotContains($lastSecond->id, $ids(['from' => '2026-08-18', 'to' => '2026-08-18']));
    }

    public function test_a_reversed_date_range_is_refused(): void
    {
        $this->getJson('/api/v1/inventory/material-requests?from=2026-08-12&to=2026-08-11')->assertStatus(422);
    }

    public function test_work_centre_narrows_the_queue_and_never_catches_a_resin_request(): void
    {
        $this->assertIds(['stale', 'carton'], $this->queue(['work_center_id' => $this->m1->id]));
        $this->assertIds(['tape'], $this->queue(['work_center_id' => $this->m2->id]));

        // The resin request carries no work centre by rule; it is in the
        // unfiltered queue and in no machine's filtered one.
        foreach ([$this->m1, $this->m2] as $machine) {
            $ids = collect($this->queue(['work_center_id' => $machine->id])->json('data'))->pluck('id')->all();
            $this->assertNotContains($this->requests['resin']->id, $ids);
        }
    }

    public function test_material_narrows_the_queue_to_the_requests_asking_for_it(): void
    {
        $this->assertIds(['stale', 'carton'], $this->queue(['item_id' => $this->carton->id]));
        $this->assertIds(['resin'], $this->queue(['item_id' => $this->resin->id]));
    }

    public function test_q_finds_a_request_by_its_number_in_any_spelling(): void
    {
        $id = $this->requests['tape']->id;

        foreach (["MR-{$id}", "mr {$id}", (string) $id] as $spelling) {
            $this->assertIds(['tape'], $this->queue(['q' => $spelling]), "q={$spelling}");
        }
    }

    public function test_filters_combine(): void
    {
        $this->assertIds(
            ['carton'],
            $this->queue([
                'status' => 'draft',
                'shift_id' => $this->shiftA->id,
                'work_center_id' => $this->m1->id,
                'item_id' => $this->carton->id,
                'from' => '2026-08-11',
                'to' => '2026-08-11',
            ]),
        );
    }

    public function test_sort_and_per_page_are_validated(): void
    {
        $this->assertIds(['resin', 'carton', 'tape', 'stale'], $this->queue(['sort' => 'requested_at']));
        $this->getJson('/api/v1/inventory/material-requests?sort=notes')->assertStatus(422);
        $this->getJson('/api/v1/inventory/material-requests?per_page=0')->assertStatus(422);
        $this->getJson('/api/v1/inventory/material-requests?per_page=5000')->assertStatus(422);
    }

    public function test_an_unknown_query_key_is_ignored(): void
    {
        $this->assertIds(['stale', 'tape', 'carton', 'resin'], $this->queue(['nonsense' => 'x']));
    }

    public function test_every_filter_narrows_in_sq_l_not_in_php(): void
    {
        // per_page=1 returns ONE row; a total of 1 can only come from a
        // COUNT the database ran with the same WHERE clauses.
        $response = $this->queue([
            'status' => 'draft',
            'shift_id' => $this->shiftA->id,
            'work_center_id' => $this->m1->id,
            'item_id' => $this->carton->id,
            'from' => '2026-08-11',
            'to' => '2026-08-11',
            'per_page' => 1,
        ]);

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertCount(1, $response->json('data'));

        // And the clauses themselves are in the statement the database ran.
        DB::enableQueryLog();
        $this->queue([
            'status' => 'draft',
            'shift_id' => $this->shiftA->id,
            'work_center_id' => $this->m1->id,
            'item_id' => $this->carton->id,
            'from' => '2026-08-11',
            'to' => '2026-08-11',
        ]);
        // Identifier quoting differs by driver (sqlite \" vs MySQL `) and
        // this assertion is about the CLAUSES, not the dialect.
        $sql = collect(DB::getQueryLog())
            ->pluck('query')
            ->map(fn (string $q) => str_replace(['"', '`'], '', $q))
            ->filter(fn (string $q) => str_contains($q, 'material_requests'))
            ->implode(' | ');
        DB::disableQueryLog();

        foreach (['status in', 'shift_id =', 'work_center_id =', 'requested_at >=', 'requested_at <', 'material_request_lines'] as $clause) {
            $this->assertStringContainsString($clause, $sql, "the queue must filter on {$clause} in SQL");
        }
    }

    // ---- helpers -----------------------------------------------------------

    /** @param  array<string, mixed>  $query */
    private function queue(array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/inventory/material-requests'.($query === [] ? '' : '?'.http_build_query($query)))->assertOk();
    }

    /** @param  list<string>  $expectedKeys */
    private function assertIds(array $expectedKeys, TestResponse $response, string $message = ''): void
    {
        $this->assertSame(
            array_map(fn (string $key) => $this->requests[$key]->id, $expectedKeys),
            collect($response->json('data'))->pluck('id')->all(),
            $message,
        );
    }

    private function request(
        MaterialRequestStatus $status,
        Shift $shift,
        ?WorkCenter $workCenter,
        string $requestedAt,
        Item $item,
        string $quantity,
    ): MaterialRequest {
        $request = MaterialRequest::create([
            'status' => $status,
            'shift_id' => $shift->id,
            'work_center_id' => $workCenter?->id,
            'requested_at' => $requestedAt,
        ]);

        $request->lines()->create([
            'item_id' => $item->id,
            'quantity' => $quantity,
            'uom' => $item->uom,
        ]);

        return $request;
    }

    /** @param  list<string>  $permissions */
    private function actingWith(array $permissions): User
    {
        $this->app['auth']->forgetGuards();

        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }
}
