<?php

namespace Tests\Feature\TallySync\PerType;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Events\ShiftProductionEntryApproved;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\TallySync\Models\TallySyncEntry;
use Spatie\Permission\Models\Role;

/**
 * Production, SHIFT granularity (tally-sync.voucher_granularity = 'shift',
 * the packaged default): the accountant's approval → ShiftProductionEntry-
 * Approved → ONE 'Stock Journal' per (production_date, shift), morphed to
 * the Shift, membership tracked on shift_production_entries.tally_sync_
 * entry_id (TallyTransactionCategory::ProductionStockJournalShift).
 *
 * This type's own facts beyond the shared lifecycle:
 *
 *   - DUPLICATE-REFUSED has TWO layers: the approval chain refuses a second
 *     accountant-approve (as in batch mode), AND the enqueue itself is
 *     idempotent — re-announcing an already-vouchered entry returns its
 *     voucher untouched (TallySyncService::enqueueShiftVoucher, the
 *     tally_sync_entry_id guard) rather than double-counting quantities;
 *   - a SECOND entry approved in the same shift is not a duplicate: it MERGES
 *     into the open voucher (voucher.merged on the history), quantities
 *     summed, still one row — and the ack/fail fans out to every member.
 */
class ProductionShiftLifecycleTest extends PerTypeLifecycleTestCase
{
    private Shift $shift;

    private WorkCenter $machine;

    private Item $bottle;

    private Item $resin;

    private Warehouse $fgStore;

    private Warehouse $rmStore;

    private ShiftProductionEntry $entry;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tally-sync.voucher_granularity' => 'shift']);

        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->machine = WorkCenter::create(['code' => 'M-01', 'name' => 'Machine 1']);
        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'NOS', 'tally_stock_item_guid' => 'itm-bottle']);
        $this->resin = Item::create(['sku' => 'RES-1', 'name' => 'PET Resin', 'uom' => 'KG', 'tally_stock_item_guid' => 'itm-resin']);
        $this->fgStore = Warehouse::create(['code' => 'WH-FG', 'name' => 'FG Store', 'tally_guid' => 'gd-fg']);
        $this->rmStore = Warehouse::create(['code' => 'WH-RM', 'name' => 'RM Store', 'tally_guid' => 'gd-rm']);

        $this->entry = $this->pendingEntry('5000', '250.0000', 'B-1');
    }

    /** A completed, counted batch of the shift awaiting the chain — a long-past date, whose shift has ended. */
    private function pendingEntry(string $produced, string $consumedKg, string $batchNumber): ShiftProductionEntry
    {
        $entry = ShiftProductionEntry::create([
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fgStore->id,
            'production_date' => '2026-07-23',
            'batch_status' => BatchStatus::Completed,
            'batch_number' => $batchNumber,
            'quantity_produced' => $produced,
            'quantity_scrap' => '0',
            'status' => ShiftProductionEntryStatus::Pending,
        ]);
        $entry->materialConsumptions()->create([
            'item_id' => $this->resin->id, 'warehouse_id' => $this->rmStore->id, 'quantity_issued_kg' => $consumedKg,
        ]);

        return $entry;
    }

    private function desk(string $name, string $role): User
    {
        $user = $this->staff($name, ['production.view', 'production.manage']);
        $user->assignRole(Role::findOrCreate($role, 'web'));

        return $user;
    }

    /** Walk the two approval endpoints for an entry. */
    private function approve(ShiftProductionEntry $entry): void
    {
        $this->asUser($this->desk('Plant Manager Kumar', 'Plant Manager'))
            ->postJson("/api/v1/production/shift-production-entries/{$entry->id}/pm-approve")
            ->assertOk();
        $this->asUser($this->desk('Vincent Accounts', 'Accounts'))
            ->postJson("/api/v1/production/shift-production-entries/{$entry->id}/accountant-approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    protected function enqueueViaDomain(): TallySyncEntry
    {
        $this->approve($this->entry);

        $voucher = TallySyncEntry::query()->sole();
        $this->assertSame($voucher->id, $this->entry->fresh()->tally_sync_entry_id, 'Membership is stamped on the entry');

        return $voucher;
    }

    protected function attemptDuplicateEnqueue(TallySyncEntry $entry): void
    {
        // Layer one: the chain refuses a second approval …
        $this->asUser($this->desk('Vincent Accounts', 'Accounts'))
            ->postJson("/api/v1/production/shift-production-entries/{$this->entry->id}/accountant-approve")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot transition shift production entry from "approved" to "approved".');

        // … layer two: even the raw domain event re-fired for an entry that
        // already carries a voucher returns that voucher, untouched.
        event(new ShiftProductionEntryApproved($this->entry->fresh()));

        $this->assertSame($entry->id, $this->entry->fresh()->tally_sync_entry_id);
        $this->assertSame($entry->payload, TallySyncEntry::query()->sole()->payload, 'A re-announced member must not change the payload');
    }

    protected function expectedCategoryKey(): string
    {
        return 'production_stock_journal_shift';
    }

    protected function expectedVoucherType(): string
    {
        return 'Stock Journal';
    }

    protected function expectedDocumentNumber(TallySyncEntry $entry): string
    {
        return "SJ-20260723-S{$this->shift->id}";
    }

    protected function tallyRejection(): string
    {
        return "Stock Item '500ml PET Bottle' does not exist!";
    }

    protected function expectedFixPath(): ?string
    {
        return '/production/standards?view=incomplete&missing_tally=1';
    }

    public function test_a_second_approval_in_the_same_shift_merges_into_the_one_voucher_and_the_result_fans_out(): void
    {
        $voucher = $this->enqueueViaDomain();
        $this->assertSame((new Shift)->getMorphClass(), $voucher->syncable_type);
        $this->assertSame($this->shift->id, (int) $voucher->syncable_id);
        $this->assertSame([$this->entry->id], $voucher->payload['entry_ids']);

        // A shift-mate approved before the agent has collected the voucher
        // joins it: still ONE row, quantities summed, the join on the record.
        $second = $this->pendingEntry('3000', '100.0000', 'B-2');
        $this->approve($second);

        $this->assertSame(1, TallySyncEntry::query()->count(), 'Both approvals must land in ONE shift voucher');
        $merged = TallySyncEntry::query()->sole();
        $this->assertSame($voucher->id, $merged->id);
        $this->assertSame([$this->entry->id, $second->id], $merged->payload['entry_ids']);
        $this->assertSame(8000.0, (float) $merged->payload['produced'][0]['quantity']);
        $this->assertSame(350.0, (float) $merged->payload['consumed'][0]['quantity']);
        $this->assertSame($voucher->id, $second->fresh()->tally_sync_entry_id);
        $this->assertHistory($voucher->id, ['voucher.enqueued', 'voucher.merged'], [['system', null], ['system', null]]);
        $this->assertSame([$second->id], $this->show($voucher->id)->json('data.history.1.details.joined_entry_ids'));

        // The list shows the shift voucher once, under its own category, with
        // the summed lines.
        $row = $this->listedRow($voucher->id);
        $this->assertSame('production_stock_journal_shift', $row['category']['key']);
        $this->assertSame("SJ-20260723-S{$this->shift->id}", $row['document_number']);
        $this->assertNull($row['hold'], 'A long-ended shift with a zero idle-hold is not held');

        // Fail → both members read failed; ack → both read synced.
        $this->asAgent()->getJson('/api/v1/tally-sync/pending')->assertOk();
        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$voucher->id}/fail", ['error_message' => $this->tallyRejection()])->assertOk();
        $this->assertSame(ShiftProductionEntryStatus::Failed, $this->entry->fresh()->status);
        $this->assertSame(ShiftProductionEntryStatus::Failed, $second->fresh()->status);

        $this->asUser($this->manager())->postJson("/api/v1/tally-sync/entries/{$voucher->id}/retry")->assertOk();
        $this->asAgent()->getJson('/api/v1/tally-sync/pending')->assertOk();
        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$voucher->id}/ack")->assertOk();
        $this->assertSame(ShiftProductionEntryStatus::Synced, $this->entry->fresh()->status);
        $this->assertSame(ShiftProductionEntryStatus::Synced, $second->fresh()->status);

        // An entry approved AFTER the voucher synced opens a follow-up (-2)
        // rather than touching a voucher that is in the books.
        $third = $this->pendingEntry('1000', '50.0000', 'B-3');
        $this->approve($third);
        $this->assertSame(2, TallySyncEntry::query()->count());
        $followUp = TallySyncEntry::query()->orderByDesc('id')->first();
        $this->assertSame("SJ-20260723-S{$this->shift->id}-2", $followUp->payload['voucher_number']);
        $this->assertSame($followUp->id, $third->fresh()->tally_sync_entry_id);
        $this->assertSame($voucher->id, $this->show($followUp->id)->json('data.history.0.details.follow_up_of'));
    }
}
