<?php

namespace App\Modules\TallySync\Models\Enums;

/**
 * What kind of Tally transaction a sync entry IS — derived from the entry
 * by TransactionClassifier, never stored (docs/engineering/TALLY-SYNC-CHAIN.md
 * §3 "Classification, derived not stored").
 *
 * The catalogue deliberately names more than the ERP builds. The Sync
 * Control Center has to be able to say, honestly and without reading Tally,
 * which of the accountant's transactions the ERP mirrors and which live in
 * Tally alone — so the categories that exist only in the books are cases
 * here too, with source 'tally', direction 'none' and (on the query side)
 * a count of null: nothing was measured, nothing was mirrored, and a zero
 * would claim otherwise.
 *
 * Case order IS the catalogue order and is stable: ERP-built first, then
 * planned, then Tally-only, then unknown. catalogue() iterates cases().
 *
 * Every wire voucher type below is the exact <VOUCHERTYPENAME> the agent
 * emits (tally-sync-agent/src/tally/voucherBuilders/*.ts) or, for the
 * Tally-only rows, the voucher type name as it appears on the accountant's
 * own Statistics screen (docs/factory/TALLY-EVIDENCE-2026-08-12.md §A, the
 * 12-Aug census, 1-Apr-26 to 10-Aug-26). Nothing here is invented.
 */
enum TallyTransactionCategory: string
{
    // ── ERP-built today (source 'erp') — one case per enqueue path in
    //    TallySyncService. ─────────────────────────────────────────────────

    /** enqueueShiftVoucher(): one Stock Journal per (production_date, shift). */
    case ProductionStockJournalShift = 'production_stock_journal_shift';

    /**
     * enqueueShiftProductionEntry() under batch granularity: one voucher per
     * approved entry, labelled 'Manufacturing Journal' on the entry and in
     * the ERP. ON THE WIRE IT IS A STOCK JOURNAL — manufacturingJournal.ts
     * emits <VOUCHERTYPENAME>Stock Journal</VOUCHERTYPENAME> deliberately
     * (Tally's BOM-backed Manufacturing Journal is not what the factory
     * posts). erpLabelDiffersFromWire() is true for this case alone.
     */
    case ProductionStockJournalBatch = 'production_stock_journal_batch';

    /** enqueueSalesInvoice(): Invoice → Tally 'Sales'. */
    case SalesInvoice = 'sales_invoice';

    /** enqueueDelivery(): Delivery → Tally 'Delivery Note'. */
    case DeliveryNote = 'delivery_note';

    /** enqueueGoodsReceiptNote(): GoodsReceiptNote → Tally 'Receipt Note'. */
    case ReceiptNote = 'receipt_note';

    /** enqueueJournalEntry(): JournalEntry → Tally 'Journal'. */
    case Journal = 'journal';

    // ── Planned (source 'planned') ───────────────────────────────────────

    /**
     * DEC-20260812-002: purchase orders are raised in the ERP and sent to
     * Tally as a Purchase Order voucher — Phase 6, NOT BUILT. There is no
     * enqueuePurchaseOrder() and no entry can classify here today; the
     * case exists so the catalogue can show the row as planned. Its count
     * is null, never 0: zero would read as "measured, none".
     */
    case PurchaseOrder = 'purchase_order';

    // ── Lives in Tally only (source 'tally', direction none) — the
    //    accountant's transactions the ERP does not mirror, from the 12-Aug
    //    Statistics census (TALLY-EVIDENCE-2026-08-12 §A): Purchase 351,
    //    Payment 925, Receipt 553, Contra 60, Credit Note 17, Debit Note 5.
    //    (Journal 418 in the same census is the Tally-side count of a
    //    category the ERP DOES build; it is not repeated here.) ───────────

    case Purchase = 'purchase';
    case Payment = 'payment';
    case Receipt = 'receipt';
    case Contra = 'contra';
    case CreditNote = 'credit_note';
    case DebitNote = 'debit_note';

    /**
     * NO Sales Order voucher type exists in the books at all — the 12-Aug
     * census lists none. DEC-20260809-003 places all real sales directly in
     * Tally (invoiced there; the ERP Sales module is demo-scale), so the
     * sales flow lives in Tally and the ERP's own sales_orders table is not
     * a Tally flow. Kept in the catalogue so the Control Center can say
     * exactly that rather than leave a gap. wireVoucherType() is null: no
     * agent builder emits one and no such voucher type is in the books.
     */
    case SalesOrder = 'sales_order';

    // ── Anything TransactionClassifier could not match. Never guessed. ────

    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::ProductionStockJournalShift => 'Production — Stock Journal (per shift)',
            self::ProductionStockJournalBatch => 'Production — Stock Journal (per batch, ERP label "Manufacturing Journal")',
            self::SalesInvoice => 'Sales — Invoice',
            self::DeliveryNote => 'Sales — Delivery Note',
            self::ReceiptNote => 'Procurement — Receipt Note',
            self::Journal => 'Finance — Journal',
            self::PurchaseOrder => 'Procurement — Purchase Order (planned, Phase 6)',
            self::Purchase => 'Purchase (lives in Tally)',
            self::Payment => 'Payment (lives in Tally)',
            self::Receipt => 'Receipt (lives in Tally)',
            self::Contra => 'Contra (lives in Tally)',
            self::CreditNote => 'Credit Note (lives in Tally)',
            self::DebitNote => 'Debit Note (lives in Tally)',
            self::SalesOrder => 'Sales Order (no such voucher type in the books; sales are invoiced in Tally)',
            self::Unknown => 'Unknown',
        };
    }

    /**
     * The exact <VOUCHERTYPENAME> the agent emits for this category, or the
     * voucher type name from the census for a Tally-only one. Null when
     * nothing emits one and nothing in the books carries one.
     */
    public function wireVoucherType(): ?string
    {
        return match ($this) {
            // Both production cases ride Tally's Stock Journal — see the
            // ProductionStockJournalBatch docblock for why the label differs.
            self::ProductionStockJournalShift, self::ProductionStockJournalBatch => 'Stock Journal',
            self::SalesInvoice => 'Sales',
            self::DeliveryNote => 'Delivery Note',
            self::ReceiptNote => 'Receipt Note',
            self::Journal => 'Journal',
            self::PurchaseOrder => 'Purchase Order',
            self::Purchase => 'Purchase',
            self::Payment => 'Payment',
            self::Receipt => 'Receipt',
            self::Contra => 'Contra',
            self::CreditNote => 'Credit Note',
            self::DebitNote => 'Debit Note',
            self::SalesOrder, self::Unknown => null,
        };
    }

    /** @return 'erp'|'planned'|'tally'|'unknown' */
    public function source(): string
    {
        return match ($this) {
            self::ProductionStockJournalShift,
            self::ProductionStockJournalBatch,
            self::SalesInvoice,
            self::DeliveryNote,
            self::ReceiptNote,
            self::Journal => 'erp',
            self::PurchaseOrder => 'planned',
            self::Purchase,
            self::Payment,
            self::Receipt,
            self::Contra,
            self::CreditNote,
            self::DebitNote,
            self::SalesOrder => 'tally',
            self::Unknown => 'unknown',
        };
    }

    /**
     * Which way the transaction moves. 'none' means the ERP never carries
     * it — nothing is mirrored either way, so no tally_sync_entries row can
     * ever be of this category (its count is null on the query side).
     *
     * Unknown is 'erp_to_tally' and that is not a guess: the classifier
     * only ever produces Unknown for a row of tally_sync_entries, and every
     * row of that table is ERP→Tally by construction (TALLY-SYNC-CHAIN.md
     * §1, "Direction"). What is unknown is the KIND of outbound transaction,
     * not the fact that it was queued outbound. Keeping the invariant
     * "direction none ⇔ can never have an entry" is what lets the Control
     * Center's direction filter mean one thing.
     *
     * @return 'erp_to_tally'|'none'
     */
    public function direction(): string
    {
        return match ($this->source()) {
            'erp', 'planned', 'unknown' => 'erp_to_tally',
            'tally' => 'none',
        };
    }

    /**
     * The ERP module that originates the transaction — null for anything
     * the ERP does not originate (Tally-only rows; SalesOrder too, since the
     * ERP's sales_orders table is not a Tally flow) and for Unknown.
     */
    public function sourceModule(): ?string
    {
        return match ($this) {
            self::ProductionStockJournalShift, self::ProductionStockJournalBatch => 'production',
            self::SalesInvoice, self::DeliveryNote => 'sales',
            self::ReceiptNote, self::PurchaseOrder => 'procurement',
            self::Journal => 'finance',
            default => null,
        };
    }

    /**
     * The honesty flag: true only where the label the ERP shows and stores
     * on the entry ('Manufacturing Journal') is not the voucher type Tally
     * receives ('Stock Journal'). Every other category is labelled on the
     * entry exactly as it is emitted on the wire.
     */
    public function erpLabelDiffersFromWire(): bool
    {
        return $this === self::ProductionStockJournalBatch;
    }

    /**
     * One catalogue row — the same shape the resource exposes as `category`,
     * so a client reads a category the same way whether it came off an
     * entry or off the catalogue.
     *
     * @return array{key: string, label: string, wire_voucher_type: ?string, source: string, direction: string, source_module: ?string, erp_label_differs_from_wire: bool}
     */
    public function describe(): array
    {
        return [
            'key' => $this->value,
            'label' => $this->label(),
            'wire_voucher_type' => $this->wireVoucherType(),
            'source' => $this->source(),
            'direction' => $this->direction(),
            'source_module' => $this->sourceModule(),
            'erp_label_differs_from_wire' => $this->erpLabelDiffersFromWire(),
        ];
    }

    /**
     * Every category, once, in the stable order the cases are declared in
     * (erp → planned → tally → unknown). Counts are NOT here: they belong to
     * the query side, which is the only place that can measure them, and a
     * catalogue that carried a 0 for a Tally-only row would be lying.
     *
     * @return list<array{key: string, label: string, wire_voucher_type: ?string, source: string, direction: string, source_module: ?string, erp_label_differs_from_wire: bool}>
     */
    public static function catalogue(): array
    {
        return array_map(fn (self $category) => $category->describe(), self::cases());
    }
}
