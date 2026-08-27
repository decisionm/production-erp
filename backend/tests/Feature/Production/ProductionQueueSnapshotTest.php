<?php

namespace Tests\Feature\Production;

use App\Modules\Production\Services\FulfilmentPlanningService;
use App\Modules\Production\Services\ProductionQueueService;
use App\Modules\Production\Services\ProductionRequestService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * ONE QUEUE, READ ONCE.
 *
 * The screen's dates and the screen's rows come from two reads of the same
 * open-request set: FulfilmentPlanningService::plan() walks the queue to work
 * out what is ahead of what, and ProductionRequestService::queue() reads the
 * rows themselves. Codex's review of eb1cfb8 found the gap between them —
 * a reorder, start or cancellation committing in that gap makes the response
 * pair the OLD queue's estimates with the NEW queue's rows, so `queued_ahead`
 * and the readiness date describe an order the screen is no longer showing.
 *
 * The fix is a single snapshot around both reads rather than a merge of two.
 * That is what is pinned here: `plan()` runs inside the same open transaction
 * the row read does, which is what makes the database hand both the same view
 * of `production_requests` (REPEATABLE READ on the live MySQL; a transaction
 * is a consistent read on SQLite too).
 *
 * This asserts a mechanism rather than an output on purpose — the snapshot IS
 * the guarantee, and a same-process test cannot stage a concurrent commit
 * from another connection to observe it any other way.
 */
class ProductionQueueSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_planning_read_and_the_row_read_share_one_transaction(): void
    {
        // RefreshDatabase already holds one open, so depth is read relative
        // to where this test started, never as an absolute.
        $outer = DB::transactionLevel();
        $planLevel = null;
        $rowLevel = null;

        $planning = Mockery::mock(FulfilmentPlanningService::class);
        $planning->shouldReceive('plan')->once()->andReturnUsing(function () use (&$planLevel) {
            $planLevel = DB::transactionLevel();

            return ['data' => [], 'basis' => ['source' => 'test']];
        });

        $requests = Mockery::mock(ProductionRequestService::class);
        $requests->shouldReceive('queue')->once()->andReturnUsing(function () use (&$rowLevel) {
            $rowLevel = DB::transactionLevel();

            return new EloquentCollection;
        });

        (new ProductionQueueService($requests, $planning))->queue();

        $this->assertSame($outer + 1, $planLevel, 'plan() must run inside the queue read transaction.');
        $this->assertSame($outer + 1, $rowLevel, 'the row read must run inside the same transaction.');
    }

    public function test_the_read_leaves_no_transaction_open(): void
    {
        $outer = DB::transactionLevel();

        app(ProductionQueueService::class)->queue();

        // A read that forgot to close its snapshot would hold row versions on
        // the live server for as long as the process lived.
        $this->assertSame($outer, DB::transactionLevel());
    }
}
