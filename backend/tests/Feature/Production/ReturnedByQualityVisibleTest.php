<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Task 1 of the "Returned by Quality" plan (03-Sep-2026): the data behind a
 * Quality return already exists —
 * ShiftProductionEntryService::returnToProduction appends
 * {returned_by, returned_at, reason, cleared_quality_check} to
 * config_snapshot['quality_returns'] on every return — but nothing reads it
 * back. This proves ShiftProductionEntryResource now surfaces the LAST row
 * as `quality_return`, with the name resolved rather than a raw id, and
 * null when the batch has never been returned.
 *
 * NO RULE CHANGES ARE UNDER TEST HERE: who may return, when, and what a
 * return does to stock stay exactly BatchAmendmentAndQcReturnTest's
 * territory. This file only reads the payload.
 *
 * Fixture copied from BatchQualityStageTest's own setUp/actAs/completedBatch
 * helpers, per the task brief, rather than inventing a new one.
 */
class ReturnedByQualityVisibleTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Item $resin;

    private Warehouse $fg;

    private Warehouse $rm;

    private Shift $shift;

    private WorkCenter $machine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'is_active' => true]);

        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg']);
        $this->rm = Warehouse::create(['code' => 'RM', 'name' => 'RM Store', 'is_active' => true, 'tally_guid' => 'gd-rm']);

        $this->resin = Item::create([
            'sku' => 'PET-IV08', 'name' => 'Billion Pet Resin IV-0.8', 'uom' => 'Kgs.',
            'is_active' => true, 'tally_stock_item_guid' => 'itm-resin',
        ]);

        $this->bottle = Item::create([
            'sku' => 'BTL-500-AMB', 'name' => '500 ml Round Amber', 'uom' => 'Nos.',
            'is_active' => true, 'nominal_weight_grams' => '12.9000',
            'standard_cycle_time' => '12.00', 'standard_cavities' => 5, 'nos_per_box' => 800,
            'colour' => 'Amber', 'tally_stock_item_guid' => 'itm-bottle',
        ]);

        app(StockMovementService::class)->recordReceipt(
            itemId: $this->resin->id, warehouseId: $this->rm->id,
            quantity: '1000', unitCost: '0', reference: 'opening', createdBy: null,
        );
    }

    /** A fresh user every call, exactly as the live desks are different people. */
    private function actAs(string ...$roles): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $permissions = ['production.view', 'production.manage', 'quality.view', 'quality.manage'];
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);
        foreach ($roles as $role) {
            $user->assignRole(Role::findOrCreate($role, 'web'));
        }
        Sanctum::actingAs($user);

        return $user;
    }

    /** Start → complete, as the supervisor. Returns the entry id. */
    private function completedBatch(): int
    {
        $entryId = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => '2026-07-30',
        ])->assertOk()->json('data.id');

        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => '10000',
            'running_hours' => '8',
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'warehouse_id' => $this->rm->id, 'quantity_issued_kg' => '130'],
            ],
        ])->assertOk();

        return $entryId;
    }

    private function entryJson(int $entryId, string $status = 'pending'): array
    {
        return collect($this->getJson("/api/v1/production/shift-production-entries?status={$status}")->assertOk()->json('data'))
            ->firstWhere('id', $entryId);
    }

    /** The quality queue, one page, exactly as BatchQualityQueueTest reads it. */
    private function queue(array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/quality/batch-quality-queue?'.http_build_query($query))->assertOk();
    }

    /** @return list<int> */
    private function queueIds(array $query = []): array
    {
        return array_map(fn (array $row) => $row['id'], $this->queue($query)->json('data'));
    }

    /** The same completion payload completedBatch() posts — reused for /amend so the piece count never moves. */
    private function amendWithSameFigures(int $entryId): TestResponse
    {
        return $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/amend", [
            'quantity_produced' => '10000',
            'running_hours' => '8',
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'warehouse_id' => $this->rm->id, 'quantity_issued_kg' => '130'],
            ],
        ]);
    }

    public function test_a_batch_sent_back_by_quality_says_so_in_its_payload(): void
    {
        $this->actAs();
        $neverReturnedId = $this->completedBatch();

        $this->actAs();
        $entryId = $this->completedBatch();

        // The never-returned batch carries a null quality_return, both on
        // its own response shape and through the index (collection()).
        $this->assertNull($this->entryJson($neverReturnedId)['quality_return']);

        // ---- Quality checks the batch, then sends it back --------------
        $checker = $this->actAs();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/quality-check", [
            'reviewed_nos' => 10000,
            'ok_nos' => 9800,
            'rejected_nos' => 200,
            'note' => 'Short fill on two trays.',
        ])->assertOk();

        $firstReason = 'Box count on the sheet does not match the pallets on the floor — recount.';
        $returned = $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/return-to-production", [
            'reason' => $firstReason,
        ])->assertOk();

        // The return endpoint's own response is a single resource, not built
        // through collection() — this proves the name resolves with no page
        // of Tally-link-style precomputation to lean on.
        $this->assertSame($firstReason, $returned->json('data.quality_return.reason'));
        $this->assertSame($checker->name, $returned->json('data.quality_return.returned_by_name'));
        $this->assertSame(1, $returned->json('data.quality_return.times'));
        $this->assertNotNull($returned->json('data.quality_return.returned_at'));

        // The index — through collection(), the batch-safe path a page of
        // rows actually uses.
        $indexed = $this->entryJson($entryId);
        $this->assertSame($firstReason, $indexed['quality_return']['reason']);
        $this->assertSame($checker->name, $indexed['quality_return']['returned_by_name']);
        $this->assertSame(1, $indexed['quality_return']['times']);

        // The untouched batch is still null throughout.
        $this->assertNull($this->entryJson($neverReturnedId)['quality_return']);

        // ---- A second return: times increments, reason is the newer one ----
        $secondChecker = $this->actAs();
        $secondReason = 'Still short by a pallet — count the rejects too.';
        $twiceReturned = $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/return-to-production", [
            'reason' => $secondReason,
        ])->assertOk();

        $this->assertSame($secondReason, $twiceReturned->json('data.quality_return.reason'));
        $this->assertSame($secondChecker->name, $twiceReturned->json('data.quality_return.returned_by_name'));
        $this->assertSame(2, $twiceReturned->json('data.quality_return.times'));

        $indexedTwice = $this->entryJson($entryId);
        $this->assertSame($secondReason, $indexedTwice['quality_return']['reason']);
        $this->assertSame(2, $indexedTwice['quality_return']['times']);
    }

    /**
     * A row written before this key existed is not guaranteed to be an
     * array — returnToProduction() itself only ever appends well-formed
     * rows, so this has to be simulated directly on the model. Spliced
     * between two good rows (not appended at the end), so a read that
     * merely trusted the last array offset — rather than filtering every
     * row through is_array — would also happen to pass; this pins the
     * filter, not the position.
     */
    public function test_a_malformed_legacy_row_is_skipped_but_the_good_rows_still_count(): void
    {
        $this->actAs();
        $entryId = $this->completedBatch();

        $this->actAs();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/return-to-production", [
            'reason' => 'First real reason.',
        ])->assertOk();

        $secondChecker = $this->actAs();
        $secondReason = 'Second real reason — the one that should win.';
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/return-to-production", [
            'reason' => $secondReason,
        ])->assertOk();

        $entry = ShiftProductionEntry::query()->findOrFail($entryId);
        $snapshot = $entry->config_snapshot;
        $snapshot['quality_returns'] = [
            $snapshot['quality_returns'][0],
            'a legacy string, not a row',
            $snapshot['quality_returns'][1],
        ];
        $entry->config_snapshot = $snapshot;
        $entry->save();

        $indexed = $this->entryJson($entryId);

        // The malformed row does not count, and the LAST GOOD row still wins
        // — not the last row by array position, which would be the string.
        $this->assertSame(2, $indexed['quality_return']['times']);
        $this->assertSame($secondReason, $indexed['quality_return']['reason']);
        $this->assertSame($secondChecker->name, $indexed['quality_return']['returned_by_name']);
    }

    /**
     * Task 2: the Quality queue's `returned=1` filter.
     *
     * IT DOES NOT MEAN "every batch ever returned" — it narrows the queue's
     * OWN membership (whereAwaitingQualityCheck: pending · completed ·
     * unchecked · no return outstanding), so a batch still sitting with the
     * floor unfixed is not a queue member at all, with or without the flag.
     * The only rows `returned=1` can ever surface are ones that were sent
     * back AND the floor has since re-submitted them — a batch back here for
     * a second look, not a batch new to the desk. That intersection is what
     * this test pins.
     */
    public function test_the_queue_returns_only_the_returned_and_resubmitted_batch_when_asked(): void
    {
        $this->actAs();
        $neverReturnedId = $this->completedBatch();

        $this->actAs();
        $returnedId = $this->completedBatch();

        $this->actAs();
        $this->postJson("/api/v1/production/shift-production-entries/{$returnedId}/return-to-production", [
            'reason' => 'Recount the boxes on this pallet — the sheet says 40, the floor has 38.',
        ])->assertOk();

        // STILL AWAITING CORRECTION: not a queue member at all yet, so
        // returned=1 must not surface it either — the flag only narrows the
        // queue, it can never widen it.
        $this->assertNotContains($returnedId, $this->queueIds());
        $this->assertNotContains($returnedId, $this->queueIds(['returned' => 1]));

        // The floor corrects it — the SAME figures it completed with, so the
        // piece count does not move and refuseStaleMaterialLines lets the
        // unchanged material line through untouched.
        $this->actAs();
        $this->amendWithSameFigures($returnedId)->assertOk();

        // Default: both the never-returned batch and the returned-then-fixed
        // one are in the queue — a corrected batch is ordinary work again.
        $default = $this->queueIds();
        $this->assertContains($neverReturnedId, $default);
        $this->assertContains($returnedId, $default);

        // returned=1: only the one that was ever sent back.
        $this->assertSame([$returnedId], $this->queueIds(['returned' => 1]));

        $narrowed = $this->queue(['returned' => 1]);
        $this->assertSame(1, $narrowed->json('meta.total'), 'the total is the filtered set\'s');

        // A falsy or absent value never turns the switch on.
        $this->assertSame($default, $this->queueIds(['returned' => 0]));
        $this->assertSame($default, $this->queueIds());
    }
}
