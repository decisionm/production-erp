<?php

namespace App\Modules\TallySync\Services;

use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\Invoice;
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
    public function enqueueSalesInvoice(Invoice $invoice): TallySyncEntry
    {
        $invoice->loadMissing(['lines.item', 'customer']);

        $lines = $invoice->lines->map(fn ($line) => [
            'item' => "{$line->item->sku} - {$line->item->name}",
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
            'item' => "{$line->item->sku} - {$line->item->name}",
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
            'item' => "{$line->item->sku} - {$line->item->name}",
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
        $entry->loadMissing(['item', 'warehouse', 'materialConsumptions.item']);

        $consumed = $entry->materialConsumptions->map(fn ($consumption) => [
            'item' => "{$consumption->item->sku} - {$consumption->item->name}",
            'quantity' => $consumption->quantity_issued_kg,
        ])->all();

        $produced = [[
            'item' => "{$entry->item->sku} - {$entry->item->name}",
            'quantity' => $entry->quantity_produced,
        ]];

        return $this->enqueue($entry, 'Manufacturing Journal', [
            'voucher_type' => 'Manufacturing Journal',
            'voucher_date' => $entry->production_date?->toDateString(),
            'voucher_number' => "SPE-{$entry->id}",
            'batch_number' => $entry->batch_number,
            'godown' => $entry->warehouse?->name,
            'narration' => $entry->notes,
            'produced' => $produced,
            'consumed' => $consumed,
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
