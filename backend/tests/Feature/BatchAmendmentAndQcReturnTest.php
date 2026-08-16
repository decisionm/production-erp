<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Models\DowntimeReason;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Enums\ShiftScrapType;
use App\Modules\Production\Models\ProductionDowntimeEvent;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftMaterialConsumption;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\ShiftScrap;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\DayBinLedgerService;
use App\Modules\Production\Services\ShiftProductionEntryService;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * CORRECTING A BATCH THAT IS ALREADY COMPLETED, both halves of the owner's
 * rule (31-Jul): "In Production Pending, allow the factory user to edit the
 * production entry until QC starts. Once QC has started, the production
 * figures should no longer be edited directly; QC should return it to
 * Production when correction is needed."
 *
 * So there are exactly two doors, and each is shut the moment the batch stops
 * belonging to whoever is behind it:
 *
 *   the floor's own amend   — open until quality_checked_at is set
 *   quality's return        — open until the plant manager signs
 *
 * and both are shut for good by an approval or a Tally voucher.
 *
 * THE TEST THAT MATTERS IS THE FIRST ONE. A correction is only worth having
 * if, afterwards, the books cannot tell that anything went wrong: the resin
 * the wrong completion issued has to be back, the bottles it received have to
 * be gone, and the consumption, scrap and downtime lines must not have
 * doubled. That is asserted by running the SAME assertion block against a
 * batch that was completed correctly first time and against one that was
 * completed wrong and amended — if the two ever diverge, one of them fails.
 *
 * THE FIXTURE'S ARITHMETIC, once, since everything below leans on it:
 *   bottle = 12.9 g      correct run: 9,500 pcs = 122.5500 kg, 120 kg resin
 *   resin opening 1,000 kg @ ₹50   day bin loaded 200 kg, closing 75 kg
 *   the wrong run said: 10,000 pcs, 130 kg resin, closing 80 kg
 */
class BatchAmendmentAndQcReturnTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Item $resin;

    private Item $scrap;

    private Warehouse $fg;

    private Warehouse $rm;

    private Shift $shift;

    private WorkCenter $machine;

    private DowntimeReason $powerCut;

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

        $this->scrap = Item::create([
            'sku' => 'PET-SCRAP', 'name' => 'Pet Scrap', 'uom' => 'Kgs.',
            'is_active' => true, 'tally_stock_item_guid' => 'itm-scrap',
        ]);
        config(['production.scrap.rejected_item_sku' => 'PET-SCRAP']);

        $this->powerCut = DowntimeReason::create([
            'code' => 'POWER', 'category' => 'utility', 'description' => 'Power interruption',
            'planning_type' => 'unplanned', 'reduces_runtime' => true, 'requires_note' => false,
            'selectable_at_start' => false, 'is_active' => true,
        ]);

        // Resin at a real cost, so a reversal that restored the quantity but
        // not the moving average would show up.
        app(StockMovementService::class)->recordReceipt(
            itemId: $this->resin->id, warehouseId: $this->rm->id,
            quantity: '1000', unitCost: '50', reference: 'opening', createdBy: null,
        );

        // The day bin this machine runs off. Recorded with no segment of its
        // own — the store filling a bin before anyone starts a batch.
        app(DayBinLedgerService::class)->record([
            'work_center_id' => $this->machine->id,
            'item_id' => $this->resin->id,
            'type' => 'load',
            'quantity_kg' => '200',
            'recorded_by' => null,
        ]);
    }

    /**
     * A user holding exactly the permissions named — granular on purpose, so
     * the two 403 tests below can genuinely fail. A fresh user every call also
     * keeps the four-eyes rules satisfied by construction, exactly as the live
     * desks are different people.
     *
     * @param  list<string>  $permissions
     */
    private function actAs(array $permissions, string ...$roles): User
    {
        $user = User::factory()->create(['is_active' => true]);
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

    private function actAsSupervisor(): User
    {
        return $this->actAs(['production.view', 'production.manage']);
    }

    private function actAsChecker(): User
    {
        return $this->actAs(['quality.view', 'quality.manage']);
    }

    private function startBatch(): int
    {
        return $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => '2026-07-31',
        ])->assertOk()->json('data.id');
    }

    /** What the shift actually made — the figures a correct completion carries. */
    private function correctFigures(): array
    {
        return [
            'quantity_produced' => '9500',
            'running_hours' => '8',
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'warehouse_id' => $this->rm->id, 'quantity_issued_kg' => '126'],
            ],
            'closing_day_bin' => [
                ['item_id' => $this->resin->id, 'quantity_kg' => '75'],
            ],
            'scraps' => [
                ['type' => ShiftScrapType::Lumps->value, 'quantity_kg' => '2'],
            ],
            'downtime_events' => [
                ['downtime_reason_id' => $this->powerCut->id, 'minutes' => 15, 'note' => '10:00-10:15 power cut'],
            ],
        ];
    }

    /** The same shift, counted wrong: every figure inflated. */
    private function wrongFigures(): array
    {
        return [
            'quantity_produced' => '10000',
            'running_hours' => '8',
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'warehouse_id' => $this->rm->id, 'quantity_issued_kg' => '130'],
            ],
            'closing_day_bin' => [
                ['item_id' => $this->resin->id, 'quantity_kg' => '80'],
            ],
            'scraps' => [
                ['type' => ShiftScrapType::Lumps->value, 'quantity_kg' => '3'],
            ],
            'downtime_events' => [
                ['downtime_reason_id' => $this->powerCut->id, 'minutes' => 30, 'note' => '10:00-10:30 power cut'],
            ],
        ];
    }

    /**
     * Quantities compared as quantities. Several of these columns carry no
     * decimal cast, so their trailing zeros are whatever the driver returns
     * ('126' on sqlite, '126.0000' on MySQL) — a string comparison would pin
     * the test to a database rather than to the figure.
     */
    private function assertAmount(string $expected, mixed $actual, string $message = ''): void
    {
        $this->assertSame(0, bccomp($expected, (string) $actual, 4), $message !== ''
            ? $message
            : "Expected {$expected}, got ".(string) $actual);
    }

    private function balance(Item $item, Warehouse $warehouse): ?StockBalance
    {
        return StockBalance::query()
            ->where('item_id', $item->id)
            ->where('warehouse_id', $warehouse->id)
            ->first();
    }

    /**
     * THE INVARIANT. Everything a shift that was counted right the first time
     * leaves behind — asserted identically against a corrected batch, which is
     * the only honest way to say "the wrong completion never happened".
     *
     * Two things are deliberately NOT compared, and both are audit trail
     * rather than state: stock_movements keeps the wrong issues AND their
     * reversals (that ledger is append-only by design — "130 kg went out and
     * came back" is what happened), and day_bin_movements keeps the
     * superseded closing count (a count is an absolute observation that
     * re-anchors the ledger, so the corrected one simply wins). What must be
     * identical is every figure anybody reads: balances, the entry's own
     * columns, and the line tables that a second completion would otherwise
     * have doubled.
     */
    private function assertLooksLikeOneCorrectCompletion(int $entryId): void
    {
        $entry = ShiftProductionEntry::query()->findOrFail($entryId);

        $this->assertSame('9500', (string) $entry->quantity_produced);
        $this->assertAmount('122.5500', $entry->quantity_produced_kg);
        $this->assertNull($entry->gross_quantity_produced);
        $this->assertNull($entry->quality_checked_at);
        $this->assertSame(BatchStatus::Completed, $entry->batch_status);
        $this->assertSame(ShiftProductionEntryStatus::Pending, $entry->status);

        // 1,000 kg of resin, less the 126 this shift issued — at the average
        // cost it was received at, untouched by the round trip.
        $resin = $this->balance($this->resin, $this->rm);
        $this->assertSame('874.0000', (string) $resin?->quantity);
        $this->assertSame('50.0000', (string) $resin?->average_cost);

        // The bottles, and nothing but this run's bottles.
        $this->assertAmount('9500.0000', $this->balance($this->bottle, $this->fg)?->quantity);

        // No scrap weight is held at all. A batch whose quality check was
        // returned leaves an emptied balance ROW behind where a never-checked
        // one has none — the row is the ledger's, the figure is the factory's,
        // and it is the figure that has to match.
        $scrapHeld = $this->balance($this->scrap, $this->fg);
        $this->assertAmount('0', $scrapHeld?->quantity ?? '0');

        // One consumption line, one scrap line, one completion downtime line —
        // the numbers that double when a reversal misses something.
        $this->assertSame(1, ShiftMaterialConsumption::query()->where('shift_production_entry_id', $entryId)->count());
        $this->assertAmount('126.0000', ShiftMaterialConsumption::query()
            ->where('shift_production_entry_id', $entryId)->value('quantity_issued_kg'));
        $this->assertSame($this->rm->id, (int) ShiftMaterialConsumption::query()
            ->where('shift_production_entry_id', $entryId)->value('warehouse_id'));

        $this->assertSame(1, ShiftScrap::query()->where('shift_production_entry_id', $entryId)->count());
        $this->assertAmount('2.0000', ShiftScrap::query()
            ->where('shift_production_entry_id', $entryId)->value('quantity_kg'));

        $downtime = ProductionDowntimeEvent::query()->where('shift_production_entry_id', $entryId)->get();
        $this->assertCount(1, $downtime);
        $this->assertAmount('15.00', $downtime->first()->minutes);

        // The day bin reads as one shift that consumed 125 kg — the corrected
        // closing count re-anchors the ledger, so the superseded one changes
        // no figure anybody sees.
        $this->assertSame([
            'opening_kg' => '200.0000',
            'loaded_kg' => '0.0000',
            'returned_kg' => '0.0000',
            'closing_kg' => '75.0000',
            'consumed_kg' => '125.0000',
        ], app(DayBinLedgerService::class)->consumptionFor($entry, $this->resin->id));

        $this->assertSame('75.0000', app(DayBinLedgerService::class)->balanceFor($this->machine->id, $this->resin->id));

        // And the number the accountant actually blocks on: 126 kg issued
        // against 122.55 kg of bottles and 2 kg of lumps. This is the figure a
        // reversal that missed a movement or a doubled line would move, and it
        // has to be the same on a corrected batch as on a right-first-time one.
        $metrics = app(ShiftProductionEntryService::class)->productionMetrics($entry);
        $this->assertAmount('126.0000', $metrics['issued_kg']);
        $this->assertAmount('122.5500', $metrics['good_production_kg']);
        $this->assertAmount('2.0000', $metrics['lumps_kg']);
        $this->assertAmount('1.4500', $metrics['reconciliation_unaccounted_kg']);
        $this->assertAmount('15.00', $metrics['downtime_minutes_total']);
        // And no lingering "this batch issued material the ledger did not
        // have" banner from a completion that was undone.
        $this->assertSame([], $metrics['stock_shortfalls']);
    }

    public function test_a_batch_completed_correctly_the_first_time_leaves_this_world(): void
    {
        $this->actAsSupervisor();
        $entryId = $this->startBatch();

        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $this->correctFigures())
            ->assertOk();

        $this->assertLooksLikeOneCorrectCompletion($entryId);
    }

    public function test_amending_a_wrong_completion_leaves_exactly_what_a_correct_one_would(): void
    {
        $supervisor = $this->actAsSupervisor();
        $entryId = $this->startBatch();

        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $this->wrongFigures())
            ->assertOk();

        // The wrong figures really were booked — otherwise the assertions
        // below would prove nothing.
        $this->assertSame('870.0000', (string) $this->balance($this->resin, $this->rm)?->quantity);
        $this->assertSame('10000.0000', (string) $this->balance($this->bottle, $this->fg)?->quantity);

        $amended = $this->postJson(
            "/api/v1/production/shift-production-entries/{$entryId}/amend",
            [...$this->correctFigures(), 'amendment_reason' => 'Counted the last pallet twice'],
        )->assertOk();

        $this->assertLooksLikeOneCorrectCompletion($entryId);

        // The one thing that IS different from a first-time-right batch, and
        // must be: the trail saying this was corrected, by whom, from what.
        $amendments = $amended->json('data.correction.amendments');
        $this->assertCount(1, $amendments);
        $this->assertSame('Counted the last pallet twice', $amendments[0]['reason']);
        $this->assertSame($supervisor->id, $amendments[0]['amended_by']);
        $this->assertSame('10000', $amendments[0]['previous_quantity_produced']);

        // Still the same batch — a correction is not a new run.
        $this->assertSame($entryId, $amended->json('data.id'));
        $this->assertSame(1, ShiftProductionEntry::query()->count());
    }

    public function test_amending_twice_still_reconciles_and_prices_the_live_lines(): void
    {
        $this->actAsSupervisor();
        $entryId = $this->startBatch();

        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $this->wrongFigures())
            ->assertOk();

        // Wrong again — a supervisor correcting under time pressure gets a
        // second go, and the reversal has to work off the CURRENT lines, not
        // the ones the first completion left.
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/amend", [
            ...$this->wrongFigures(),
            'quantity_produced' => '9800',
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'warehouse_id' => $this->rm->id, 'quantity_issued_kg' => '128'],
            ],
        ])->assertOk();

        $corrected = $this->postJson(
            "/api/v1/production/shift-production-entries/{$entryId}/amend",
            $this->correctFigures(),
        )->assertOk();

        $this->assertLooksLikeOneCorrectCompletion($entryId);
        $this->assertCount(2, $corrected->json('data.correction.amendments'));

        // And the consumption is priced off THIS completion's issue, not off
        // one of the two superseded ones still carrying the same reference in
        // the append-only ledger: 126 kg of resin at ₹50. The supervisor sees
        // the total; the per-line rate is finance's (FC-06 —
        // StockRateVisibilityTest), so it is read back as the accountant.
        $this->assertAmount('6300.0000', $corrected->json('data.material_cost.total_cost'));
        $this->assertArrayNotHasKey('unit_cost', $corrected->json('data.material_cost.lines.0'));

        $this->actAs(['production.view', 'finance.view']);
        $asFinance = $this->getJson('/api/v1/production/shift-production-entries')->assertOk()
            ->json('data');
        $row = collect($asFinance)->firstWhere('id', $entryId);
        $this->assertAmount('50.0000', $row['material_cost']['lines'][0]['unit_cost']);
        $this->assertAmount('6300.0000', $row['material_cost']['total_cost']);
    }

    public function test_whoever_amends_becomes_the_one_who_cannot_certify_the_count(): void
    {
        // Completed by the day supervisor…
        $this->actAsSupervisor();
        $entryId = $this->startBatch();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $this->wrongFigures())
            ->assertOk();

        // …corrected by someone who also sits at the quality desk. Four eyes
        // binds the count to whoever last COUNTED it, so an amendment has to
        // move that signature with it — otherwise correcting a batch is a way
        // of quietly buying the right to certify your own figures.
        $both = $this->actAs(['production.view', 'production.manage', 'quality.view', 'quality.manage']);
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/amend", $this->correctFigures())
            ->assertOk();

        $this->assertSame($both->id, (int) ShiftProductionEntry::query()->find($entryId)->completed_by);

        $refused = $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/quality-check", [
            'reviewed_nos' => 500, 'ok_nos' => 500, 'rejected_nos' => 0,
        ])->assertStatus(422);

        $this->assertStringContainsString(
            'the same person cannot complete a batch and pass its own quality check',
            $refused->json('message'),
        );
    }

    public function test_an_amendment_needs_no_reason_but_is_always_recorded(): void
    {
        $this->actAsSupervisor();
        $entryId = $this->startBatch();

        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $this->wrongFigures())
            ->assertOk();

        $amended = $this->postJson(
            "/api/v1/production/shift-production-entries/{$entryId}/amend",
            $this->correctFigures(),
        )->assertOk();

        $this->assertNull($amended->json('data.correction.amendments.0.reason'));
        $this->assertNotNull($amended->json('data.correction.amendments.0.amended_at'));
        $this->assertLooksLikeOneCorrectCompletion($entryId);
    }

    public function test_the_floor_cannot_amend_once_quality_has_checked_the_batch(): void
    {
        $this->actAsSupervisor();
        $entryId = $this->startBatch();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $this->wrongFigures())
            ->assertOk();

        $this->actAsChecker();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/quality-check", [
            'reviewed_nos' => 500, 'ok_nos' => 500, 'rejected_nos' => 0,
        ])->assertOk();

        $this->actAsSupervisor();
        $refused = $this->postJson(
            "/api/v1/production/shift-production-entries/{$entryId}/amend",
            $this->correctFigures(),
        )->assertStatus(422);

        // And it says who can open the door again, rather than only that it is
        // shut — the supervisor is holding a clipboard, not a status diagram.
        $this->assertStringContainsString('quality has already checked this batch', $refused->json('message'));
        $this->assertStringContainsString('return it to production', $refused->json('message'));

        // Nothing moved.
        $this->assertSame('870.0000', (string) $this->balance($this->resin, $this->rm)?->quantity);
        $this->assertSame('10000', (string) ShiftProductionEntry::query()->find($entryId)->quantity_produced);
    }

    public function test_quality_can_return_a_batch_it_has_not_checked_and_the_floor_then_corrects_it(): void
    {
        $this->actAsSupervisor();
        $entryId = $this->startBatch();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $this->wrongFigures())
            ->assertOk();

        $checker = $this->actAsChecker();
        $returned = $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/return-to-production", [
            'reason' => 'Box count on the sheet does not match the pallets on the floor — recount.',
        ])->assertOk();

        $this->assertTrue($returned->json('data.correction.awaiting_correction'));
        $this->assertSame(
            'Box count on the sheet does not match the pallets on the floor — recount.',
            $returned->json('data.correction.latest_return_reason'),
        );
        $this->assertSame($checker->id, $returned->json('data.correction.returns.0.returned_by'));
        $this->assertFalse($returned->json('data.correction.returns.0.cleared_quality_check'));

        // The batch is still exactly where it was — a return is an
        // instruction, not an approval step.
        $this->assertSame('pending', $returned->json('data.status'));
        $this->assertSame('completed', $returned->json('data.batch_status'));

        $this->actAsSupervisor();
        $corrected = $this->postJson(
            "/api/v1/production/shift-production-entries/{$entryId}/amend",
            $this->correctFigures(),
        )->assertOk();

        $this->assertLooksLikeOneCorrectCompletion($entryId);

        // AND THE BATCH IS BACK IN QUALITY'S QUEUE. This is the assertion the
        // whole round trip is for: a corrected batch is in the same columns a
        // returned one is (pending, completed, no check), so the ONLY thing
        // separating "still waiting to be fixed" from "fixed, check it" is
        // this flag. The quality queue filters `awaiting_correction` out, so a
        // flag that stayed true would leave the batch never offered for a
        // check, never checked, and therefore permanently short of pmApprove's
        // precondition — outside the approval chain with no way back in.
        $this->assertFalse($corrected->json('data.correction.awaiting_correction'));

        // The trail behind it survives the correction — both halves of it.
        $this->assertCount(1, $corrected->json('data.correction.returns'));
        $this->assertCount(1, $corrected->json('data.correction.amendments'));
        $this->assertSame(
            'Box count on the sheet does not match the pallets on the floor — recount.',
            $corrected->json('data.correction.latest_return_reason'),
        );

        // A SECOND return re-raises it: the flag tracks whether the floor has
        // answered the LATEST return, not whether one ever happened.
        $this->actAsChecker();
        $again = $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/return-to-production", [
            'reason' => 'Still short by a pallet — count the rejects too.',
        ])->assertOk();

        $this->assertTrue($again->json('data.correction.awaiting_correction'));
        $this->assertCount(2, $again->json('data.correction.returns'));
    }

    public function test_a_check_recorded_before_returns_existed_cannot_be_returned_if_it_moved_stock(): void
    {
        // THE DEPLOY-NIGHT ROW: a batch quality-checked before this feature
        // shipped carries no quality_check_effects record. Its FG side has a
        // legacy fallback but its scrap receipt does not — a return would
        // reverse half the check and delete the audit line explaining the
        // other half. The safe answer is a refusal that names the way out.
        $this->actAsSupervisor();
        $entryId = $this->startBatch();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $this->correctFigures())
            ->assertOk();

        $this->actAsChecker();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/quality-check", [
            'reviewed_nos' => 500, 'ok_nos' => 300, 'rejected_nos' => 200, 'note' => 'Neck finish',
        ])->assertOk();

        // Simulate the pre-deploy world: the check happened, the record of
        // what it moved did not.
        $entry = ShiftProductionEntry::query()->findOrFail($entryId);
        $snapshot = $entry->config_snapshot;
        unset($snapshot['quality_check_effects']);
        $entry->forceFill(['config_snapshot' => $snapshot])->save();

        $scrapBefore = (string) $this->balance($this->scrap, $this->fg)?->quantity;
        $fgBefore = (string) $this->balance($this->bottle, $this->fg)?->quantity;

        $refused = $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/return-to-production", [
            'reason' => 'Checked the wrong pallet — this batch was never inspected.',
        ])->assertStatus(422);

        $this->assertStringContainsString('before returns existed', $refused->json('message'));
        $this->assertStringContainsString('reject the batch back to the supervisor', $refused->json('message'));

        // REFUSED MEANS UNTOUCHED: stock, the scrap audit line, and the
        // check itself all stand exactly as they were.
        $this->assertSame($scrapBefore, (string) $this->balance($this->scrap, $this->fg)?->quantity);
        $this->assertSame($fgBefore, (string) $this->balance($this->bottle, $this->fg)?->quantity);
        $entry->refresh();
        $this->assertNotNull($entry->quality_checked_at);
        $this->assertSame(1, $entry->scraps()->where('type', ShiftScrapType::RejectedFinishedGood->value)->count());
    }

    public function test_a_legacy_check_that_rejected_nothing_returns_cleanly(): void
    {
        // The harmless twin: a pre-deploy check with zero rejects moved no
        // stock, so its return is a plain column clear and must not be
        // blocked alongside the dangerous case.
        $this->actAsSupervisor();
        $entryId = $this->startBatch();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $this->correctFigures())
            ->assertOk();

        $this->actAsChecker();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/quality-check", [
            'reviewed_nos' => 500, 'ok_nos' => 500, 'rejected_nos' => 0, 'note' => 'All clean',
        ])->assertOk();

        $entry = ShiftProductionEntry::query()->findOrFail($entryId);
        $snapshot = $entry->config_snapshot;
        unset($snapshot['quality_check_effects']);
        $entry->forceFill(['config_snapshot' => $snapshot])->save();

        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/return-to-production", [
            'reason' => 'Wrong batch ticked in the log — recheck it properly.',
        ])->assertOk();

        $entry->refresh();
        $this->assertNull($entry->quality_checked_at);
        $this->assertNull($entry->quality_rejected_nos);
    }

    public function test_quality_can_return_its_own_check_before_the_pm_signs_and_the_batch_is_checked_fresh(): void
    {
        $this->actAsSupervisor();
        $entryId = $this->startBatch();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $this->correctFigures())
            ->assertOk();

        // A check that rejects 200 bottles: production nets to 9,300, the
        // bottles leave finished goods and 2.58 kg lands on the scrap item.
        $this->actAsChecker();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/quality-check", [
            'reviewed_nos' => 500, 'ok_nos' => 300, 'rejected_nos' => 200, 'note' => 'Neck finish',
        ])->assertOk();

        $this->assertSame('9300.0000', (string) $this->balance($this->bottle, $this->fg)?->quantity);
        $this->assertSame('2.5800', (string) $this->balance($this->scrap, $this->fg)?->quantity);

        // QC realises the check itself was wrong and hands the batch back.
        $returned = $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/return-to-production", [
            'reason' => 'Checked the wrong pallet — this batch was never inspected.',
        ])->assertOk();

        $this->assertTrue($returned->json('data.correction.returns.0.cleared_quality_check'));

        // Every trace of the check is gone: the counts, the netting, the
        // bottles it took out of finished goods, the scrap weight it created
        // and the scrap LINE that would otherwise ride the Tally voucher.
        $entry = ShiftProductionEntry::query()->findOrFail($entryId);
        $this->assertNull($entry->quality_checked_at);
        $this->assertNull($entry->quality_checked_by);
        $this->assertNull($entry->quality_reviewed_nos);
        $this->assertNull($entry->quality_ok_nos);
        $this->assertNull($entry->quality_rejected_nos);
        $this->assertNull($entry->quality_note);
        $this->assertNull($entry->gross_quantity_produced);
        $this->assertNull($entry->qc_rejection_kg);
        $this->assertFalse($returned->json('data.quality.checked'));

        $this->assertSame('9500.0000', (string) $this->balance($this->bottle, $this->fg)?->quantity);
        $this->assertSame('0.0000', (string) $this->balance($this->scrap, $this->fg)?->quantity);
        $this->assertSame(0, ShiftScrap::query()
            ->where('shift_production_entry_id', $entryId)
            ->where('type', ShiftScrapType::RejectedFinishedGood->value)
            ->count());

        // The supervisor's own lumps line is untouched — the return undid
        // quality's work, not the shift's.
        $this->assertSame(1, ShiftScrap::query()->where('shift_production_entry_id', $entryId)->count());

        // Which leaves the batch exactly as a never-checked correct
        // completion, ready to be checked fresh.
        $this->assertLooksLikeOneCorrectCompletion($entryId);

        $rechecked = $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/quality-check", [
            'reviewed_nos' => 500, 'ok_nos' => 500, 'rejected_nos' => 0,
        ])->assertOk();

        $this->assertTrue($rechecked->json('data.quality.checked'));
        $this->assertAmount('9500', $rechecked->json('data.quantity_produced'));
    }

    public function test_a_return_gives_back_the_rejection_weight_the_supervisor_had_entered(): void
    {
        $this->actAsSupervisor();
        $entryId = $this->startBatch();
        // The supervisor weighed their own rejection at completion; the check
        // then overwrote it (rejection_precedence = 'qc').
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            ...$this->correctFigures(),
            'qc_rejection_kg' => '1.5',
        ])->assertOk();

        $this->actAsChecker();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/quality-check", [
            'reviewed_nos' => 500, 'ok_nos' => 300, 'rejected_nos' => 200,
        ])->assertOk();
        $this->assertSame('2.5800', (string) ShiftProductionEntry::query()->find($entryId)->qc_rejection_kg);

        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/return-to-production", [
            'reason' => 'Wrong batch checked.',
        ])->assertOk();

        // Restored, not blanked — the weighed figure was never quality's to
        // destroy.
        $this->assertSame('1.5000', (string) ShiftProductionEntry::query()->find($entryId)->qc_rejection_kg);
    }

    public function test_a_return_without_a_reason_is_refused(): void
    {
        $this->actAsSupervisor();
        $entryId = $this->startBatch();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $this->correctFigures())
            ->assertOk();

        $this->actAsChecker();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/return-to-production", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/return-to-production", ['reason' => 'no'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_neither_door_is_open_once_the_plant_manager_has_approved(): void
    {
        $this->actAsSupervisor();
        $entryId = $this->startBatch();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $this->wrongFigures())
            ->assertOk();

        $this->actAsChecker();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/quality-check", [
            'reviewed_nos' => 500, 'ok_nos' => 500, 'rejected_nos' => 0,
        ])->assertOk();

        $this->actAs(['production.view', 'production.manage'], 'Plant Manager');
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/pm-approve")->assertOk();

        $this->actAsSupervisor();
        $amend = $this->postJson(
            "/api/v1/production/shift-production-entries/{$entryId}/amend",
            $this->correctFigures(),
        )->assertStatus(422);
        $this->assertStringContainsString('plant manager has already approved', $amend->json('message'));

        $this->actAsChecker();
        $return = $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/return-to-production", [
            'reason' => 'Rechecking this one.',
        ])->assertStatus(422);
        $this->assertStringContainsString('plant manager has already approved', $return->json('message'));

        // The PM's signature still describes the figures they signed.
        $entry = ShiftProductionEntry::query()->findOrFail($entryId);
        $this->assertSame('10000', (string) $entry->quantity_produced);
        $this->assertNotNull($entry->quality_checked_at);
        $this->assertSame(ShiftProductionEntryStatus::PmApproved, $entry->status);
    }

    public function test_neither_door_is_open_once_the_batch_is_in_tally(): void
    {
        $this->actAsSupervisor();
        $entryId = $this->startBatch();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $this->wrongFigures())
            ->assertOk();

        ShiftProductionEntry::query()->whereKey($entryId)
            ->update(['status' => ShiftProductionEntryStatus::Synced->value]);

        $this->actAsSupervisor();
        $amend = $this->postJson(
            "/api/v1/production/shift-production-entries/{$entryId}/amend",
            $this->correctFigures(),
        )->assertStatus(422);
        $this->assertStringContainsString('already in Tally', $amend->json('message'));

        $this->actAsChecker();
        $return = $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/return-to-production", [
            'reason' => 'Needs a recount.',
        ])->assertStatus(422);
        $this->assertStringContainsString('already in Tally', $return->json('message'));
    }

    public function test_a_batch_already_on_a_voucher_is_refused_even_while_it_reads_as_pending(): void
    {
        $this->actAsSupervisor();
        $entryId = $this->startBatch();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $this->wrongFigures())
            ->assertOk();

        // The belt-and-braces guard: a voucher cannot exist at 'pending'
        // today, and the refusal must not depend on that staying true.
        TallySyncEntry::create([
            'syncable_type' => (new ShiftProductionEntry)->getMorphClass(),
            'syncable_id' => $entryId,
            'tally_voucher_type' => 'Stock Journal',
            'payload' => ['voucher_number' => 'SJ-20260731-S1'],
            'status' => TallySyncStatus::Pending,
            'attempts' => 0,
        ]);

        $amend = $this->postJson(
            "/api/v1/production/shift-production-entries/{$entryId}/amend",
            $this->correctFigures(),
        )->assertStatus(422);
        $this->assertStringContainsString('already on a Tally voucher', $amend->json('message'));

        $this->actAsChecker();
        $return = $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/return-to-production", [
            'reason' => 'Needs a recount.',
        ])->assertStatus(422);
        $this->assertStringContainsString('already on a Tally voucher', $return->json('message'));
    }

    public function test_a_segment_that_handed_over_can_no_longer_be_corrected(): void
    {
        $this->actAsSupervisor();
        $entryId = $this->startBatch();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $this->wrongFigures())
            ->assertOk();

        // The next shift opened from this segment's closing weights.
        ShiftProductionEntry::create([
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => '2026-07-31',
            'batch_number' => '20260731-M01-001',
            'batch_status' => BatchStatus::InProgress,
            'parent_entry_id' => $entryId,
            'quantity_scrap' => '0',
        ]);

        $amend = $this->postJson(
            "/api/v1/production/shift-production-entries/{$entryId}/amend",
            $this->correctFigures(),
        )->assertStatus(422);
        $this->assertStringContainsString('handed over to the next shift', $amend->json('message'));

        $this->actAsChecker();
        $return = $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/return-to-production", [
            'reason' => 'Needs a recount.',
        ])->assertStatus(422);
        $this->assertStringContainsString('handed over to the next shift', $return->json('message'));
    }

    public function test_a_batch_still_running_has_nothing_to_amend(): void
    {
        $this->actAsSupervisor();
        $entryId = $this->startBatch();

        $refused = $this->postJson(
            "/api/v1/production/shift-production-entries/{$entryId}/amend",
            $this->correctFigures(),
        )->assertStatus(422);

        $this->assertStringContainsString('still running', $refused->json('message'));
    }

    public function test_a_production_user_cannot_return_a_batch_to_themselves(): void
    {
        $this->actAsSupervisor();
        $entryId = $this->startBatch();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $this->correctFigures())
            ->assertOk();

        // Production permission is not quality permission — the return is the
        // quality desk's action, and a supervisor holding both would make the
        // rule it enforces meaningless.
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/return-to-production", [
            'reason' => 'I would like another go at this.',
        ])->assertStatus(403);
    }

    public function test_a_quality_user_cannot_amend_the_production_figures(): void
    {
        $this->actAsSupervisor();
        $entryId = $this->startBatch();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $this->wrongFigures())
            ->assertOk();

        $this->actAsChecker();
        $this->postJson(
            "/api/v1/production/shift-production-entries/{$entryId}/amend",
            $this->correctFigures(),
        )->assertStatus(403);

        // Refused at the door, so nothing was touched.
        $this->assertSame('10000', (string) ShiftProductionEntry::query()->find($entryId)->quantity_produced);
    }

    public function test_quality_cannot_return_a_batch_when_the_stage_is_switched_off(): void
    {
        config(['production.approvals.quality_stage_enabled' => false]);

        $this->actAsSupervisor();
        $entryId = $this->startBatch();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $this->correctFigures())
            ->assertOk();

        $this->actAsChecker();
        $refused = $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/return-to-production", [
            'reason' => 'Needs a recount.',
        ])->assertStatus(422);

        $this->assertStringContainsString('quality stage is switched off', $refused->json('message'));

        // With quality stood down the floor's own door is simply never shut.
        $this->actAsSupervisor();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/amend", $this->correctFigures())
            ->assertOk();
    }
}
