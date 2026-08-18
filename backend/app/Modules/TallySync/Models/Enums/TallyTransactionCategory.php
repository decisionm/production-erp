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
 * TWO AXES, kept apart on purpose. source() says where the transaction
 * LIVES today — 'erp' (the ERP builds it and posts it), 'tally' (it exists
 * in the accountant's books and the ERP does not mirror it), 'absent' (no
 * such voucher type exists in the books at all), 'unknown'. erpBuild()
 * says what the ERP has BUILT for it — 'built', 'planned', 'none'. They
 * were one field once ("planned") and that overloaded them: a Purchase
 * Order is in the books 92 times AND its ERP-originated version is planned,
 * and one word cannot say both.
 *
 * Case order IS the catalogue order and is stable: ERP-built first, then
 * Tally-only in the census's order, then absent, then unknown. catalogue()
 * iterates cases().
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

    /**
     * enqueueJournalEntry(): JournalEntry → Tally 'Journal'. Journal is BOTH
     * in the 12-Aug census (418) AND ERP-built: the accountant keys Journals
     * in Tally and the ERP posts its own. Source stays 'erp' and the query
     * side counts ONLY the ERP-posted ones (tally_sync_entries rows) — the
     * 418 are the accountant's and are never counted here. There is
     * deliberately no second, Tally-only Journal case: one category, one
     * key, and the count says what it measures.
     */
    case Journal = 'journal';

    /**
     * enqueuePurchaseOrder(): PurchaseOrder → Tally 'Purchase Order' — an
     * ORDER voucher that touches neither accounts nor stock BY TYPE
     * (DEC-20260812-002). BUILT and STAGED in Phase 6, live posting
     * OWNER-GATED: tally-sync.purchase_orders_enabled is off by default and
     * the first live write is the owner's call (Q35), so on the live
     * instance this row's count is an honest 0 until then. Like Journal it
     * is BOTH ERP-built AND in the 12-Aug census (92 accountant-keyed
     * orders): source stays 'erp' and the query side counts ONLY the
     * ERP-staged rows — the 92 are the accountant's and are never counted
     * here. (Before Phase 6 this row read source 'tally' / erp_build
     * 'planned' and sat in the Tally-only block below; the plan is now the
     * build, and the case moved up here with the other enqueue paths — the
     * catalogue order is the case order.)
     */
    case PurchaseOrder = 'purchase_order';

    // ── Lives in Tally only (source 'tally', direction none) — the
    //    accountant's transactions the ERP does not mirror, from the 12-Aug
    //    Statistics census (TALLY-EVIDENCE-2026-08-12 §A, 1-Apr-26 to
    //    10-Aug-26): Purchase 351, Payment 925, Receipt 553, Contra 60,
    //    Credit Note 17, Debit Note 5. (Journal 418 and Purchase Order 92
    //    in the same census are the Tally-side counts of categories the ERP
    //    DOES build; see those cases.) Counts here are the query side's
    //    null, never these figures: the census is evidence of what exists,
    //    not a live measurement. ────────────────────────────────────────────

    case Purchase = 'purchase';

    case Payment = 'payment';
    case Receipt = 'receipt';
    case Contra = 'contra';
    case CreditNote = 'credit_note';
    case DebitNote = 'debit_note';

    // ── Absent (source 'absent', direction none) ─────────────────────────

    /**
     * NO Sales Order voucher type exists in the books at all — the 12-Aug
     * census lists none. DEC-20260809-003 places all real sales directly in
     * Tally (invoiced there; the ERP Sales module is demo-scale), so the
     * sales flow lives in Tally and the ERP's own sales_orders table is not
     * a Tally flow. Kept in the catalogue so the Control Center can say
     * exactly that rather than leave a gap — and NOT as source 'tally',
     * which would list it under "lives in Tally, not mirrored" beside
     * voucher types that are actually in the books. wireVoucherType() is
     * null: no agent builder emits one and no such voucher type is in the
     * books.
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
            self::Purchase => 'Purchase (lives in Tally)',
            self::PurchaseOrder => 'Procurement — Purchase Order (staged; live posting owner-gated, Q35 — flag off)',
            self::Payment => 'Payment (lives in Tally)',
            self::Receipt => 'Receipt (lives in Tally)',
            self::Contra => 'Contra (lives in Tally)',
            self::CreditNote => 'Credit Note (lives in Tally)',
            self::DebitNote => 'Debit Note (lives in Tally)',
            self::SalesOrder => 'Sales Order (no such voucher type in the books; sales are invoiced there — DEC-20260809-003)',
            self::Unknown => 'Unknown',
        };
    }

    /**
     * Whether the voucher's party (payload `party_ledger` / `party_gstin`)
     * is a SUPPLIER — the second half of FC-06 ("Purchase rates and supplier
     * details are Owner/Accounts only. Floor and sales logins never see what
     * a material cost or WHO SUPPLIED IT"). True for the Receipt Note the
     * ERP builds today (enqueueGoodsReceiptNote() writes the PO's vendor as
     * the party) and for the purchase categories a supplier is the party of
     * by definition (Purchase; the Purchase Order voucher —
     * enqueuePurchaseOrder() writes the vendor's Tally ledger as the party,
     * DEC-20260812-002). A CUSTOMER
     * on a Sales invoice or Delivery Note is not FC-06 and reads false, as
     * does anything with no party. Every surface that must withhold the
     * supplier from a non-finance reader (TallySyncEntryResource,
     * EntryPresenter, EntryMappingSurface) asks THIS, so the rule lives once.
     */
    public function partyIsSupplier(): bool
    {
        return match ($this) {
            self::ReceiptNote, self::Purchase, self::PurchaseOrder => true,
            default => false,
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
            self::Purchase => 'Purchase',
            self::PurchaseOrder => 'Purchase Order',
            self::Payment => 'Payment',
            self::Receipt => 'Receipt',
            self::Contra => 'Contra',
            self::CreditNote => 'Credit Note',
            self::DebitNote => 'Debit Note',
            self::SalesOrder, self::Unknown => null,
        };
    }

    /**
     * Where the transaction LIVES today. 'erp': the ERP builds and posts it
     * (Purchase Order: builds and STAGES it — posting is owner-gated, and
     * an ERP-built row with an honest zero is the truth, not a Tally-only
     * row). 'tally': it is in the accountant's books (the 12-Aug census)
     * and the ERP does not mirror it. 'absent': no such voucher type is in
     * the books at all. What the ERP has built is erpBuild(), not this.
     *
     * @return 'erp'|'tally'|'absent'|'unknown'
     */
    public function source(): string
    {
        return match ($this) {
            self::ProductionStockJournalShift,
            self::ProductionStockJournalBatch,
            self::SalesInvoice,
            self::DeliveryNote,
            self::ReceiptNote,
            self::Journal,
            self::PurchaseOrder => 'erp',
            self::Purchase,
            self::Payment,
            self::Receipt,
            self::Contra,
            self::CreditNote,
            self::DebitNote => 'tally',
            self::SalesOrder => 'absent',
            self::Unknown => 'unknown',
        };
    }

    /**
     * What the ERP has built for this category: 'built' for the seven
     * categories with an enqueue path in TallySyncService — the Purchase
     * Order voucher joined them in Phase 6 (enqueuePurchaseOrder(); staged,
     * flag off, DEC-20260812-002); 'planned' is kept as a value for the
     * next category that is decided but not yet built (none today); 'none'
     * for everything else. This is the axis that used to ride on source()
     * as "planned" — separated so "lives in Tally" and "the ERP builds it"
     * can both be true of one row without contradiction.
     *
     * @return 'built'|'planned'|'none'
     */
    public function erpBuild(): string
    {
        return match ($this) {
            self::ProductionStockJournalShift,
            self::ProductionStockJournalBatch,
            self::SalesInvoice,
            self::DeliveryNote,
            self::ReceiptNote,
            self::Journal,
            self::PurchaseOrder => 'built',
            default => 'none',
        };
    }

    /**
     * Which way the transaction moves. 'none' means the ERP never carries
     * it — nothing is mirrored either way, so no tally_sync_entries row can
     * ever be of this category (its count is null on the query side).
     * Purchase Order is 'erp_to_tally' since Phase 6: staged rows CAN
     * exist (the flag on), and the invariant below is what says so.
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
            'erp', 'unknown' => 'erp_to_tally',
            'tally', 'absent' => 'none',
        };
    }

    /**
     * The ERP module that originates the transaction, and null for anything
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
     * @return array{key: string, label: string, wire_voucher_type: ?string, source: string, erp_build: string, direction: string, source_module: ?string, erp_label_differs_from_wire: bool}
     */
    public function describe(): array
    {
        return [
            'key' => $this->value,
            'label' => $this->label(),
            'wire_voucher_type' => $this->wireVoucherType(),
            'source' => $this->source(),
            'erp_build' => $this->erpBuild(),
            'direction' => $this->direction(),
            'source_module' => $this->sourceModule(),
            'erp_label_differs_from_wire' => $this->erpLabelDiffersFromWire(),
        ];
    }

    /**
     * Every category, once, in the stable order the cases are declared in
     * (erp → tally → absent → unknown). Counts are NOT here: they belong to
     * the query side, which is the only place that can measure them, and a
     * catalogue that carried a 0 for a Tally-only row would be lying.
     *
     * @return list<array{key: string, label: string, wire_voucher_type: ?string, source: string, erp_build: string, direction: string, source_module: ?string, erp_label_differs_from_wire: bool}>
     */
    public static function catalogue(): array
    {
        return array_map(fn (self $category) => $category->describe(), self::cases());
    }
}
