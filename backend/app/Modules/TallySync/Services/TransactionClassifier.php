<?php

namespace App\Modules\TallySync\Services;

use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Procurement\Models\GoodsReceiptNote;
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
 *   Delivery Note    enqueueDelivery()          voucher_date · voucher_number · party_ledger · lines[{item, quantity}]
 *   Manufacturing J. buildBatchVoucherPayload() voucher_date · voucher_number · produced[{item, quantity}] · consumed[{item, quantity, godown}]   (no party)
 *   Stock Journal    shiftVoucherPayload()      voucher_date · voucher_number · shift · produced[{item, quantity, godown}] · consumed[{item, quantity, godown}]   (no party)
 */
class TransactionClassifier
{
    /**
     * The category, from tally_voucher_type + syncable_type. Both must
     * agree with one row of the table; a label with the wrong morph, or a
     * morph with the wrong label, is Unknown.
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
     */
    public function classify(TallySyncEntry $entry): TallyTransactionCategory
    {
        $label = $entry->tally_voucher_type;
        $morph = $entry->syncable_type;

        if (in_array($label, ['Stock Journal', 'Manufacturing Journal'], true)) {
            return match ($morph) {
                (new Shift)->getMorphClass() => TallyTransactionCategory::ProductionStockJournalShift,
                (new ShiftProductionEntry)->getMorphClass() => TallyTransactionCategory::ProductionStockJournalBatch,
                default => TallyTransactionCategory::Unknown,
            };
        }

        return match (true) {
            $label === 'Sales' && $morph === (new Invoice)->getMorphClass() => TallyTransactionCategory::SalesInvoice,
            $label === 'Delivery Note' && $morph === (new Delivery)->getMorphClass() => TallyTransactionCategory::DeliveryNote,
            $label === 'Receipt Note' && $morph === (new GoodsReceiptNote)->getMorphClass() => TallyTransactionCategory::ReceiptNote,
            $label === 'Journal' && $morph === (new JournalEntry)->getMorphClass() => TallyTransactionCategory::Journal,
            default => TallyTransactionCategory::Unknown,
        };
    }

    /** The voucher's business date (payload voucher_date, "Y-m-d"), not the row's created_at. */
    public function businessDate(TallySyncEntry $entry): ?string
    {
        return $this->payloadString($entry, 'voucher_date');
    }

    /** The document number staff search for — "SPE-12", "SJ-20260723-S1", "GRN-7". */
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
