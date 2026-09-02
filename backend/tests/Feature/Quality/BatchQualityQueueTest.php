<?php

namespace Tests\Feature\Quality;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GET /quality/batch-quality-queue — the Production QC queue, answered by
 * the database instead of built in the browser.
 *
 * The screen used to walk every page of `status=pending`, keep the rows
 * whose `quality` block said unchecked-and-gated and whose `correction`
 * block said not-sent-back, and re-sort them oldest first — two hundred
 * batches was two hundred rows in memory with no pager and no search. The
 * endpoint's contract is the same three questions asked in SQL, and what
 * must hold:
 *
 *   - MEMBERSHIP: exactly the rows the resource's own flags would have
 *     kept — pending · completed · unchecked · not awaiting correction.
 *     PARITY IS THE TEST: for a mixed fixture the queue equals the rows of
 *     the unfiltered production list whose flags say so, row for row;
 *   - ORDER: oldest first (production_date, then id) — a queue is worked
 *     front to back;
 *   - `q` narrows on the batch number, the product and the machine;
 *   - the total is the queue's, and per_page is 1..100;
 *   - with the stage switched off the queue is EMPTY and says so in meta,
 *     with the count of batches going straight to the Plant Manager;
 *   - reading it needs BOTH quality.view and production.view — exactly
 *     what the screen needed when it built the queue itself. Relaxing that
 *     is an owner's call, not this endpoint's.
 */
class BatchQualityQueueTest extends TestCase
{
    use RefreshDatabase;

    private Shift $shift;

    private WorkCenter $machineOne;

    private WorkCenter $machineTwo;

    private Item $amber;

    private Item $clear;

    private Warehouse $fg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->machineOne = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'is_active' => true]);
        $this->machineTwo = WorkCenter::create(['code' => 'MC-02', 'name' => 'Husky Two', 'is_active' => true]);
        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg']);
        $this->amber = Item::create([
            'sku' => 'BTL-500-AMB', 'name' => '500 ml Round Amber', 'uom' => 'Nos.',
            'is_active' => true, 'nominal_weight_grams' => '12.9000', 'tally_stock_item_guid' => 'itm-amber',
        ]);
        $this->clear = Item::create([
            'sku' => 'BTL-1L-CLR', 'name' => '1 Litre Pet Bottle - Clear', 'uom' => 'Nos.',
            'is_active' => true, 'nominal_weight_grams' => '24.0000', 'tally_stock_item_guid' => 'itm-clear',
        ]);
    }

    /** @param  list<string>  $permissions */
    private function actAs(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);

        return $user;
    }

    /** @param  array<string, mixed>  $overrides */
    private function entry(string $batchNumber, string $productionDate, array $overrides = []): ShiftProductionEntry
    {
        return ShiftProductionEntry::create(array_merge([
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machineOne->id,
            'item_id' => $this->amber->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => $productionDate,
            'batch_number' => $batchNumber,
            'batch_status' => BatchStatus::Completed,
            'quantity_produced' => '100',
            'quantity_scrap' => '0',
            'status' => ShiftProductionEntryStatus::Pending,
        ], $overrides));
    }

    /** A quality return as returnToProduction() writes it. */
    private function return(string $reason = 'recount'): array
    {
        return ['returned_by' => 7, 'returned_at' => '2026-08-10T10:00:00+00:00', 'reason' => $reason, 'cleared_quality_check' => false];
    }

    /** An amendment as amendCompletion() writes it — answered_returns = count(quality_returns) at the time. */
    private function amendment(int $answered): array
    {
        return ['amended_by' => 3, 'amended_at' => '2026-08-10T11:00:00+00:00', 'reason' => 'typo', 'answered_returns' => $answered, 'previous_quantity_produced' => '90', 'previous_completed_by' => 3];
    }

    /** @param  array<string, mixed>  $query */
    private function queue(array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/quality/batch-quality-queue?'.http_build_query($query))->assertOk();
    }

    /** @return list<int> */
    private function queueIds(array $query = []): array
    {
        return array_map(fn (array $row) => $row['id'], $this->queue($query)->json('data'));
    }

    public function test_the_queue_is_exactly_the_batches_the_resource_flags_say_are_waiting_oldest_first(): void
    {
        $this->actAs(['quality.view', 'production.view']);

        // Waiting — in the order the desk must see them: the oldest day first,
        // and within a day the batch that was created first.
        $oldest = $this->entry('B-OLD', '2026-08-08');
        $middleA = $this->entry('B-MID-A', '2026-08-09');
        $middleB = $this->entry('B-MID-B', '2026-08-09', ['work_center_id' => $this->machineTwo->id, 'item_id' => $this->clear->id]);
        $returnedThenFixed = $this->entry('B-FIXED', '2026-08-10', ['config_snapshot' => ['quality_returns' => [$this->return()], 'amendments' => [$this->amendment(1)]]]);
        $emptyArrays = $this->entry('B-EMPTY', '2026-08-10', ['config_snapshot' => ['quality_returns' => [], 'amendments' => []]]);
        $typoFixOnly = $this->entry('B-TYPO', '2026-08-10', ['config_snapshot' => ['amendments' => [$this->amendment(0)]]]);

        // Not waiting: with the floor, already checked, elsewhere in the chain, or not work.
        $returned = $this->entry('B-RETURNED', '2026-08-07', ['config_snapshot' => ['quality_returns' => [$this->return()]]]);
        $returnedTwiceFixedOnce = $this->entry('B-RET2-FIX1', '2026-08-07', ['config_snapshot' => ['quality_returns' => [$this->return('a'), $this->return('b')], 'amendments' => [$this->amendment(1)]]]);
        $checked = $this->entry('B-CHECKED', '2026-08-07', ['quality_checked_at' => '2026-08-10 12:00:00', 'quality_reviewed_nos' => 100, 'quality_ok_nos' => 100, 'quality_rejected_nos' => 0]);
        $pmApproved = $this->entry('B-PM', '2026-08-07', ['status' => ShiftProductionEntryStatus::PmApproved]);
        $rejected = $this->entry('B-REJ', '2026-08-07', ['status' => ShiftProductionEntryStatus::Rejected]);
        $running = $this->entry('B-RUN', '2026-08-07', ['batch_status' => BatchStatus::InProgress, 'quantity_produced' => null]);
        $cancelled = $this->entry('B-CANCEL', '2026-08-07', ['batch_status' => BatchStatus::Cancelled]);

        $expected = [$oldest->id, $middleA->id, $middleB->id, $returnedThenFixed->id, $emptyArrays->id, $typoFixOnly->id];
        $this->assertSame($expected, $this->queueIds(['per_page' => 100]), 'the queue, oldest first, id breaking the tie');

        foreach ([$returned, $returnedTwiceFixedOnce, $checked, $pmApproved, $rejected, $running, $cancelled] as $absent) {
            $this->assertNotContains($absent->id, $expected, $absent->batch_number);
        }

        // PARITY: the same rows the browser used to keep from the unfiltered
        // production list, judged on the resource's own flags.
        $unfiltered = $this->getJson('/api/v1/production/shift-production-entries?status=pending&per_page=100')->assertOk()->json('data');
        $derived = [];
        foreach ($unfiltered as $row) {
            if (($row['quality']['stage_enabled'] ?? null) === true
                && ($row['quality']['checked'] ?? null) === false
                && ($row['correction']['awaiting_correction'] ?? null) !== true) {
                $derived[] = $row['id'];
            }
        }
        sort($derived);
        $sortedExpected = $expected;
        sort($sortedExpected);
        $this->assertSame($sortedExpected, $derived, 'the SQL keeps exactly the rows the resource flags keep');

        // Every queued row carries the flags the queue promised.
        foreach ($this->queue(['per_page' => 100])->json('data') as $row) {
            $this->assertTrue($row['quality']['stage_enabled'], $row['batch_number']);
            $this->assertFalse($row['quality']['checked'], $row['batch_number']);
            $this->assertFalse($row['correction']['awaiting_correction'], $row['batch_number']);
        }

        // The page is cut AFTER the filter: the total is the queue's.
        $page = $this->queue(['per_page' => 4]);
        $this->assertCount(4, $page->json('data'));
        $this->assertSame(6, $page->json('meta.total'));
        $this->assertSame(2, $page->json('meta.last_page'));
        $this->assertSame(20, $this->queue()->json('meta.per_page'), 'twenty to a page when not asked');
        $this->assertSame([$emptyArrays->id, $typoFixOnly->id], $this->queueIds(['per_page' => 4, 'page' => 2]));

        // The stage is on, and there is nothing to say about the batches
        // going past it.
        $this->assertTrue($page->json('meta.stage_enabled'));
        $this->assertNull($page->json('meta.pending_count'));
    }

    public function test_q_narrows_on_the_batch_number_the_product_and_the_machine(): void
    {
        $this->actAs(['quality.view', 'production.view']);

        $amberOnOne = $this->entry('MC01-0810-01', '2026-08-10');
        $clearOnTwo = $this->entry('MC02-0810-01', '2026-08-10', ['work_center_id' => $this->machineTwo->id, 'item_id' => $this->clear->id]);
        $amberOnTwo = $this->entry('MC02-0810-02', '2026-08-10', ['work_center_id' => $this->machineTwo->id]);

        $this->assertSame([$amberOnOne->id], $this->queueIds(['q' => 'mc01-0810']), 'batch number, any case');
        $this->assertSame([$clearOnTwo->id, $amberOnTwo->id], $this->queueIds(['q' => 'MC02-0810']), 'batch number prefix, oldest first');
        $this->assertSame([$amberOnOne->id, $amberOnTwo->id], $this->queueIds(['q' => 'amber']), 'product name');
        $this->assertSame([$clearOnTwo->id], $this->queueIds(['q' => 'btl-1l']), 'product sku');
        $this->assertSame([$clearOnTwo->id, $amberOnTwo->id], $this->queueIds(['q' => 'husky']), 'machine name');
        $this->assertSame([$amberOnOne->id], $this->queueIds(['q' => 'MC-01']), 'machine code');
        $this->assertSame([], $this->queueIds(['q' => 'zebra']));
        $this->assertSame([], $this->queueIds(['q' => '%%%']), 'a wildcard is a character');
        $this->assertSame([], $this->queueIds(['q' => 'MC01_0810']), 'an underscore is a character');
        $this->assertSame([$amberOnOne->id], $this->queueIds(['q' => '  mc01-0810  ']), 'trimmed');

        $narrowed = $this->queue(['q' => 'MC02', 'per_page' => 1]);
        $this->assertSame(2, $narrowed->json('meta.total'), 'the total is the matching set\'s');
        $this->assertSame(2, $narrowed->json('meta.last_page'));
    }

    public function test_a_bad_page_size_or_an_over_long_term_is_refused(): void
    {
        $this->actAs(['quality.view', 'production.view']);

        $this->getJson('/api/v1/quality/batch-quality-queue?per_page=0')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/v1/quality/batch-quality-queue?per_page=101')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/v1/quality/batch-quality-queue?page=0')->assertUnprocessable()->assertJsonValidationErrors(['page']);
        $this->getJson('/api/v1/quality/batch-quality-queue?q='.str_repeat('a', 101))->assertUnprocessable()->assertJsonValidationErrors(['q']);
        // A key the queue does not know is ignored — its membership is not
        // something a query string may widen.
        $this->entry('B-ONLY', '2026-08-10');
        $this->assertCount(1, $this->queueIds(['status' => 'approved', 'awaiting_correction' => 'maybe']));
    }

    public function test_with_the_stage_switched_off_the_queue_is_empty_and_says_so(): void
    {
        config(['production.approvals.quality_stage_enabled' => false]);
        $this->actAs(['quality.view', 'production.view']);

        $this->entry('B-A', '2026-08-10');
        $this->entry('B-B', '2026-08-10');
        $this->entry('B-RUN', '2026-08-10', ['batch_status' => BatchStatus::InProgress, 'quantity_produced' => null]);

        $response = $this->queue();
        $this->assertSame([], $response->json('data'), 'the check endpoint refuses while the stage is off, so the queue offers no work');
        $this->assertSame(0, $response->json('meta.total'));
        $this->assertFalse($response->json('meta.stage_enabled'));
        $this->assertSame(2, $response->json('meta.pending_count'), 'the completed batches going straight to the Plant Manager');
    }

    public function test_reading_the_queue_needs_both_quality_view_and_production_view(): void
    {
        $this->entry('B-A', '2026-08-10');

        $this->actAs(['quality.view', 'quality.manage']);
        $this->getJson('/api/v1/quality/batch-quality-queue')->assertForbidden();

        $this->actAs(['production.view', 'production.manage']);
        $this->getJson('/api/v1/quality/batch-quality-queue')->assertForbidden();

        $this->actAs(['quality.view', 'production.view']);
        $this->assertCount(1, $this->queue()->json('data'));
    }
}
