<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Models\Enums\ShiftScrapType;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * THE QUALITY GATE, as the owner described it (30-Jul): "we need one new
 * approval as quality check. All the machines will go to quality queue, and
 * quality will do the check, add entry as how many reviewed, how many okay
 * and how many rejected. This quality-rejected needs to add in the production
 * as rejected by quality, so the total production will reduce if rejection,
 * otherwise same, then go to next level." Asked whether rejected bottles are
 * ever reworked: "no — go to the rejected scrap only."
 *
 * Every test here is one sentence of that brief, plus the two things a gate
 * like this fails at rather than the thing it is for: the arithmetic must not
 * quietly disagree with itself (a netted production figure and an unchanged
 * material reconciliation are the same physical shift), and the stage must be
 * switchable off to exactly the chain that existed before it.
 *
 * THE FIXTURE'S ARITHMETIC, once, since every test leans on it:
 *   bottle = 12.9 g   produced 10,000 = 129.0000 kg
 *   rejected 200      = 2.5800 kg     net 9,800 = 126.4200 kg
 *   issued 130 kg     unaccounted 130 − 129 = 1.0000 kg
 */
class BatchQualityStageTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Item $resin;

    private Item $scrap;

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

        // The factory's scrap master, mirrored from the "Pet Scrap" line their
        // Tally books already carry. Named in config below — the ERP resolves
        // no scrap item on its own, deliberately (see config/production.php).
        $this->scrap = Item::create([
            'sku' => 'PET-SCRAP', 'name' => 'Pet Scrap', 'uom' => 'Kgs.',
            'is_active' => true, 'tally_stock_item_guid' => 'itm-scrap',
        ]);
        config(['production.scrap.rejected_item_sku' => 'PET-SCRAP']);

        app(StockMovementService::class)->recordReceipt(
            itemId: $this->resin->id, warehouseId: $this->rm->id,
            quantity: '1000', unitCost: '0', reference: 'opening', createdBy: null,
        );
    }

    /**
     * A fresh user every call — which is also what keeps the four-eyes rules
     * satisfied by construction, exactly as the live desks are different
     * people.
     */
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
    private function completedBatch(array $completion = []): int
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
            ...$completion,
        ])->assertOk();

        return $entryId;
    }

    private function check(int $entryId, array $counts): TestResponse
    {
        return $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/quality-check", $counts);
    }

    private function entryJson(int $entryId, string $status = 'pending'): array
    {
        return collect($this->getJson("/api/v1/production/shift-production-entries?status={$status}")->assertOk()->json('data'))
            ->firstWhere('id', $entryId);
    }

    // =================================================================
    // 1. THE WHOLE SENTENCE, end to end
    // =================================================================

    public function test_a_rejection_reduces_production_all_the_way_to_the_voucher(): void
    {
        $this->actAs();
        $entryId = $this->completedBatch();

        // Before quality: the supervisor's gross figure, and a material
        // reconciliation of 130 − 129 = 1 kg unaccounted.
        $before = $this->entryJson($entryId);
        $this->assertSame('129.0000', $before['metrics']['good_production_kg']);
        $this->assertSame('1.0000', $before['metrics']['reconciliation_unaccounted_kg']);
        $this->assertFalse($before['quality']['checked']);

        // ---- The quality desk checks the batch ------------------------
        $checker = $this->actAs();
        $checked = $this->check($entryId, [
            'reviewed_nos' => 10000,
            'ok_nos' => 9800,
            'rejected_nos' => 200,
            'note' => 'Short fill on two trays.',
        ])->assertOk();

        // "The total production will reduce if rejection."
        $this->assertSame(9800.0, (float) $checked->json('data.quantity_produced'));
        $this->assertSame(10000.0, (float) $checked->json('data.gross_quantity_produced'));
        $this->assertSame(126.42, (float) $checked->json('data.quantity_produced_kg'));

        // 200 x 12.9 g = 2.58 kg, written to the EXISTING qc field so the
        // rejection precedence that was already in the codebase consumes it.
        $this->assertSame('2.5800', $checked->json('data.qc_rejection_kg'));
        $this->assertSame('2.5800', $checked->json('data.metrics.confirmed_rejection_kg'));
        $this->assertSame('2.5800', $checked->json('data.metrics.rejection_kg_qc'));

        // THE RECONCILIATION HAS NOT MOVED — on this batch, which carried no
        // earlier rejection figure. issued − net − rejected is the same shift
        // as issued − gross: the rejected bottles were always part of what
        // came out of the machine, and this stage renames them rather than
        // making a kilogram disappear. If this ever drifts, the accountant's
        // blocking figure is being computed off two different stories about
        // the same batch.
        //
        // A batch that DID carry an earlier rejection figure is a different
        // and noisier case, because precedence replaces rather than adds —
        // pinned separately in
        // test_a_supervisor_rejection_figure_is_superseded_not_added_to().
        $this->assertSame('1.0000', $checked->json('data.metrics.reconciliation_unaccounted_kg'));

        // The counts, the checker and the basis are all on the record.
        $quality = $checked->json('data.quality');
        $this->assertTrue($quality['checked']);
        $this->assertSame(10000, $quality['reviewed_nos']);
        $this->assertSame(9800, $quality['ok_nos']);
        $this->assertSame(200, $quality['rejected_nos']);
        $this->assertSame('Short fill on two trays.', $quality['note']);
        $this->assertSame($checker->id, $quality['checked_by']['id']);
        $this->assertNotNull($quality['checked_at']);
        $this->assertSame('2.5800', $quality['rejection_kg']);
        $this->assertSame('12.9000', $quality['rejection_kg_basis']['unit_weight_grams']);
        $this->assertNull($quality['scrap_note'], 'The scrap receipt resolved, so there is nothing to explain.');

        // ---- Then the chain, unchanged ---------------------------------
        $this->actAs('Plant Manager');
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/pm-approve")->assertOk();

        $this->actAs('Accounts');
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/accountant-approve")->assertOk();

        // ---- THE VOUCHER CARRIES NET -----------------------------------
        $payload = TallySyncEntry::query()->sole()->payload;
        $this->assertSame(9800.0, (float) $payload['produced'][0]['quantity'],
            'The books must receive what the factory can sell, not what the machine ejected.');

        // The rejection reaches the voucher as scrap data and narration —
        // the mechanism that already existed. It is NOT promoted to a second
        // produced line, because nothing in this ERP maps a scrap type to a
        // Tally scrap item yet and inventing that would change the payload's
        // shape.
        $scrapLine = collect($payload['scraps'])->firstWhere('type', 'rejected_finished_good');
        $this->assertNotNull($scrapLine);
        $this->assertSame(200.0, (float) $scrapLine['quantity_nos']);
        $this->assertSame(2.58, (float) $scrapLine['quantity_kg']);
        $this->assertStringContainsString('rejected_finished_good', $payload['narration']);
    }

    // =================================================================
    // 2. THE GATE ITSELF
    // =================================================================

    public function test_the_plant_manager_cannot_approve_a_batch_quality_has_not_checked(): void
    {
        $this->actAs();
        $entryId = $this->completedBatch();

        $this->actAs('Plant Manager');
        $refused = $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/pm-approve")
            ->assertStatus(422);
        $this->assertStringContainsString('quality queue', $refused->json('message'));

        // The batch is untouched and still waiting.
        $this->assertSame('pending', ShiftProductionEntry::findOrFail($entryId)->status->value);

        $this->actAs();
        $this->check($entryId, ['reviewed_nos' => 10000, 'ok_nos' => 10000, 'rejected_nos' => 0])->assertOk();

        $this->actAs('Plant Manager');
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/pm-approve")->assertOk();
    }

    // =================================================================
    // 3. "OTHERWISE SAME"
    // =================================================================

    public function test_a_clean_batch_records_its_counts_and_moves_nothing(): void
    {
        $this->actAs();
        // The supervisor's own weighed rejection figure, entered at
        // completion. A zero-rejection quality check must not overwrite it
        // with nothing.
        $entryId = $this->completedBatch(['qc_rejection_kg' => '1.5']);

        $before = $this->entryJson($entryId);
        $movementsBefore = StockMovement::query()->count();

        $this->actAs();
        $checked = $this->check($entryId, [
            'reviewed_nos' => 10000,
            'ok_nos' => 10000,
            'rejected_nos' => 0,
        ])->assertOk();

        // Counts recorded, gate open — and not one figure moved.
        $this->assertTrue($checked->json('data.quality.checked'));
        $this->assertSame(10000, $checked->json('data.quality.reviewed_nos'));
        $this->assertSame(0, $checked->json('data.quality.rejected_nos'));
        $this->assertNull($checked->json('data.quality.rejection_kg'));
        $this->assertNull($checked->json('data.quality.rejection_kg_basis'));

        $this->assertSame((float) $before['quantity_produced'], (float) $checked->json('data.quantity_produced'));
        $this->assertNull($checked->json('data.gross_quantity_produced'), 'Nothing was netted, so there is no gross to keep apart.');
        $this->assertSame('1.5000', $checked->json('data.qc_rejection_kg'), "A clean check must not erase the supervisor's weighed figure.");
        $this->assertSame($before['metrics']['good_production_kg'], $checked->json('data.metrics.good_production_kg'));
        $this->assertSame($before['metrics']['confirmed_rejection_kg'], $checked->json('data.metrics.confirmed_rejection_kg'));
        $this->assertSame($before['metrics']['reconciliation_unaccounted_kg'], $checked->json('data.metrics.reconciliation_unaccounted_kg'));

        // No stock moved and no scrap line was invented.
        $this->assertSame($movementsBefore, StockMovement::query()->count());
        $this->assertSame(0, ShiftProductionEntry::findOrFail($entryId)->scraps()
            ->where('type', ShiftScrapType::RejectedFinishedGood->value)->count());
    }

    /**
     * THE ONE CASE WHERE THE RECONCILIATION DOES MOVE, pinned because the
     * accountant is BLOCKED on this figure and a silent swing in it would be
     * discovered as an unexplained refusal to approve.
     *
     * production.rejection_precedence is 'qc' — "QC's figure outranks
     * production's" — and precedence picks ONE figure, it does not add them.
     * So when the supervisor already recorded a rejection at completion, the
     * quality desk's count REPLACES it, and the material reconciliation moves
     * by the difference. That is the pre-existing precedence rule doing
     * exactly what it says; the quality gate simply makes the collision
     * routine instead of rare.
     *
     * Adding the two instead would assert they count DIFFERENT bottles, which
     * nobody at the factory has said and which would double-count the moment
     * the supervisor's figure was their own estimate of the same rejection.
     * So the behaviour stands and the consequence is written down here.
     */
    public function test_a_supervisor_rejection_figure_is_superseded_not_added_to(): void
    {
        $this->actAs();
        $entryId = $this->completedBatch(['qc_rejection_kg' => '1.5']);

        // 130 issued − 129 good − 1.5 rejected = −0.5 unaccounted.
        $before = $this->entryJson($entryId);
        $this->assertSame('1.5000', $before['metrics']['confirmed_rejection_kg']);
        $this->assertSame('-0.5000', $before['metrics']['reconciliation_unaccounted_kg']);

        $this->actAs();
        $checked = $this->check($entryId, [
            'reviewed_nos' => 10000, 'ok_nos' => 9800, 'rejected_nos' => 200,
        ])->assertOk();

        // The supervisor's 1.5 kg is GONE, replaced by the desk's 2.58 kg —
        // not 4.08 kg.
        $this->assertSame('2.5800', $checked->json('data.qc_rejection_kg'));
        $this->assertSame('2.5800', $checked->json('data.metrics.confirmed_rejection_kg'));

        // And the reconciliation swings by exactly the superseded figure:
        // −0.5000 → +1.0000 is 1.5000, the number that was replaced.
        $this->assertSame('1.0000', $checked->json('data.metrics.reconciliation_unaccounted_kg'));
    }

    // =================================================================
    // 4. THE THREE NUMBERS MUST RECONCILE
    // =================================================================

    public function test_reviewed_must_equal_ok_plus_rejected(): void
    {
        $this->actAs();
        $entryId = $this->completedBatch();

        $this->actAs();
        $this->check($entryId, ['reviewed_nos' => 500, 'ok_nos' => 480, 'rejected_nos' => 15])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reviewed_nos');

        // Nothing was recorded by the refused attempt.
        $this->assertNull(ShiftProductionEntry::findOrFail($entryId)->quality_checked_at);
    }

    public function test_a_batch_cannot_reject_more_than_it_produced(): void
    {
        $this->actAs();
        $entryId = $this->completedBatch();

        $this->actAs();
        $this->check($entryId, ['reviewed_nos' => 10001, 'ok_nos' => 0, 'rejected_nos' => 10001])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rejected_nos');

        $this->assertNull(ShiftProductionEntry::findOrFail($entryId)->quality_checked_at);
    }

    // =================================================================
    // 5. ONE CHECK ONLY
    // =================================================================

    public function test_a_second_quality_check_is_refused(): void
    {
        $this->actAs();
        $entryId = $this->completedBatch();

        $this->actAs();
        $this->check($entryId, ['reviewed_nos' => 10000, 'ok_nos' => 9800, 'rejected_nos' => 200])->assertOk();

        $this->actAs();
        $refused = $this->check($entryId, ['reviewed_nos' => 10000, 'ok_nos' => 9900, 'rejected_nos' => 100])
            ->assertStatus(422);
        $this->assertStringContainsString('already had its quality check', $refused->json('message'));

        // And the refusal is real: the first check's figures stand, and the
        // second one did not move stock a second time.
        $entry = ShiftProductionEntry::findOrFail($entryId);
        $this->assertSame(200, $entry->quality_rejected_nos);
        $this->assertSame(9800.0, (float) $entry->quantity_produced);
        $this->assertSame(1, StockMovement::query()->where('reference', "QC #{$entryId}")
            ->where('item_id', $this->bottle->id)->count());
    }

    // =================================================================
    // 6. THE STOCK: OUT OF FINISHED GOODS, INTO SCRAP
    // =================================================================

    public function test_the_rejected_bottles_leave_finished_goods_and_their_weight_becomes_scrap(): void
    {
        $this->actAs();
        $entryId = $this->completedBatch();

        // Completion received the gross count into FG.
        $this->assertSame('10000.0000', (string) StockBalance::query()
            ->where('item_id', $this->bottle->id)->where('warehouse_id', $this->fg->id)->value('quantity'));

        $this->actAs();
        $this->check($entryId, ['reviewed_nos' => 10000, 'ok_nos' => 9800, 'rejected_nos' => 200])->assertOk();

        $fgQuantity = (string) StockBalance::query()
            ->where('item_id', $this->bottle->id)->where('warehouse_id', $this->fg->id)->value('quantity');
        $scrapQuantity = (string) StockBalance::query()
            ->where('item_id', $this->scrap->id)->where('warehouse_id', $this->fg->id)->value('quantity');

        $this->assertSame('9800.0000', $fgQuantity, 'Rejected bottles must stop counting as sellable product.');
        $this->assertSame('2.5800', $scrapQuantity);

        // AND THE TWO RECONCILE. The issue is in pieces and the receipt is in
        // kilograms, so the only thing that makes them the same movement is
        // the run's frozen unit weight — assert that relationship directly
        // rather than the two constants, or a wrong weight would sail past.
        $this->assertSame(
            bcdiv(bcmul('200', '12.9000', 4), '1000', 4),
            $scrapQuantity,
            'Mass out of finished goods must equal mass into scrap.',
        );

        // Both movements are labelled with the gate that made them.
        $issue = StockMovement::query()->where('reference', "QC #{$entryId}")->where('item_id', $this->bottle->id)->sole();
        $receipt = StockMovement::query()->where('reference', "QC #{$entryId}")->where('item_id', $this->scrap->id)->sole();
        $this->assertSame('issue', $issue->type->value);
        $this->assertSame('receipt', $receipt->type->value);
    }

    // =================================================================
    // 7. NO SCRAP ITEM: RECORD IT, SKIP THE RECEIPT, SAY SO
    // =================================================================

    public function test_an_unresolvable_scrap_item_is_recorded_and_visible_rather_than_guessed(): void
    {
        // The live state of this ERP today: no scrap item is configured.
        config(['production.scrap.rejected_item_sku' => null]);

        $this->actAs();
        $entryId = $this->completedBatch();

        $this->actAs();
        $checked = $this->check($entryId, ['reviewed_nos' => 10000, 'ok_nos' => 9800, 'rejected_nos' => 200])
            ->assertOk();

        // THE REJECTION IS STILL FULLY RECORDED — the figures the books need
        // do not depend on the ERP owning a scrap master.
        $this->assertSame(9800.0, (float) $checked->json('data.quantity_produced'));
        $this->assertSame('2.5800', $checked->json('data.qc_rejection_kg'));

        // The bottles still leave finished goods (under-recording a movement
        // is recoverable; leaving rejected stock on the shelf as sellable is
        // not) …
        $this->assertSame('9800.0000', (string) StockBalance::query()
            ->where('item_id', $this->bottle->id)->where('warehouse_id', $this->fg->id)->value('quantity'));

        // … but nothing was received against a guessed item.
        $this->assertSame(0, StockMovement::query()
            ->where('reference', "QC #{$entryId}")->where('type', 'receipt')->count());

        // And the skip is on the entry where an approver reads it, not only
        // in a log file.
        $note = $checked->json('data.quality.scrap_note');
        $this->assertNotNull($note);
        $this->assertStringContainsString('production.scrap.rejected_item_sku', $note);
        // Still visible to the next desk in the queue listing.
        $this->assertSame($note, $this->entryJson($entryId)['quality']['scrap_note']);
    }

    // =================================================================
    // 8. FOUR EYES
    // =================================================================

    public function test_the_checker_may_not_be_the_supervisor_who_completed_the_batch(): void
    {
        $supervisor = $this->actAs();
        $entryId = $this->completedBatch();

        // Same person, still authenticated, now trying to certify their own
        // count.
        $refused = $this->check($entryId, ['reviewed_nos' => 10000, 'ok_nos' => 9800, 'rejected_nos' => 200])
            ->assertStatus(422);
        $this->assertStringContainsString('same person', $refused->json('message'));
        $this->assertNull(ShiftProductionEntry::findOrFail($entryId)->quality_checked_at);

        // Anyone else may.
        $this->actAs();
        $this->check($entryId, ['reviewed_nos' => 10000, 'ok_nos' => 9800, 'rejected_nos' => 200])->assertOk();

        $this->assertSame(
            $supervisor->id,
            ShiftProductionEntry::findOrFail($entryId)->completed_by,
            'The completing supervisor is recorded — that is what the rule compares against.',
        );
    }

    public function test_a_one_person_office_can_relax_the_quality_four_eyes_rule_in_the_open(): void
    {
        config(['production.approvals.allow_same_user' => true]);

        $this->actAs();
        $entryId = $this->completedBatch();

        $this->check($entryId, ['reviewed_nos' => 10000, 'ok_nos' => 9800, 'rejected_nos' => 200])->assertOk();
    }

    // =================================================================
    // 9. THE SWITCH
    // =================================================================

    public function test_with_the_stage_switched_off_the_chain_is_exactly_what_it_was(): void
    {
        config(['production.approvals.quality_stage_enabled' => false]);

        $this->actAs();
        $entryId = $this->completedBatch();

        // Straight from completion to the plant manager, no quality check.
        $this->actAs('Plant Manager');
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/pm-approve")->assertOk();

        $this->actAs('Accounts');
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/accountant-approve")->assertOk();

        $entry = ShiftProductionEntry::findOrFail($entryId);
        $this->assertSame('approved', $entry->status->value);

        // Not one figure differs from a pre-quality-stage run: the gross
        // count stands, no gross/net split exists, no quality columns were
        // written, and the voucher carries what it always carried.
        $this->assertSame(10000.0, (float) $entry->quantity_produced);
        $this->assertNull($entry->gross_quantity_produced);
        $this->assertNull($entry->quality_checked_at);
        $this->assertNull($entry->quality_rejected_nos);
        $this->assertNull($entry->qc_rejection_kg);
        $this->assertSame(0, $entry->scraps()->count());

        $payload = TallySyncEntry::query()->sole()->payload;
        $this->assertSame(10000.0, (float) $payload['produced'][0]['quantity']);
        $this->assertSame([], $payload['scraps']);

        // No stock moved at the gate that was switched off.
        $this->assertSame(0, StockMovement::query()->where('reference', "QC #{$entryId}")->count());

        // AND THE ENDPOINT ITSELF REFUSES. A hidden button is not a guard:
        // this API is a product surface other clients may call, so with the
        // stage down a POST must be turned away rather than quietly netting
        // the production figure and issuing bottles out of finished goods.
        $second = $this->completedBatch();
        $movementsBefore = StockMovement::query()->count();

        $this->actAs();
        $refused = $this->check($second, ['reviewed_nos' => 10000, 'ok_nos' => 9800, 'rejected_nos' => 200])
            ->assertStatus(422);
        $this->assertStringContainsString('quality stage is switched off', $refused->json('message'));

        $untouched = ShiftProductionEntry::findOrFail($second);
        $this->assertSame(10000.0, (float) $untouched->quantity_produced);
        $this->assertNull($untouched->gross_quantity_produced);
        $this->assertNull($untouched->quality_checked_at);
        $this->assertNull($untouched->qc_rejection_kg);
        $this->assertSame(0, $untouched->scraps()->count());
        $this->assertSame($movementsBefore, StockMovement::query()->count());
    }

    public function test_the_check_needs_the_quality_permission_not_the_production_one(): void
    {
        $this->actAs();
        $entryId = $this->completedBatch();

        $user = User::factory()->create(['is_active' => true]);
        foreach (['production.view', 'production.manage', 'quality.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        // Full production rights, and only READ on quality.
        $user->givePermissionTo(['production.view', 'production.manage', 'quality.view']);
        Sanctum::actingAs($user);

        $this->check($entryId, ['reviewed_nos' => 10000, 'ok_nos' => 10000, 'rejected_nos' => 0])
            ->assertStatus(403);

        // And the converse, which is the whole reason this route sits outside
        // the production group: a QC checker who holds NOTHING in production
        // can still do their job.
        $qc = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('quality.manage', 'web');
        $qc->givePermissionTo(['quality.manage']);
        Sanctum::actingAs($qc);

        $this->check($entryId, ['reviewed_nos' => 10000, 'ok_nos' => 10000, 'rejected_nos' => 0])
            ->assertOk();
    }
}
