<?php

namespace App\Modules\TallySync\Services;

use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\Invoice;
use App\Modules\TallySync\Models\Enums\TallyTransactionCategory;
use App\Modules\TallySync\Models\TallySyncEntry;

/**
 * Reads a sync entry and says what it is — category, business date,
 * document number, party, items — from the two columns and the payload the
 * entry already carries. Derived on every read, stored nowhere
 * (TALLY-SYNC-CHAIN.md §3 "Classification, derived not stored").
 *
 * What this class NEVER does: query Tally, query the syncable, or match
 * fuzzily. classify() is an exact-pair table over tally_voucher_type and
 * syncable_type; anything off the table is Unknown, not a best guess. The
 * field readers below return null for a key the payload does not carry —
 * they do not reach into the source model to fill the gap.
 *
 * THE PAYLOAD KEYS READ HERE, quoted from TallySyncService's builders so
 * this class cannot drift from them silently:
 *
 *   Sales            enqueueSalesInvoice()      voucher_date · voucher_number · party_ledger · lines[{item, quantity, rate, amount}]
 *   Journal          enqueueJournalEntry()      voucher_date · voucher_number · lines[{ledger, debit, credit, memo}]   (no party, no item)
 *   Receipt Note     enqueueGoodsReceiptNote()  voucher_date · voucher_number · party_ledger · lines[{item, quantity, rate, amount}]
 *   Purchase Order   enqueuePurchaseOrder()     voucher_date · voucher_number · party_ledger · purchase_ledger · godown · lines[{item, quantity, rate, amount, schedules[{due_date, quantity, amount}]}]
 *   Delivery Note    enqueueDelivery()          voucher_date · voucher_number · party_ledger · lines[{item, quantity}]
 *   Manufacturing J. buildBatchVoucherPayload() voucher_date · voucher_number · produced[{item, quantity}] · consumed[{item, quantity, godown}]   (no party)
 *   Stock Journal    shiftVoucherPayload()      voucher_date · voucher_number · shift · produced[{item, quantity, godown}] · consumed[{item, quantity, godown}]   (no party)
 */
class TransactionClassifier
{
    /**
     * THE CLASSIFICATION TABLE, ONCE: each ERP-built category and the exact
     * (tally_voucher_type, syncable_type) pairs that classify to it.
     * classify() reads it row by row, and the query side
     * (TallySyncQueryService) turns the same pairs into WHERE clauses — so
     * "filter by category" can never disagree with the category the resource
     * shows on the row, and adding an enqueue path is one new row here, not
     * two tables to keep in step.
     *
     * The two production labels are told apart by the MORPH, not the label:
     * a Shift-morph voucher is a shift voucher and a ShiftProductionEntry-
     * morph voucher is a batch voucher, whichever of 'Stock Journal' /
     * 'Manufacturing Journal' it is labelled. That is history, not
     * leniency — the first shift vouchers were relabelled 'Manufacturing
     * Journal' server-side to ride the older agent's builder (PR #149) and
     * some synced under that label before the flip back; only the morph
     * ever said what they were. Compared with the same
     * (new Model)->getMorphClass() pattern ShiftVoucherReleaseGate uses.
     *
     * Only categories the ERP BUILDS have rows. A planned or Tally-only
     * category (and Unknown, the fallback) has none: nothing enqueues one,
     * so no pair can name one.
     *
     * @return array<string, list<array{0: string, 1: string}>> category value => [[tally_voucher_type, syncable_type], ...]
     */
    public function pairs(): array
    {
        $shift = (new Shift)->getMorphClass();
        $batch = (new ShiftProductionEntry)->getMorphClass();

        return [
            TallyTransactionCategory::ProductionStockJournalShift->value => [['Stock Journal', $shift], ['Manufacturing Journal', $shift]],
            TallyTransactionCategory::ProductionStockJournalBatch->value => [['Stock Journal', $batch], ['Manufacturing Journal', $batch]],
            TallyTransactionCategory::SalesInvoice->value => [['Sales', (new Invoice)->getMorphClass()]],
            TallyTransactionCategory::DeliveryNote->value => [['Delivery Note', (new Delivery)->getMorphClass()]],
            TallyTransactionCategory::ReceiptNote->value => [['Receipt Note', (new GoodsReceiptNote)->getMorphClass()]],
            // Phase 6: the staged Purchase Order voucher (DEC-20260812-002).
            TallyTransactionCategory::PurchaseOrder->value => [['Purchase Order', (new PurchaseOrder)->getMorphClass()]],
            TallyTransactionCategory::Journal->value => [['Journal', (new JournalEntry)->getMorphClass()]],
        ];
    }

    /**
     * The pairs that classify to ONE category — empty for anything the ERP
     * does not build (planned, Tally-only, Unknown), which is exactly what
     * lets a filter on such a key match nothing rather than guess.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function pairsFor(TallyTransactionCategory $category): array
    {
        return $this->pairs()[$category->value] ?? [];
    }

    /**
     * The category, from tally_voucher_type + syncable_type. Both must
     * agree with one pair of the table above; a label with the wrong morph,
     * or a morph with the wrong label, is Unknown — never a best guess.
     */
    public function classify(TallySyncEntry $entry): TallyTransactionCategory
    {
        $pair = [$entry->tally_voucher_type, $entry->syncable_type];

        foreach ($this->pairs() as $key => $pairs) {
            if (in_array($pair, $pairs, true)) {
                return TallyTransactionCategory::from($key);
            }
        }

        return TallyTransactionCategory::Unknown;
    }

    /** The voucher's business date (payload voucher_date, "Y-m-d"), not the row's created_at. */
    public function businessDate(TallySyncEntry $entry): ?string
    {
        return $this->payloadString($entry, 'voucher_date');
    }

    /** The document number staff search for — "SPE-12", "SJ-20260723-S1", "GRN-7", "PO-3". */
    public function documentNumber(TallySyncEntry $entry): ?string
    {
        return $this->payloadString($entry, 'voucher_number');
    }

    /**
     * The counter-party (payload party_ledger: customer or vendor name).
     * Null for production and journal vouchers — their payloads carry no
     * party_ledger key, and nothing here invents one.
     */
    public function party(TallySyncEntry $entry): ?string
    {
        return $this->payloadString($entry, 'party_ledger');
    }

    /**
     * A one-line view of what the voucher moves: the first item name and
     * how many DISTINCT item names the voucher carries, across produced +
     * consumed (production) or lines (everything else). Produced is read
     * first so a production voucher headlines its finished good, not its
     * resin. Null when the payload names no item at all — a Journal, whose
     * lines are ledgers, or a payload built with no lines.
     *
     * @return array{first: string, count: int}|null
     */
    public function itemSummary(TallySyncEntry $entry): ?array
    {
        $payload = $entry->payload;

        if (! is_array($payload)) {
            return null;
        }

        $names = [];
        foreach (['produced', 'consumed', 'lines'] as $section) {
            $lines = $payload[$section] ?? null;
            foreach (is_array($lines) ? $lines : [] as $line) {
                $item = is_array($line) ? ($line['item'] ?? null) : null;
                if (is_string($item) && $item !== '') {
                    $names[] = $item;
                }
            }
        }

        $distinct = array_values(array_unique($names));

        if ($distinct === []) {
            return null;
        }

        return ['first' => $distinct[0], 'count' => count($distinct)];
    }

    /** A non-empty string at $key on the payload, else null. */
    private function payloadString(TallySyncEntry $entry, string $key): ?string
    {
        $payload = $entry->payload;
        $value = is_array($payload) ? ($payload[$key] ?? null) : null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
