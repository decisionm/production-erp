<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ShiftProductionEntryService;
use App\Modules\TallySync\Models\TallySyncEntry;
use Database\Seeders\CanonicalMachineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE INCIDENT (owner's screenshot, 30-Jul). A real shift's completion was
 * refused with:
 *
 *   "Could not complete batch — Insufficient stock for item #592 at
 *    warehouse #10: available 0.0000, requested 118.998."
 *
 * Two defects in one sentence. It spoke in ids and named no fix; and it
 * BLOCKED THE FLOOR over a bin that held zero RECORDED stock only because
 * nobody had entered an opening balance for it yet. The resin was genuinely
 * consumed. Refusing the completion did not un-consume it — it only stopped
 * the truth being written down.
 *
 * What these tests pin, in the order the rule runs:
 *   1. the completion goes through, the balance goes negative by exactly the
 *      shortfall, and the shortfall is recorded as a fact on the entry;
 *   2. that fact reaches the screen with NAMES on it, and never blocks
 *      approval — the accountant fixes the stock, not the supervisor;
 *   3. a partial shortage records only the part that was actually short;
 *   4. the flag off restores the refusal, now readable, and rolls the whole
 *      completion back;
 *   5. every OTHER issue path (work orders here) keeps its hard block;
 *   6. the Tally voucher still carries the consumption the supervisor
 *      submitted, unchanged — a negative bin is our bookkeeping problem,
 *      not a reason to restate what the shift ate.
 */
class NegativeStockOnCompletionTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Item $resin;

    private Warehouse $fg;

    private Warehouse $dayBin;

    private Shift $shift;

    private WorkCenter $machine;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CanonicalMachineSeeder::class);
        $this->machine = WorkCenter::where('code', 'MC-01')->firstOrFail();
        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);

        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg']);
        // The day bin from the incident: the machine-side bin the resin is
        // consumed out of, holding nothing the ledger knows about.
        $this->dayBin = Warehouse::create(['code' => 'DAY-BIN', 'name' => 'Factory Day Bin', 'is_active' => true, 'tally_guid' => 'gd-bin']);

        $this->resin = Item::create(['sku' => 'PET-IV08', 'name' => 'Billion Pet Resin IV-0.8', 'uom' => 'Kgs.', 'is_active' => true, 'tally_stock_item_guid' => 'g1']);

        // Fully specified: the readiness gate refuses a start long before
        // completion, so an under-specified product would fail these tests
        // for a reason that has nothing to do with stock.
        $this->bottle = Item::create([
            'sku' => 'BTL-100-RND', 'name' => '100ML ROUND', 'uom' => 'Nos.', 'is_active' => true,
            'nominal_weight_grams' => '12.9000', 'standard_cycle_time' => '12.30', 'standard_cavities' => 5,
            'nos_per_tray' => 162, 'trays_per_box' => 5, 'nos_per_box' => 810,
            'colour' => 'Amber', 'tally_stock_item_guid' => 'g3',
        ]);

        $bom = Bom::create(['item_id' => $this->bottle->id, 'name' => 'recipe', 'version' => '1', 'is_active' => true]);
        $bom->lines()->create(['component_item_id' => $this->resin->id, 'quantity_per' => '0.0129']);

        $this->user = $this->actAsSupervisor();
    }

    private function actAsSupervisor(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['production.view', 'production.manage', 'inventory.view', 'inventory.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo(['production.view', 'production.manage', 'inventory.view', 'inventory.manage']);
        Sanctum::actingAs($user);

        return $user;
    }

    private function startBatch(): int
    {
        return $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => '2026-07-30',
        ])->assertOk()->json('data.id');
    }

    /** The incident's own figure: 118.998 kg of resin the bin does not know about. */
    private function complete(int $entryId, string $issuedKg = '118.998'): TestResponse
    {
        return $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => '8100',
            'running_hours' => '8',
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'warehouse_id' => $this->dayBin->id, 'quantity_issued_kg' => $issuedKg],
            ],
        ]);
    }

    private function binBalance(): ?string
    {
        return StockBalance::query()
            ->where('item_id', $this->resin->id)
            ->where('warehouse_id', $this->dayBin->id)
            ->value('quantity');
    }

    // ---------------------------------------------------------------
    // 1. The shift is recorded. The balance tells the truth about it.
    // ---------------------------------------------------------------

    public function test_a_completion_against_zero_recorded_stock_succeeds_and_drives_the_balance_negative(): void
    {
        $entryId = $this->startBatch();

        // Not one balance row exists for this bin — the exact state that
        // refused the owner's shift.
        $this->assertNull($this->binBalance());

        $this->complete($entryId)->assertOk();

        $entry = ShiftProductionEntry::findOrFail($entryId);
        $this->assertSame(BatchStatus::Completed, $entry->batch_status);
        $this->assertSame(ShiftProductionEntryStatus::Pending, $entry->status);

        // Negative by EXACTLY the shortfall — the issue happened in full,
        // and the ledger now owes 118.998 kg it never recorded arriving.
        $this->assertSame('-118.9980', $this->binBalance());

        // And the consumption line is on the entry, unreduced. Compared
        // numerically because the column carries no decimal cast and the
        // driver decides the trailing zeros.
        $this->assertSame(0, bccomp('118.998', (string) $entry->materialConsumptions()->firstOrFail()->quantity_issued_kg, 4));
    }

    public function test_the_shortfall_is_recorded_on_the_entry_and_exposed_on_the_resource(): void
    {
        $entryId = $this->startBatch();

        $response = $this->complete($entryId)->assertOk();

        // Recorded as a fact on the entry itself, names frozen alongside ids.
        $recorded = ShiftProductionEntry::findOrFail($entryId)->config_snapshot['stock_shortfalls'];
        $this->assertSame([[
            'item_id' => $this->resin->id,
            'item_name' => 'Billion Pet Resin IV-0.8',
            'warehouse_id' => $this->dayBin->id,
            'warehouse_name' => 'Factory Day Bin',
            'short_kg' => '118.9980',
        ]], $recorded);

        // And it survives to the screen through the resource's metrics block.
        $shortfalls = $response->json('data.metrics.stock_shortfalls');
        $this->assertCount(1, $shortfalls);

        // THE CONTRACT, pinned by name. These five keys are what
        // frontend/src/features/production/types.ts StockShortfall declares and
        // what readStockShortfalls() reads — item_name, warehouse_name and
        // short_kg are the three it prints. An earlier draft of that file also
        // read requested_kg/available_kg/resulting_balance_kg, none of which
        // this side has ever sent, and the drawer duly printed "— kg" for the
        // one figure the screen exists to show. Rename anything here and the
        // approval drawer goes quiet; this assertion is what makes that fail
        // loudly instead.
        $this->assertSame(
            ['item_id', 'item_name', 'warehouse_id', 'warehouse_name', 'short_kg'],
            array_keys($shortfalls[0]),
        );
        $this->assertSame('Billion Pet Resin IV-0.8', $shortfalls[0]['item_name']);
        $this->assertSame('Factory Day Bin', $shortfalls[0]['warehouse_name']);
        $this->assertSame('118.9980', $shortfalls[0]['short_kg']);

        // A batch with nothing short says so with an empty list, not a null
        // a screen would have to special-case. (The bin row now exists and
        // is negative — the accountant's correction, in miniature.)
        StockBalance::query()
            ->where('item_id', $this->resin->id)
            ->where('warehouse_id', $this->dayBin->id)
            ->update(['quantity' => '500.0000', 'average_cost' => '85.0000']);
        $clean = $this->complete($this->startBatch())->assertOk();
        $this->assertSame([], $clean->json('data.metrics.stock_shortfalls'));
    }

    /**
     * A shortfall is a FLAG, not a gate. The stock record is what was wrong;
     * the accountant fixes it, and the shift posts either way.
     */
    public function test_approval_runs_all_the_way_through_with_a_shortfall_recorded(): void
    {
        $entryId = $this->startBatch();
        $this->complete($entryId)->assertOk();

        $service = app(ShiftProductionEntryService::class);
        $entry = ShiftProductionEntry::findOrFail($entryId);

        $this->assertFalse($service->productionMetrics($entry)['blocks_approval']);

        // Two people, because four-eyes: the accountant gate refuses the PM's
        // own account. This test is about the shortfall surviving the chain,
        // not about who signs — see ApprovalChainTest for the rule itself.
        $accountant = User::factory()->create();
        $service->pmApprove($entry, $this->user->id);
        $approved = $service->accountantApprove(ShiftProductionEntry::findOrFail($entryId), $accountant->id);

        $this->assertSame(ShiftProductionEntryStatus::Approved, $approved->status);

        // Still recorded after the whole chain — approval does not tidy the
        // shortfall away.
        $this->assertCount(1, $approved->config_snapshot['stock_shortfalls']);
    }

    /**
     * The other side of the same coin. An empty bin has no recorded average
     * cost, so the issue stamps 0.0000 — the ABSENCE of a price, not a price
     * of zero. Reporting it as a fully-priced batch would tell the accountant
     * the resin was free on the very screen asking them to go fix its stock.
     */
    public function test_material_the_ledger_never_priced_is_not_reported_as_costing_nothing(): void
    {
        $service = app(ShiftProductionEntryService::class);

        $shortEntryId = $this->startBatch();
        $this->complete($shortEntryId)->assertOk();

        $cost = $service->materialCost(ShiftProductionEntry::findOrFail($shortEntryId));
        $this->assertNull($cost['lines'][0]['unit_cost']);
        $this->assertNull($cost['lines'][0]['cost']);
        $this->assertNull($cost['total_cost']);

        // A line the ledger CAN price is untouched — this is not a blanket
        // distrust of zero, it is keyed off the recorded shortfall.
        StockBalance::query()
            ->where('item_id', $this->resin->id)
            ->where('warehouse_id', $this->dayBin->id)
            ->update(['quantity' => '500.0000', 'average_cost' => '85.0000']);

        $pricedEntryId = $this->startBatch();
        $this->complete($pricedEntryId, '100')->assertOk();

        $priced = $service->materialCost(ShiftProductionEntry::findOrFail($pricedEntryId));
        $this->assertSame('85.0000', $priced['lines'][0]['unit_cost']);
        $this->assertSame('8500.0000', $priced['total_cost']);
    }

    // ---------------------------------------------------------------
    // 2. Only the part that was actually short
    // ---------------------------------------------------------------

    public function test_a_partial_shortfall_records_only_the_short_part(): void
    {
        StockBalance::create(['item_id' => $this->resin->id, 'warehouse_id' => $this->dayBin->id, 'quantity' => '50.0000', 'average_cost' => '85.0000']);

        $entryId = $this->startBatch();
        $this->complete($entryId)->assertOk();

        // 118.998 issued against 50 recorded: 68.998 of it was never on the
        // books, and that — not the whole issue — is the gap.
        $this->assertSame('-68.9980', $this->binBalance());

        $recorded = ShiftProductionEntry::findOrFail($entryId)->config_snapshot['stock_shortfalls'];
        $this->assertCount(1, $recorded);
        $this->assertSame('68.9980', $recorded[0]['short_kg']);
    }

    /**
     * Handover completes the outgoing segment through completeBatch, so it
     * inherits the allowance — and that inheritance is the whole point:
     * a bin nobody had entered stock for must not be able to end a run at
     * shift change, with the incoming shift already at the machine.
     *
     * Pinned rather than assumed, because handover also records its own
     * closing day-bin counts around the completion; nothing else in this
     * file would notice if that path stopped agreeing with the direct one.
     */
    public function test_a_handover_inherits_the_allowance_and_records_the_shortfall(): void
    {
        config()->set('production.traceability_enabled', true);

        $entryId = $this->startBatch();
        $evening = Shift::create(['name' => 'Evening', 'start_time' => '14:00', 'end_time' => '22:00']);

        $child = $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/handover", [
            'shift_id' => $evening->id,
            'production_date' => '2026-07-30',
            'completion' => [
                'quantity_produced' => '8100',
                'running_hours' => '8',
                'material_consumptions' => [
                    ['item_id' => $this->resin->id, 'warehouse_id' => $this->dayBin->id, 'quantity_issued_kg' => '118.998'],
                ],
            ],
        ])->assertOk()->json('data');

        $parent = ShiftProductionEntry::findOrFail($entryId);
        $this->assertSame(BatchStatus::Completed, $parent->batch_status);
        $this->assertSame('118.9980', $parent->config_snapshot['stock_shortfalls'][0]['short_kg']);
        $this->assertSame('-118.9980', $this->binBalance());

        // And the run continued into the incoming shift.
        $this->assertSame($entryId, $child['parent_entry_id']);
    }

    // ---------------------------------------------------------------
    // 3. The flag off — the old refusal, now readable
    // ---------------------------------------------------------------

    public function test_the_flag_off_restores_the_refusal_with_a_readable_message(): void
    {
        config()->set('production.stock.allow_negative_on_completion', false);

        $entryId = $this->startBatch();

        $message = $this->complete($entryId)->assertStatus(422)->json('message');

        // Names, both quantities, and the fix — asserted deliberately
        // instead of the ids, because the ids are what made the original
        // message useless to the person reading it.
        $this->assertStringContainsString('Billion Pet Resin IV-0.8', $message);
        $this->assertStringContainsString('Factory Day Bin', $message);
        $this->assertStringContainsString('0.0000 recorded there', $message);
        $this->assertStringContainsString('118.998 needed', $message);
        $this->assertStringContainsString('Receive the material against its purchase, or enter its opening stock on the Day Bin page', $message);

        // A refusal must refuse everything: the batch is still running, no
        // consumption landed, and no balance row was left behind.
        $entry = ShiftProductionEntry::findOrFail($entryId);
        $this->assertSame(BatchStatus::InProgress, $entry->batch_status);
        $this->assertSame(0, $entry->materialConsumptions()->count());
        $this->assertNull($this->binBalance());
    }

    // ---------------------------------------------------------------
    // 4. Every other issue path keeps its hard block
    // ---------------------------------------------------------------

    /**
     * The allowance is the COMPLETION path's alone. A work order is a
     * planned issue against a known store — nothing has been consumed yet
     * when it is released, so there is no accomplished fact to record and
     * the refusal is still the right answer.
     */
    public function test_a_work_order_release_still_refuses_even_with_the_completion_flag_on(): void
    {
        $this->assertTrue(config('production.stock.allow_negative_on_completion'));

        $workOrderId = $this->postJson('/api/v1/production/work-orders', [
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->dayBin->id,
            'quantity_planned' => '8100',
        ])->assertCreated()->json('data.id');

        $message = $this->postJson("/api/v1/production/work-orders/{$workOrderId}/release")
            ->assertStatus(422)
            ->json('message');

        // Refused — but refused in the same human sentence, because the
        // readability fix is not conditional on the allowance.
        $this->assertStringContainsString('Billion Pet Resin IV-0.8', $message);
        $this->assertStringContainsString('Factory Day Bin', $message);

        $this->assertNull($this->binBalance());
    }

    // ---------------------------------------------------------------
    // 5. Tally sees what the shift said it consumed
    // ---------------------------------------------------------------

    public function test_the_tally_voucher_carries_the_submitted_consumption_unchanged(): void
    {
        $entryId = $this->startBatch();
        $this->complete($entryId)->assertOk();

        $service = app(ShiftProductionEntryService::class);
        // Four-eyes: the accountant gate refuses the PM's own account.
        $accountant = User::factory()->create();
        $service->pmApprove(ShiftProductionEntry::findOrFail($entryId), $this->user->id);
        $service->accountantApprove(ShiftProductionEntry::findOrFail($entryId), $accountant->id);

        $voucher = TallySyncEntry::where('tally_voucher_type', 'Manufacturing Journal')->firstOrFail();

        // The full 118.998 kg, against the bin it came out of. Tally permits
        // negative stock; the voucher's job is to say what happened, not to
        // trim it to what our balance could cover. The payload SHAPE is
        // untouched — same keys, same one line per consumption.
        $this->assertSame(['item', 'quantity', 'godown'], array_keys($voucher->payload['consumed'][0]));
        $this->assertCount(1, $voucher->payload['consumed']);
        $this->assertSame('Billion Pet Resin IV-0.8', $voucher->payload['consumed'][0]['item']);
        $this->assertSame(0, bccomp('118.998', (string) $voucher->payload['consumed'][0]['quantity'], 4));
        $this->assertSame('Factory Day Bin', $voucher->payload['consumed'][0]['godown']);
        $this->assertSame(0, bccomp('8100', (string) $voucher->payload['produced'][0]['quantity'], 4));
    }
}
