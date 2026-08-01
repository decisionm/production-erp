<?php

namespace App\Modules\TallySync\Services;

use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\TallyGodownResolver;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\Invoice;
use App\Modules\TallySync\Models\Enums\TallyLedgerRole;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Builds an XML-agnostic intermediate payload per voucher and queues it —
 * never talks to Tally directly (there is no cloud-to-Tally connection;
 * see TECHNICAL-DOCS.md §6). Payloads carry human-readable ledger/party
 * names rather than internal IDs, since Tally voucher import matches
 * against ledger names already configured in the customer's own Tally
 * company, not our database IDs.
 */
class TallySyncService
{
    /**
     * Every godown name written into a payload goes through $godowns
     * (TallyGodownResolver): an internal warehouse Tally has never heard of
     * (the factory day bin) posts under its parent godown's name — or, in
     * this one-godown factory, under the single Tally-linked godown — so
     * the accountant's books keep seeing exactly the godowns Tally knows.
     */
    public function __construct(
        private readonly TallyLedgerMappingService $ledgerMappings,
        private readonly TallyGodownResolver $godowns,
    ) {}

    public function enqueueSalesInvoice(Invoice $invoice): TallySyncEntry
    {
        $invoice->loadMissing(['lines.item', 'customer']);

        $lines = $invoice->lines->map(fn ($line) => [
            // The exact Tally stock-item name (items are pulled from Tally, so
            // item->name IS the Tally name) — this is what a voucher's
            // <STOCKITEMNAME> must match. Never "sku - name".
            'item' => $line->item->name,
            'quantity' => $line->quantity,
            'rate' => $line->unit_price,
            'amount' => bcmul($line->quantity, $line->unit_price, 4),
        ])->all();

        $totalAmount = array_reduce($lines, fn ($carry, $line) => bcadd($carry, $line['amount'], 4), '0.0000');

        return $this->enqueue($invoice, 'Sales', [
            'voucher_type' => 'Sales',
            'voucher_date' => $invoice->invoice_date?->toDateString(),
            'voucher_number' => "INV-{$invoice->id}",
            'party_ledger' => $invoice->customer->name,
            'party_gstin' => $invoice->customer->gstin,
            // The configured Sales ledger (Settings → Ledger Mappings) instead of
            // a hardcoded "Sales Account", so each client posts to their own.
            'sales_ledger' => $this->ledgerMappings->get(TallyLedgerRole::Sales),
            'narration' => $invoice->notes,
            'lines' => $lines,
            'total_amount' => $totalAmount,
        ]);
    }

    public function enqueueJournalEntry(JournalEntry $entry): TallySyncEntry
    {
        $entry->loadMissing('lines.glAccount');

        $lines = $entry->lines->map(fn ($line) => [
            'ledger' => "{$line->glAccount->code} - {$line->glAccount->name}",
            'debit' => $line->debit,
            'credit' => $line->credit,
            'memo' => $line->memo,
        ])->all();

        return $this->enqueue($entry, 'Journal', [
            'voucher_type' => 'Journal',
            'voucher_date' => $entry->entry_date?->toDateString(),
            'voucher_number' => $entry->reference ?? "JE-{$entry->id}",
            'narration' => $entry->memo,
            'lines' => $lines,
        ]);
    }

    /**
     * Raw material received against a PO → Tally Receipt Note (increases RM
     * stock). Party is the supplier; godown is the receiving warehouse. The
     * agent translates this to the Receipt Note voucher XML.
     */
    public function enqueueGoodsReceiptNote(GoodsReceiptNote $note): TallySyncEntry
    {
        $note->loadMissing(['lines.item', 'warehouse', 'purchaseOrder.vendor']);

        $lines = $note->lines->map(fn ($line) => [
            // The exact Tally stock-item name (items are pulled from Tally, so
            // item->name IS the Tally name) — this is what a voucher's
            // <STOCKITEMNAME> must match. Never "sku - name".
            'item' => $line->item->name,
            'quantity' => $line->quantity,
            'rate' => $line->unit_cost,
            'amount' => bcmul($line->quantity, $line->unit_cost, 4),
        ])->all();

        $totalAmount = array_reduce($lines, fn ($carry, $line) => bcadd($carry, $line['amount'], 4), '0.0000');

        return $this->enqueue($note, 'Receipt Note', [
            'voucher_type' => 'Receipt Note',
            'voucher_date' => $note->received_date?->toDateString(),
            'voucher_number' => "GRN-{$note->id}",
            'party_ledger' => $note->purchaseOrder?->vendor?->name,
            'party_gstin' => $note->purchaseOrder?->vendor?->gstin,
            'godown' => $this->godowns->resolveName($note->warehouse),
            'narration' => $note->notes,
            'lines' => $lines,
            'total_amount' => $totalAmount,
        ]);
    }

    /**
     * Finished goods dispatched to a customer → Tally Delivery Note (reduces FG
     * stock). No pricing — a Delivery Note is a stock movement, not a bill.
     */
    public function enqueueDelivery(Delivery $delivery): TallySyncEntry
    {
        $delivery->loadMissing(['lines.item', 'warehouse', 'salesOrder.customer']);

        $lines = $delivery->lines->map(fn ($line) => [
            // The exact Tally stock-item name (items are pulled from Tally, so
            // item->name IS the Tally name) — this is what a voucher's
            // <STOCKITEMNAME> must match. Never "sku - name".
            'item' => $line->item->name,
            'quantity' => $line->quantity,
        ])->all();

        return $this->enqueue($delivery, 'Delivery Note', [
            'voucher_type' => 'Delivery Note',
            'voucher_date' => $delivery->delivered_date?->toDateString(),
            'voucher_number' => "DN-{$delivery->id}",
            'party_ledger' => $delivery->salesOrder?->customer?->name,
            'party_gstin' => $delivery->salesOrder?->customer?->gstin,
            'godown' => $this->godowns->resolveName($delivery->warehouse),
            'narration' => $delivery->notes,
            'lines' => $lines,
        ]);
    }

    /**
     * An approved shift's production → Tally Manufacturing/Stock Journal:
     * consumes the raw materials issued that shift and produces the finished
     * item into the warehouse godown, carrying the batch number. The agent
     * decides the exact voucher shape (Manufacturing Journal if Tally BOM is
     * enabled, else a plain Stock Journal) — TALLY-SYNC-MASTER-PLAN.md §6.
     */
    public function enqueueShiftProductionEntry(ShiftProductionEntry $entry): ?TallySyncEntry
    {
        // A LOCAL- fixture product exists in this database and nowhere in
        // Tally. A voucher naming it cannot be accepted — Tally answers
        // "Stock Item does not exist" — so queueing one buys nothing and
        // costs the accountant a permanently failing row they must decide
        // what to do with. The batch itself is real and stays fully
        // recorded; only the posting is declined, and loudly, in the log.
        if ($this->isLocalFixtureEntry($entry)) {
            logger()->info('Tally voucher skipped: local-only fixture product.', [
                'shift_production_entry_id' => $entry->id,
                'item_sku' => $entry->item?->sku,
            ]);

            return null;
        }

        if (config('tally-sync.voucher_granularity') === 'shift') {
            return $this->enqueueShiftVoucher($entry);
        }

        return $this->enqueue($entry, 'Manufacturing Journal', $this->buildBatchVoucherPayload($entry));
    }

    /**
     * Whether this entry's produced item is a local-only fixture. Used both
     * as the top-level guard and inside the shift-granularity sweep, so a
     * real item's approval can never drag a fixture's quantities into a
     * shared voucher through the back door.
     */
    private function isLocalFixtureEntry(ShiftProductionEntry $entry): bool
    {
        $entry->loadMissing('item');

        return $entry->item?->isLocalFixture() ?? false;
    }

    /**
     * The per-batch voucher payload, built WITHOUT writing anything.
     *
     * Split out of enqueueShiftProductionEntry() so the pre-post preview and
     * the real post are produced by the same code — a preview built by a
     * parallel implementation would eventually drift from what actually gets
     * sent, which is precisely the confidence the preview exists to provide.
     *
     * @return array<string, mixed>
     */
    public function buildBatchVoucherPayload(ShiftProductionEntry $entry): array
    {
        $entry->loadMissing(['item', 'warehouse', 'materialConsumptions.item', 'materialConsumptions.warehouse', 'scraps.scrapReason']);

        // Each consumption line names its own godown (the RM store it was
        // issued from) — without it the agent falls back to the voucher's
        // FG godown and Tally deducts resin from the wrong store. Resolved
        // through TallyGodownResolver: a line issued from the internal
        // factory day bin posts under the godown Tally actually knows (the
        // bin's parent / the sole company godown), because the bin is an
        // ERP-only location the accountant's books must never see.
        $consumed = $entry->materialConsumptions->map(fn ($consumption) => [
            'item' => $consumption->item->name,
            'quantity' => $consumption->quantity_issued_kg,
            'godown' => $this->godowns->resolveName($consumption->warehouse),
        ])->all();

        $produced = [[
            'item' => $entry->item->name,
            'quantity' => $entry->quantity_produced,
        ]];

        // Scraps ride along as data and as narration text: crediting regrind
        // back into stock as a valued item needs the accountant's valuation
        // rules (master plan Phase 1.5), which aren't codified yet — but the
        // figures should still reach Tally's books in human-readable form
        // rather than silently dropping off the voucher.
        $scraps = $entry->scraps->map(fn ($scrap) => [
            'type' => $scrap->type->value,
            'quantity_nos' => $scrap->quantity_nos,
            'quantity_kg' => $scrap->quantity_kg,
            'reason' => $scrap->scrapReason?->name,
        ])->all();

        $scrapNote = $entry->scraps
            ->map(function ($scrap) {
                $amounts = implode(' / ', array_filter([
                    $scrap->quantity_nos !== null ? "{$scrap->quantity_nos} nos" : null,
                    $scrap->quantity_kg !== null ? "{$scrap->quantity_kg} kg" : null,
                ]));

                return "{$scrap->type->value}: ".($amounts !== '' ? $amounts : '0')
                    .($scrap->scrapReason ? " ({$scrap->scrapReason->name})" : '');
            })
            ->implode('; ');

        return [
            'voucher_type' => 'Manufacturing Journal',
            'voucher_date' => $entry->production_date?->toDateString(),
            'voucher_number' => "SPE-{$entry->id}",
            'batch_number' => $entry->batch_number,
            'godown' => $this->godowns->resolveName($entry->warehouse),
            'narration' => trim(implode('. ', array_filter([$entry->notes, $scrapNote !== '' ? "Scrap — {$scrapNote}" : null]))),
            'produced' => $produced,
            'consumed' => $consumed,
            'scraps' => $scraps,
        ];
    }

    /**
     * 'shift' voucher granularity (config tally-sync.voucher_granularity):
     * instead of one voucher per approved entry, everything a shift produced
     * aggregates into ONE Stock Journal per (production_date, shift) —
     * consumption summed item+godown-wise, production totals item-wise (kept
     * per FG godown so the agent books each into the store it actually
     * landed in). Voucher number: SJ-{Ymd}-S{shift_id}, with -2/-3 suffixes
     * for follow-up vouchers.
     *
     * Membership tracking: shift_production_entries.tally_sync_entry_id — a
     * scalar FK, so an entry can belong to exactly ONE voucher ever, by
     * construction (a pivot would have to police uniqueness; a single-valued
     * column can't double-book). While the shift's voucher is still pending,
     * later approvals merge into it and the payload is rebuilt from all
     * members; once it has synced (or failed), it is closed — its numbers
     * are already in Tally's books — so later approvals open a follow-up
     * voucher instead of mutating history.
     */
    private function enqueueShiftVoucher(ShiftProductionEntry $entry): TallySyncEntry
    {
        return DB::transaction(function () use ($entry) {
            // Idempotent: re-announcing an already-vouchered entry returns
            // its voucher untouched instead of double-counting quantities.
            if ($entry->tally_sync_entry_id !== null) {
                return TallySyncEntry::query()->findOrFail($entry->tally_sync_entry_id);
            }

            $date = $entry->production_date->toDateString();

            // Approved shift-mates not yet in any voucher — normally just
            // the entry that triggered this call; the lock closes the race
            // of two approvals landing at once claiming the same rows.
            $joining = ShiftProductionEntry::query()
                ->whereDate('production_date', $date)
                ->where('shift_id', $entry->shift_id)
                ->where('status', ShiftProductionEntryStatus::Approved->value)
                ->whereNull('tally_sync_entry_id')
                // Entries approved under batch mode already own a per-entry
                // voucher — sweeping them here after a granularity flip
                // would book every quantity into Tally twice.
                ->whereDoesntHave('tallySyncEntries', fn ($query) => $query->whereIn(
                    'status',
                    [TallySyncStatus::Pending->value, TallySyncStatus::Synced->value],
                ))
                // Local-only fixtures never join a voucher. Without this the
                // top-level guard would be bypassed sideways: a REAL item's
                // approval sweeps its approved shift-mates, and a fixture
                // sitting in the same shift would have its quantities posted
                // to Tally under someone else's approval.
                //
                // Written as "doesn't have a LOCAL item" rather than "has a
                // non-LOCAL item" because Item soft-deletes: an entry whose
                // product was retired after the run has NO visible item row,
                // and the positive form would silently drop its quantities
                // from the voucher. Absence of a LOCAL match is the correct
                // test — it includes exactly what the old code included.
                ->whereDoesntHave('item', fn ($query) => $query->where(
                    'sku', 'like', Item::LOCAL_FIXTURE_SKU_PREFIX.'%',
                ))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($joining->isEmpty()) {
                // A concurrent approval already claimed this entry.
                return TallySyncEntry::query()->findOrFail($entry->fresh()->tally_sync_entry_id);
            }

            // Vouchers this (date, shift) already has — derived from the
            // membership column, never by parsing voucher numbers.
            $voucherIds = ShiftProductionEntry::query()
                ->whereDate('production_date', $date)
                ->where('shift_id', $entry->shift_id)
                ->whereNotNull('tally_sync_entry_id')
                ->distinct()
                ->pluck('tally_sync_entry_id');

            // Merge-open means Pending AND never handed to the agent: a
            // payload the agent may already hold must not change under it
            // (it would ack the old shape and silently drop the merged
            // entries from Tally). Row-locked against a concurrent ack.
            $voucher = TallySyncEntry::query()
                ->whereIn('id', $voucherIds)
                ->where('status', TallySyncStatus::Pending)
                ->whereNull('delivered_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($voucher === null) {
                $sequence = $voucherIds->count() + 1;
                $number = "SJ-{$entry->production_date->format('Ymd')}-S{$entry->shift_id}"
                    .($sequence > 1 ? "-{$sequence}" : '');

                $voucher = TallySyncEntry::create([
                    // The morph names the Shift: a shift voucher belongs to
                    // no single entry. Members hang off tally_sync_entry_id.
                    'syncable_type' => (new Shift)->getMorphClass(),
                    'syncable_id' => $entry->shift_id,
                    'tally_voucher_type' => 'Stock Journal',
                    'payload' => $this->shiftVoucherPayload($joining, $number, $entry),
                    'status' => TallySyncStatus::Pending,
                    'attempts' => 0,
                ]);

                ShiftProductionEntry::query()
                    ->whereIn('id', $joining->pluck('id'))
                    ->update(['tally_sync_entry_id' => $voucher->id]);

                return $voucher;
            }

            ShiftProductionEntry::query()
                ->whereIn('id', $joining->pluck('id'))
                ->update(['tally_sync_entry_id' => $voucher->id]);

            // Rebuild the payload from ALL members (pre-existing + just
            // joined) — recomputing the sums beats patching incrementally.
            $members = ShiftProductionEntry::query()
                ->where('tally_sync_entry_id', $voucher->id)
                ->orderBy('id')
                ->get();
            $voucher->update([
                'payload' => $this->shiftVoucherPayload($members, $voucher->payload['voucher_number'], $entry),
            ]);

            return $voucher->fresh();
        });
    }

    /**
     * Aggregate a shift voucher's members into one Stock Journal payload:
     * consumption lines summed per (item, godown) so the agent deducts each
     * RM from the store it was actually issued from, production totals per
     * (item, FG godown).
     *
     * @param  Collection<int, ShiftProductionEntry>  $members
     */
    private function shiftVoucherPayload(Collection $members, string $voucherNumber, ShiftProductionEntry $entry): array
    {
        $members->loadMissing(['item', 'warehouse', 'shift', 'materialConsumptions.item', 'materialConsumptions.warehouse']);

        // Godown names resolve through TallyGodownResolver, same as the
        // per-batch payload: a line issued from the internal day bin books
        // under its parent godown's name (the accountant's single godown),
        // never under a warehouse Tally has never heard of. Aggregation
        // still keys on the REAL warehouse_id — two internal bins aliasing
        // to the same godown stay separate lines, which Tally sums anyway.
        $consumed = [];
        $produced = [];
        foreach ($members as $member) {
            foreach ($member->materialConsumptions as $consumption) {
                $key = "{$consumption->item_id}@{$consumption->warehouse_id}";
                $consumed[$key] = [
                    'item' => $consumption->item->name,
                    'quantity' => bcadd($consumed[$key]['quantity'] ?? '0.0000', (string) $consumption->quantity_issued_kg, 4),
                    'godown' => $this->godowns->resolveName($consumption->warehouse),
                ];
            }

            $key = "{$member->item_id}@{$member->warehouse_id}";
            $produced[$key] = [
                'item' => $member->item->name,
                'quantity' => bcadd($produced[$key]['quantity'] ?? '0.0000', (string) $member->quantity_produced, 4),
                'godown' => $this->godowns->resolveName($member->warehouse),
            ];
        }

        $batches = $members->pluck('batch_number')->filter()->values();

        return [
            'voucher_type' => 'Stock Journal',
            'voucher_date' => $entry->production_date->toDateString(),
            'voucher_number' => $voucherNumber,
            'shift' => $members->first()?->shift?->name,
            'narration' => trim("Shift production — {$members->count()} entries"
                .($batches->isNotEmpty() ? '. Batches: '.$batches->implode(', ') : '')),
            'produced' => array_values($produced),
            'consumed' => array_values($consumed),
            // Human/agent-readable membership; the authoritative tracker is
            // shift_production_entries.tally_sync_entry_id.
            'entry_ids' => $members->pluck('id')->all(),
        ];
    }

    public function pending(): Collection
    {
        $entries = TallySyncEntry::query()
            ->where('status', TallySyncStatus::Pending)
            ->orderBy('id')
            ->get();

        // First delivery closes the merge window (see the shift-voucher
        // merge query). Unacked vouchers keep reappearing on later polls.
        //
        // The rows are READ BEFORE THEY ARE STAMPED on purpose, and the
        // agent depends on it: the delivered_at each entry carries in this
        // response is its value as of the moment of this poll — null the
        // first time it is handed out, set on every re-poll. That is the
        // agent's idempotency line ("have I been given this voucher
        // before?"), the one thing standing between a lost acknowledgement
        // and the same voucher posted twice into live books. Stamp first
        // and every delivery looks like a re-delivery; drop the mass
        // update's ->whereNull() and a re-delivery looks brand new. Keep
        // this ordering. Covered by VoucherPostedOnceTest.
        TallySyncEntry::query()
            ->whereIn('id', $entries->pluck('id'))
            ->whereNull('delivered_at')
            ->update(['delivered_at' => now()]);

        return $entries;
    }

    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return TallySyncEntry::query()
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function markSynced(TallySyncEntry $entry): TallySyncEntry
    {
        $entry->update([
            'status' => TallySyncStatus::Synced,
            'synced_at' => now(),
            'error_message' => null,
        ]);

        return $entry;
    }

    /**
     * The agent reporting that Tally rejected this voucher.
     *
     * Refused, loudly but harmlessly, once the voucher is already synced:
     * "failed" is what puts the Retry button on the dashboard, and retrying
     * a voucher Tally has already accepted posts it into the live books a
     * second time. The only way a synced entry can be reported failed is a
     * confused/duplicated report — a stale agent replaying an old queue, or
     * an ack that succeeded on a connection whose response never arrived —
     * and in every one of those cases the books already hold the voucher.
     *
     * Note the guard is on SYNCED, not on delivered: every entry the agent
     * can report on has been delivered to it by definition (pending()
     * stamps delivered_at on the way out), so refusing delivered entries
     * would silence real Tally rejections ("Stock Item does not exist")
     * entirely — the one thing SyncFailureVisibilityTest exists to protect.
     */
    public function markFailed(TallySyncEntry $entry, string $errorMessage): TallySyncEntry
    {
        if ($entry->isInTally()) {
            logger()->warning('Tally sync failure ignored: that voucher is already in Tally.', [
                'tally_sync_entry_id' => $entry->id,
                'voucher_number' => $entry->voucherNumber(),
                'synced_at' => $entry->synced_at?->toIso8601String(),
                'reported_error' => $errorMessage,
            ]);

            return $entry;
        }

        $entry->update([
            'status' => TallySyncStatus::Failed,
            'error_message' => $errorMessage,
            'attempts' => $entry->attempts + 1,
        ]);

        return $entry;
    }

    /**
     * Put a voucher back in the queue for the agent to post again.
     *
     * Refuses outright once the voucher is in Tally — see markFailed(). By
     * the time anyone can click Retry the money is in someone's books, so
     * the honest answer is "go look at Tally", not a second voucher.
     *
     * Clearing delivered_at is what actually re-arms the agent: the agent
     * refuses to rebuild a voucher for any entry that arrives already
     * stamped as delivered (it cannot tell a lost ack from a lost post),
     * so a re-queue that left the stamp in place would be a Retry button
     * that quietly does nothing. Nulling it makes each retry a ONE-SHOT
     * posting authorisation — pending() re-stamps on the very next poll, so
     * the guard closes again by itself.
     *
     * @throws ValidationException 422 naming the voucher to check in Tally
     */
    public function retry(TallySyncEntry $entry): TallySyncEntry
    {
        if ($entry->isInTally()) {
            $synced = $entry->synced_at?->format('d M Y H:i');

            throw ValidationException::withMessages([
                'entry' => "This voucher is already in Tally as {$entry->voucherNumber()}"
                    .($synced !== null ? " (synced {$synced})" : '')
                    .' — check Tally before anything else. Re-queueing it would post the same voucher twice.',
            ]);
        }

        $entry->update([
            'status' => TallySyncStatus::Pending,
            'error_message' => null,
            'delivered_at' => null,
        ]);

        return $entry;
    }

    private function enqueue(Model $syncable, string $voucherType, array $payload): TallySyncEntry
    {
        return TallySyncEntry::create([
            'syncable_type' => $syncable->getMorphClass(),
            'syncable_id' => $syncable->getKey(),
            'tally_voucher_type' => $voucherType,
            'payload' => $payload,
            'status' => TallySyncStatus::Pending,
            'attempts' => 0,
        ]);
    }
}
