<?php

namespace App\Modules\TallySync\Services;

use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\Invoice;
use App\Modules\TallySync\Models\Enums\TallyLedgerRole;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

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
    public function __construct(private readonly TallyLedgerMappingService $ledgerMappings) {}

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
            'godown' => $note->warehouse?->name,
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
            'godown' => $delivery->warehouse?->name,
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
    public function enqueueShiftProductionEntry(ShiftProductionEntry $entry): TallySyncEntry
    {
        $entry->loadMissing(['item', 'warehouse', 'materialConsumptions.item', 'materialConsumptions.warehouse', 'scraps.scrapReason']);

        // Each consumption line names its own godown (the RM store it was
        // issued from) — without it the agent falls back to the voucher's
        // FG godown and Tally deducts resin from the wrong store.
        $consumed = $entry->materialConsumptions->map(fn ($consumption) => [
            'item' => $consumption->item->name,
            'quantity' => $consumption->quantity_issued_kg,
            'godown' => $consumption->warehouse?->name,
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

        return $this->enqueue($entry, 'Manufacturing Journal', [
            'voucher_type' => 'Manufacturing Journal',
            'voucher_date' => $entry->production_date?->toDateString(),
            'voucher_number' => "SPE-{$entry->id}",
            'batch_number' => $entry->batch_number,
            'godown' => $entry->warehouse?->name,
            'narration' => trim(implode('. ', array_filter([$entry->notes, $scrapNote !== '' ? "Scrap — {$scrapNote}" : null]))),
            'produced' => $produced,
            'consumed' => $consumed,
            'scraps' => $scraps,
        ]);
    }

    public function pending(): Collection
    {
        return TallySyncEntry::query()
            ->where('status', TallySyncStatus::Pending)
            ->orderBy('id')
            ->get();
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

    public function markFailed(TallySyncEntry $entry, string $errorMessage): TallySyncEntry
    {
        $entry->update([
            'status' => TallySyncStatus::Failed,
            'error_message' => $errorMessage,
            'attempts' => $entry->attempts + 1,
        ]);

        return $entry;
    }

    public function retry(TallySyncEntry $entry): TallySyncEntry
    {
        $entry->update([
            'status' => TallySyncStatus::Pending,
            'error_message' => null,
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
