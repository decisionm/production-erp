<?php

namespace Tests\Feature\TallySync\PerType;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\TallySync\Models\TallySyncEntry;
use Spatie\Permission\Models\Role;

/**
 * Production, BATCH granularity (tally-sync.voucher_granularity = 'batch'):
 * the accountant's approval (POST .../accountant-approve, after the PM's
 * POST .../pm-approve) → ShiftProductionEntryApproved → ONE voucher per
 * approved entry, labelled 'Manufacturing Journal' on the row (a Stock
 * Journal on the wire — TallyTransactionCategory::ProductionStockJournalBatch).
 *
 * This type's own facts beyond the shared lifecycle:
 *
 *   - DUPLICATE-REFUSED is the approval chain's atomic status update
 *     (ShiftProductionEntryService::advance): a second accountant-approve
 *     finds no row at pm_approved and is refused — the event that enqueues
 *     never fires twice for one entry;
 *   - the voucher's ack/fail WRITES BACK onto the entry's own status
 *     (approved → failed → synced) so the approval queue shows real sync
 *     state (TallySyncEventServiceProvider — the reverse hop).
 */
class ProductionBatchLifecycleTest extends PerTypeLifecycleTestCase
{
    private ShiftProductionEntry $entry;

    protected function setUp(): void
    {
        parent::setUp();

        // Batch mode under test explicitly; the packaged default is shift.
        config(['tally-sync.voucher_granularity' => 'batch']);

        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => 'M-01', 'name' => 'Machine 1']);
        $bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'NOS', 'tally_stock_item_guid' => 'itm-bottle']);
        $resin = Item::create(['sku' => 'RES-1', 'name' => 'PET Resin', 'uom' => 'KG', 'tally_stock_item_guid' => 'itm-resin']);
        $fgStore = Warehouse::create(['code' => 'WH-FG', 'name' => 'FG Store', 'tally_guid' => 'gd-fg']);
        $rmStore = Warehouse::create(['code' => 'WH-RM', 'name' => 'RM Store', 'tally_guid' => 'gd-rm']);

        // A completed, counted batch awaiting the chain — a long-past date, so
        // nothing about "today" enters the picture.
        $this->entry = ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $bottle->id,
            'warehouse_id' => $fgStore->id,
            'production_date' => '2026-07-23',
            'batch_status' => BatchStatus::Completed,
            'batch_number' => 'M01-MOR-20260723-1',
            'quantity_produced' => '5000',
            'quantity_scrap' => '0',
            'status' => ShiftProductionEntryStatus::Pending,
        ]);
        $this->entry->materialConsumptions()->create([
            'item_id' => $resin->id, 'warehouse_id' => $rmStore->id, 'quantity_issued_kg' => '250.0000',
        ]);
    }

    /** A desk with the production permissions and the named approval role — one person per desk (four eyes). */
    private function desk(string $name, string $role): User
    {
        $user = $this->staff($name, ['production.view', 'production.manage']);
        $user->assignRole(Role::findOrCreate($role, 'web'));

        return $user;
    }

    protected function enqueueViaDomain(): TallySyncEntry
    {
        $this->asUser($this->desk('Plant Manager Kumar', 'Plant Manager'))
            ->postJson("/api/v1/production/shift-production-entries/{$this->entry->id}/pm-approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'pm_approved');
        $this->assertSame(0, TallySyncEntry::query()->count(), 'PM approval must not enqueue');

        // The accountant is FINAL — this is the posting gate.
        $this->asUser($this->desk('Vincent Accounts', 'Accounts'))
            ->postJson("/api/v1/production/shift-production-entries/{$this->entry->id}/accountant-approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        return TallySyncEntry::query()->sole();
    }

    protected function attemptDuplicateEnqueue(TallySyncEntry $entry): void
    {
        // A stale approval screen pressing Approve again: the atomic update
        // finds no row at pm_approved and refuses.
        $this->asUser($this->desk('Vincent Accounts', 'Accounts'))
            ->postJson("/api/v1/production/shift-production-entries/{$this->entry->id}/accountant-approve")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot transition shift production entry from "approved" to "approved".');

        $this->assertSame(ShiftProductionEntryStatus::Approved, $this->entry->fresh()->status);
    }

    protected function expectedCategoryKey(): string
    {
        return 'production_stock_journal_batch';
    }

    protected function expectedVoucherType(): string
    {
        return 'Manufacturing Journal';
    }

    protected function expectedDocumentNumber(TallySyncEntry $entry): string
    {
        return "SPE-{$this->entry->id}";
    }

    protected function tallyRejection(): string
    {
        return "Stock Item '500ml PET Bottle' does not exist!";
    }

    protected function expectedFixPath(): ?string
    {
        return '/production/standards?view=incomplete&missing_tally=1';
    }

    public function test_the_voucher_carries_the_batch_and_the_agents_result_writes_back_onto_the_entry(): void
    {
        $voucher = $this->enqueueViaDomain();

        // The per-batch shape (OutboundVoucherTest pins the lines in depth):
        // the batch number, the produced item into the FG store, the resin
        // out of the store it was issued from.
        $this->assertSame('M01-MOR-20260723-1', $voucher->payload['batch_number']);
        $this->assertSame('2026-07-23', $voucher->payload['voucher_date']);
        $this->assertSame('500ml PET Bottle', $voucher->payload['produced'][0]['item']);
        // sqlite hands decimals back as numbers, MySQL as strings — the
        // figure, not the driver's formatting, is what is pinned.
        $this->assertSame(5000.0, (float) $voucher->payload['produced'][0]['quantity']);
        $this->assertSame('PET Resin', $voucher->payload['consumed'][0]['item']);
        $this->assertSame('RM Store', $voucher->payload['consumed'][0]['godown']);
        $this->assertSame((new ShiftProductionEntry)->getMorphClass(), $voucher->syncable_type);
        $this->assertSame($this->entry->id, (int) $voucher->syncable_id);

        // The reverse hop: fail → the entry shows failed; ack → synced. Only
        // production carries sync state on its source row.
        $this->asAgent()->getJson('/api/v1/tally-sync/pending')->assertOk();
        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$voucher->id}/fail", ['error_message' => $this->tallyRejection()])->assertOk();
        $this->assertSame(ShiftProductionEntryStatus::Failed, $this->entry->fresh()->status);

        $this->asUser($this->manager())->postJson("/api/v1/tally-sync/entries/{$voucher->id}/retry")->assertOk();
        // Retrying only re-queues the voucher — the entry stays failed until
        // the agent actually reports success.
        $this->assertSame(ShiftProductionEntryStatus::Failed, $this->entry->fresh()->status);

        $this->asAgent()->getJson('/api/v1/tally-sync/pending')->assertOk();
        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$voucher->id}/ack")->assertOk();
        $this->assertSame(ShiftProductionEntryStatus::Synced, $this->entry->fresh()->status);

        // And a late failure report, refused on the queue, never reaches the
        // entry: no synced batch flips back to failed on the floor.
        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$voucher->id}/fail", ['error_message' => 'timeout of 15000ms exceeded'])->assertOk();
        $this->assertSame(ShiftProductionEntryStatus::Synced, $this->entry->fresh()->status);
    }
}
