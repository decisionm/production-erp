<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Enums\ShiftScrapType;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\DayBinLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 7 (P7-03 (g)) — the two work-queue filters on GET
 * /production/shift-production-entries, answered IN SQL before the page is
 * cut (no more walking every page of status=pending and filtering in the
 * browser):
 *
 *   correctable=1          status pending · batch_status completed ·
 *                          quality not checked — the frontend's
 *                          canAmendCompletion, row for row;
 *   awaiting_correction=1  the subset quality sent back that the floor has
 *                          not yet re-submitted — correction.awaiting_
 *                          correction on the resource, row for row
 *                          (count(quality_returns) > max(amendments[].
 *                          answered_returns), walked in JSON per driver).
 *
 * PARITY IS THE TEST: for a mixed fixture the server's filtered list must
 * equal the rows of the UNFILTERED list whose resource-derived flags are
 * true — the SQL mirrors the derivation or this fails. The fixture is
 * written twice: every shape the two writers (returnToProduction /
 * amendCompletion) can leave on the snapshot, by hand; and the real round
 * trip through the API (complete → return → amend → return → check), so
 * the shapes the SQL relies on are the ones the code actually writes.
 * RED before: the two keys were not validated (ignored) and the list
 * answered the whole queue.
 */
class EntriesIndexWorkQueueFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Shift $shift;

    private WorkCenter $machine;

    private Item $bottle;

    private Item $resin;

    private Warehouse $fg;

    private Warehouse $rm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'is_active' => true]);
        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg']);
        $this->rm = Warehouse::create(['code' => 'RM', 'name' => 'RM Store', 'is_active' => true, 'tally_guid' => 'gd-rm']);
        $this->resin = Item::create([
            'sku' => 'PET-IV08', 'name' => 'PET Resin', 'uom' => 'Kgs.',
            'is_active' => true, 'tally_stock_item_guid' => 'itm-resin',
        ]);
        $this->bottle = Item::create([
            'sku' => 'BTL-500-AMB', 'name' => '500 ml Round Amber', 'uom' => 'Nos.',
            'is_active' => true, 'nominal_weight_grams' => '12.9000',
            'standard_cycle_time' => '12.00', 'standard_cavities' => 5, 'nos_per_box' => 800,
            'colour' => 'Amber', 'tally_stock_item_guid' => 'itm-bottle',
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
    private function entry(string $label, array $overrides = []): ShiftProductionEntry
    {
        static $sequence = 0;
        $sequence++;

        return ShiftProductionEntry::create(array_merge([
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => '2026-08-10',
            'batch_number' => sprintf('%03d-%s', $sequence, $label),
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

    /** @return list<int> */
    private function ids(string $query): array
    {
        $response = $this->getJson('/api/v1/production/shift-production-entries?'.$query)->assertOk();

        return array_map(fn (array $row) => $row['id'], $response->json('data'));
    }

    /**
     * The resource-derived flags, exactly as the frontend reads them:
     * canAmendCompletion (types.ts) and isAwaitingCorrection.
     *
     * @return array{correctable: list<int>, awaiting: list<int>}
     */
    private function derivedFromTheUnfilteredList(string $extra = ''): array
    {
        $rows = $this->getJson('/api/v1/production/shift-production-entries?per_page=100'.$extra)->assertOk()->json('data');
        $correctable = [];
        $awaiting = [];
        foreach ($rows as $row) {
            if ($row['status'] === 'pending' && $row['batch_status'] === 'completed' && ($row['quality']['checked'] ?? null) !== true) {
                $correctable[] = $row['id'];
            }
            if (($row['correction']['awaiting_correction'] ?? null) === true) {
                $awaiting[] = $row['id'];
            }
        }

        return ['correctable' => $correctable, 'awaiting' => $awaiting];
    }

    // ---- parity on every snapshot shape the writers can leave ------------------

    public function test_the_two_filters_answer_exactly_the_rows_whose_resource_flags_are_true(): void
    {
        $this->actAs(['production.view', 'production.manage']);

        // Correctable, not awaiting.
        $bare = $this->entry('bare-pending');
        $emptyArrays = $this->entry('empty-arrays', ['config_snapshot' => ['quality_returns' => [], 'amendments' => []]]);
        $otherKeysOnly = $this->entry('colour-only', ['config_snapshot' => ['colour' => 'Amber', 'unit_weight_grams' => '12.9']]);
        $returnedThenFixed = $this->entry('returned-then-fixed', ['config_snapshot' => ['quality_returns' => [$this->return()], 'amendments' => [$this->amendment(1)]]]);
        $typoThenReturnThenFixed = $this->entry('typo-return-fixed', ['config_snapshot' => ['quality_returns' => [$this->return()], 'amendments' => [$this->amendment(0), $this->amendment(1)]]]);
        $twoReturnsBothFixed = $this->entry('two-returns-both-fixed', ['config_snapshot' => ['quality_returns' => [$this->return('a'), $this->return('b')], 'amendments' => [$this->amendment(1), $this->amendment(2)]]]);
        $typoFixOnly = $this->entry('typo-fix-only', ['config_snapshot' => ['amendments' => [$this->amendment(0)]]]);

        // Correctable AND awaiting.
        $returned = $this->entry('returned', ['config_snapshot' => ['quality_returns' => [$this->return()]]]);
        $returnedTwiceFixedOnce = $this->entry('returned-twice-fixed-once', ['config_snapshot' => ['quality_returns' => [$this->return('a'), $this->return('b')], 'amendments' => [$this->amendment(1)]]]);
        $typoThenReturned = $this->entry('typo-then-returned', ['config_snapshot' => ['quality_returns' => [$this->return()], 'amendments' => [$this->amendment(0)]]]);
        $returnedWithEmptyAmendments = $this->entry('returned-empty-amendments', ['config_snapshot' => ['quality_returns' => [$this->return()], 'amendments' => []]]);

        // Neither: the column facts fail, whatever the snapshot says.
        $returnedButChecked = $this->entry('returned-but-checked', ['quality_checked_at' => '2026-08-10 12:00:00', 'quality_reviewed_nos' => 100, 'quality_ok_nos' => 100, 'quality_rejected_nos' => 0, 'config_snapshot' => ['quality_returns' => [$this->return()]]]);
        $returnedButPmApproved = $this->entry('returned-but-pm-approved', ['status' => ShiftProductionEntryStatus::PmApproved, 'config_snapshot' => ['quality_returns' => [$this->return()]]]);
        $returnedButRejected = $this->entry('returned-but-rejected', ['status' => ShiftProductionEntryStatus::Rejected, 'config_snapshot' => ['quality_returns' => [$this->return()]]]);
        $returnedButRunning = $this->entry('returned-but-running', ['batch_status' => BatchStatus::InProgress, 'quantity_produced' => null, 'config_snapshot' => ['quality_returns' => [$this->return()]]]);
        $checkedPlain = $this->entry('checked', ['quality_checked_at' => '2026-08-10 12:00:00', 'quality_reviewed_nos' => 100, 'quality_ok_nos' => 100, 'quality_rejected_nos' => 0]);
        $running = $this->entry('running', ['batch_status' => BatchStatus::InProgress, 'quantity_produced' => null]);
        $approved = $this->entry('approved', ['status' => ShiftProductionEntryStatus::Approved]);
        $cancelled = $this->entry('cancelled', ['batch_status' => BatchStatus::Cancelled, 'config_snapshot' => ['quality_returns' => [$this->return()]]]);

        $expectedCorrectable = [$bare, $emptyArrays, $otherKeysOnly, $returnedThenFixed, $typoThenReturnThenFixed, $twoReturnsBothFixed, $typoFixOnly, $returned, $returnedTwiceFixedOnce, $typoThenReturned, $returnedWithEmptyAmendments];
        $expectedAwaiting = [$returned, $returnedTwiceFixedOnce, $typoThenReturned, $returnedWithEmptyAmendments];
        $neither = [$returnedButChecked, $returnedButPmApproved, $returnedButRejected, $returnedButRunning, $checkedPlain, $running, $approved, $cancelled];

        $sortedIds = fn (array $entries) => collect($entries)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $sorted = fn (array $ids) => collect($ids)->sort()->values()->all();

        // The hand-written expectation, the resource's derivation and the
        // server's SQL — all three agree.
        $derived = $this->derivedFromTheUnfilteredList();
        $this->assertSame($sortedIds($expectedCorrectable), $sorted($derived['correctable']), 'the resource derives correctable as the fixture intends');
        $this->assertSame($sortedIds($expectedAwaiting), $sorted($derived['awaiting']), 'the resource derives awaiting_correction as the fixture intends');

        $serverCorrectable = $this->ids('correctable=1&per_page=100');
        $serverAwaiting = $this->ids('awaiting_correction=1&per_page=100');
        $this->assertSame($sorted($derived['correctable']), $sorted($serverCorrectable), 'correctable=1 in SQL ≡ canAmendCompletion per row');
        $this->assertSame($sorted($derived['awaiting']), $sorted($serverAwaiting), 'awaiting_correction=1 in SQL ≡ correction.awaiting_correction per row');
        foreach ($neither as $entry) {
            $this->assertNotContains($entry->id, $serverCorrectable, $entry->batch_number);
            $this->assertNotContains($entry->id, $serverAwaiting, $entry->batch_number);
        }

        // Newest first, like every answer of this list; the total is the
        // filtered set's, so a page never lies about how much work is queued.
        $this->assertSame(array_reverse($sortedIds($expectedAwaiting)), $serverAwaiting);
        $this->getJson('/api/v1/production/shift-production-entries?awaiting_correction=1&per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.total', count($expectedAwaiting))
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(2, 'data');

        // Every filtered row carries the flag the filter promised.
        foreach ($this->getJson('/api/v1/production/shift-production-entries?awaiting_correction=1&per_page=100')->json('data') as $row) {
            $this->assertTrue($row['correction']['awaiting_correction'], $row['batch_number']);
        }

        // Composable with the rest of the grammar.
        $this->assertSame($serverAwaiting, $this->ids('awaiting_correction=1&status=pending&production_date=2026-08-10&per_page=100'));
        $this->assertSame([], $this->ids('awaiting_correction=1&production_date=2026-08-09&per_page=100'));
        $this->assertSame($sorted($serverAwaiting), $sorted($this->ids('awaiting_correction=1&correctable=1&per_page=100')), 'awaiting is a subset of correctable');
    }

    public function test_the_flags_are_booleans_and_only_a_true_one_filters(): void
    {
        $this->actAs(['production.view', 'production.manage']);
        $this->entry('returned', ['config_snapshot' => ['quality_returns' => [$this->return()]]]);
        $this->entry('plain');

        $unfiltered = $this->ids('per_page=100');
        $this->assertCount(2, $unfiltered);

        // 0 / false / empty are "no filter", never the complement ('false'
        // spelt out too — an axios params object serialises a boolean that
        // way).
        $this->assertSame($unfiltered, $this->ids('awaiting_correction=0&per_page=100'));
        $this->assertSame($unfiltered, $this->ids('awaiting_correction=false&per_page=100'));
        $this->assertSame($unfiltered, $this->ids('awaiting_correction=&per_page=100'));
        $this->assertSame($unfiltered, $this->ids('correctable=0&per_page=100'));
        // true / 1 both ask.
        $this->assertCount(1, $this->ids('awaiting_correction=true&per_page=100'));
        $this->assertCount(1, $this->ids('awaiting_correction=1&per_page=100'));
        $this->assertCount(2, $this->ids('correctable=true&per_page=100'));

        // A value that could only be a mistake is refused, as every other key is.
        $this->getJson('/api/v1/production/shift-production-entries?awaiting_correction=maybe')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['awaiting_correction']);
        $this->getJson('/api/v1/production/shift-production-entries?correctable=yes')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['correctable']);
    }

    public function test_the_filters_are_applied_in_sql_before_the_page_is_cut(): void
    {
        $this->actAs(['production.view', 'production.manage']);
        $this->entry('returned', ['config_snapshot' => ['quality_returns' => [$this->return()]]]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/production/shift-production-entries?awaiting_correction=1')->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // The grammar is the assertion, not the driver's punctuation: sqlite
        // quotes identifiers "like this", MySQL `like this`, and the suite
        // runs on both legs (ci.yml). Strip the quoting, then read the SQL.
        $unquoted = static fn (string $sql): string => str_replace(['"', '`'], '', $sql);

        $lists = array_values(array_filter(
            array_map($unquoted, array_column($log, 'query')),
            fn (string $sql) => str_contains($sql, 'from shift_production_entries') && str_contains($sql, 'quality_checked_at'),
        ));
        $this->assertNotEmpty($lists, 'the list read (and its count) carry the predicate');
        foreach ($lists as $sql) {
            $this->assertStringContainsString('status = ?', $sql);
            $this->assertStringContainsString('batch_status = ?', $sql);
            $this->assertStringContainsString('quality_checked_at is null', $sql);
            $this->assertStringContainsString('quality_returns', $sql, 'the JSON walk is in the WHERE, not in PHP');
            $this->assertStringContainsString('answered_returns', $sql);
        }
    }

    // ---- the real round trip: the writers, then the SQL ------------------------

    public function test_the_shapes_the_real_return_and_amend_paths_write_are_the_shapes_the_sql_reads(): void
    {
        config(['production.scrap.rejected_item_sku' => 'PET-SCRAP']);
        Item::create(['sku' => 'PET-SCRAP', 'name' => 'Pet Scrap', 'uom' => 'Kgs.', 'is_active' => true, 'tally_stock_item_guid' => 'itm-scrap']);
        app(StockMovementService::class)->recordReceipt(itemId: $this->resin->id, warehouseId: $this->rm->id, quantity: '1000', unitCost: '50', reference: 'opening');
        app(DayBinLedgerService::class)->record(['work_center_id' => $this->machine->id, 'item_id' => $this->resin->id, 'type' => 'load', 'quantity_kg' => '200', 'recorded_by' => null]);

        $figures = fn (string $produced, string $resin, string $closing) => [
            'quantity_produced' => $produced, 'running_hours' => '8',
            'material_consumptions' => [['item_id' => $this->resin->id, 'warehouse_id' => $this->rm->id, 'quantity_issued_kg' => $resin]],
            'closing_day_bin' => [['item_id' => $this->resin->id, 'quantity_kg' => $closing]],
            'scraps' => [['type' => ShiftScrapType::Lumps->value, 'quantity_kg' => '2']],
            // The amend door's stale-kg guard is not what this test is
            // about: the kilograms are confirmed as typed on every call.
            'material_kg_confirmed' => true,
        ];
        $supervisor = ['production.view', 'production.manage'];
        $quality = ['quality.view', 'quality.manage', 'production.view'];

        // Start + complete (wrong figures): correctable, not awaiting.
        $this->actAs($supervisor);
        $id = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->shift->id, 'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id, 'warehouse_id' => $this->fg->id, 'production_date' => '2026-07-31',
        ])->assertOk()->json('data.id');
        $this->postJson("/api/v1/production/shift-production-entries/{$id}/complete", $figures('10000', '130', '80'))->assertOk();
        $this->assertSame([$id], $this->ids('correctable=1'));
        $this->assertSame([], $this->ids('awaiting_correction=1'));

        // Quality returns it: awaiting.
        $this->actAs($quality);
        $this->postJson("/api/v1/production/shift-production-entries/{$id}/return-to-production", ['reason' => 'Box count does not match the pallets — recount.'])->assertOk();
        $this->assertSame([$id], $this->ids('awaiting_correction=1'));
        $this->assertSame([$id], $this->ids('correctable=1'));

        // The floor amends: still correctable, no longer awaiting — the
        // ONE fact separating "sent back" from "fixed" is the amendment's
        // answered_returns, and the SQL reads it.
        $this->actAs($supervisor);
        $this->postJson("/api/v1/production/shift-production-entries/{$id}/amend", $figures('9500', '126', '75') + ['amendment_reason' => 'Recounted the pallets.'])->assertOk();
        $this->assertSame([], $this->ids('awaiting_correction=1'));
        $this->assertSame([$id], $this->ids('correctable=1'));

        // A second return re-raises it.
        $this->actAs($quality);
        $this->postJson("/api/v1/production/shift-production-entries/{$id}/return-to-production", ['reason' => 'Still short by a pallet.'])->assertOk();
        $this->assertSame([$id], $this->ids('awaiting_correction=1'));

        // Fixed again, then quality checks it: out of both queues.
        $this->actAs($supervisor);
        $this->postJson("/api/v1/production/shift-production-entries/{$id}/amend", $figures('9600', '126', '75') + ['amendment_reason' => 'Counted the rejects too.'])->assertOk();
        $this->assertSame([], $this->ids('awaiting_correction=1'));
        $this->actAs($quality);
        $this->postJson("/api/v1/production/shift-production-entries/{$id}/quality-check", ['reviewed_nos' => 9600, 'ok_nos' => 9600, 'rejected_nos' => 0])->assertOk();
        $this->assertSame([], $this->ids('correctable=1'));
        $this->assertSame([], $this->ids('awaiting_correction=1'));

        // And at every step the resource agreed (the last state, in full).
        $derived = $this->derivedFromTheUnfilteredList();
        $this->assertSame([], $derived['correctable']);
        $this->assertSame([], $derived['awaiting']);
        $this->assertSame([[1, 2], 2], (function () use ($id) {
            $entry = ShiftProductionEntry::query()->findOrFail($id);

            return [
                array_column($entry->config_snapshot['amendments'], 'answered_returns'),
                count($entry->config_snapshot['quality_returns']),
            ];
        })(), 'the writers stamp answered_returns from the return count — the invariant the SQL rests on');
    }
}
