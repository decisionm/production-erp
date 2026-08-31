import type { ConfigurationAbilities } from '@/components/configuration/types';

import type { Item, Warehouse } from '@/features/inventory/types';
import type { MaterialLot } from '@/features/production/types';
import type { TallyLink } from '@/features/sales/types';

export interface Vendor {
    id: number;
    code: string;
    name: string;
    email: string | null;
    phone: string | null;
    address: string | null;
    gstin: string | null;
    state_code: string | null;
    /**
     * The vendor's ledger name in Tally — the party a staged Purchase Order
     * voucher would name (Phase 6). Typed by Accounts on the vendor form,
     * NEVER pulled from Tally (no Tally read); null until set, and a vendor
     * without one cannot be staged (refusal 'party_unmapped'). Absent on an
     * older backend.
     */
    tally_ledger_name?: string | null;
    /**
     * WHERE THIS VENDOR'S DETAILS CAME FROM, present only on one linked to a
     * Tally ledger — by the import command or by an Owner/Accounts confirm on
     * the Tally vendor review. `synced_at` is when the pull last CONFIRMED
     * that ledger, read from the mirror rather than from this row's own
     * timestamps, which move whenever anything about the vendor changes.
     *
     * Null on a vendor typed in by hand, and absent on an older backend.
     */
    tally_source?: { source: 'tally'; ledger_guid: string; synced_at: string | null } | null;
    /** Archived-by-soft-delete, distinct from is_active. */
    archived_at?: string | null;
    /**
     * The Configuration Lifecycle Contract's `can` block (DEC-20260817-002).
     * Null delete on index means UNDETERMINED — the confirm dialog asks
     * show(); it never means "no".
     */
    can?: ConfigurationAbilities | null;
    is_active: boolean;
    created_at: string;
}

export type PurchaseRequisitionStatus = 'draft' | 'approved' | 'rejected';

/**
 * How much of a requisition line has been ordered, in one word — the
 * server's own vocabulary (RequisitionCoverageService). Words for it live in
 * requisitionCoverage.ts.
 */
export type CoverageStatus = 'not_ordered' | 'partially_ordered' | 'fully_ordered';

export interface PurchaseRequisitionLine {
    id: number;
    item: Item;
    quantity: string;
    notes: string | null;
    /**
     * HOW MUCH OF THIS LINE HAS BEEN ORDERED — the server's arithmetic
     * (RequisitionCoverageService), grouped by ITEM across every purchase
     * order raised from the requisition, and the same sum the backend refuses
     * an over-order on.
     *
     * All four are OPTIONAL because the server OMITS them — never nulls them —
     * on a line it did not decorate, and an older backend sends none of them.
     * Absent means "not computed", which is not the same fact as zero; read
     * them through requisitionCoverage.ts, which words that difference.
     *
     * `requested_quantity` is `quantity` under the name it earns standing
     * beside the other three; `quantity` is unchanged and remains what every
     * existing reader asks for.
     */
    requested_quantity?: string;
    ordered_quantity?: string;
    /** Still to order. Never below zero. */
    balance_quantity?: string;
    order_status?: CoverageStatus;
}

export interface PurchaseRequisition {
    id: number;
    /** "PR-{id}" — the list's `q` grammar; absent on an older backend. */
    document_number?: string;
    status: PurchaseRequisitionStatus;
    requested_by: string | null;
    /** The decision trail — null on a requisition decided before the stamps existed. */
    approved_by?: string | null;
    approved_at?: string | null;
    rejected_by?: string | null;
    rejected_at?: string | null;
    needed_by_date: string | null;
    notes: string | null;
    lines: PurchaseRequisitionLine[];
    /**
     * The orders raised FROM this requisition — identity and status only, no
     * lines and no rate. `reserves_quantity` is the server's answer to
     * whether THIS order is one of those holding quantity against the
     * requisition; the status set behind it turns on two questions the owner
     * has not yet answered, so the screen is told the answer rather than the
     * rule.
     */
    purchase_orders?: { id: number; status: PurchaseOrderStatus; document_number?: string; reserves_quantity?: boolean }[];
    /**
     * The requisition in one word — the roll-up of its lines' statuses.
     * Deliberately a word and never a quantity: a requisition's lines may be
     * in Kgs and Nos at once, so it has no total. Absent when the lines were
     * not decorated.
     */
    order_status?: CoverageStatus;
    created_at: string;
}

/** The list's server-side filters (ListPurchaseRequisitionsRequest). */
export interface PurchaseRequisitionListFilters {
    status?: PurchaseRequisitionStatus | '';
    q?: string;
    page?: number;
    per_page?: number;
}

export type PurchaseOrderStatus = 'draft' | 'sent' | 'partially_received' | 'closed' | 'cancelled';

/** One item/due-date delivery window mirrored from the Tally order. */
export interface PurchaseOrderSchedule {
    id: number;
    due_date: string;
    quantity: string;
    quantity_received: string;
    remaining: string;
    tally_reference: string | null;
}

export interface PurchaseOrderLine {
    id: number;
    item: Item;
    quantity: string;
    /**
     * Owner/Accounts only (FC-06): the server OMITS the key — never nulls it —
     * for anyone without finance access, so its presence on a row is the
     * server's own answer about whether this user may see the rate.
     */
    unit_price?: string;
    quantity_received: string;
    schedules?: PurchaseOrderSchedule[];
}

// ------------------------------------------------------- Tally staging (P6-03) --

/**
 * WHERE A SENT PO STANDS WITH TALLY, as the server recorded it at send time
 * (`purchase_orders.tally_staging`, written ONLY by
 * PurchaseOrderService::recordTallyStaging — Phase 6 contract, addendum 6).
 *
 *   disabled   `tally-sync.purchase_orders_enabled` was OFF when the order
 *              was sent: NOTHING was queued. The flag defaults off and stays
 *              off until the owner clears the gate (Q35(d)/(e), Q39) — the
 *              first live PO write to real Tally is never unattended.
 *   refused    the flag was on but the cloud REFUSED to queue, with named
 *              reasons — an unmapped vendor ledger, an item with no Tally
 *              identity, no purchase ledger mapped, no lines. Refuse rather
 *              than guess a Tally name (DEC-20260812-002).
 *   enqueued   one tally_sync_entries row exists (`entry_id`); its status
 *              then rides on `tally` (the TallyLink) like every other voucher.
 *   dismissed  the order was cancelled or closed in the ERP while its staged
 *              voucher was still Pending and the agent had NOT collected it:
 *              the queue entry was dismissed (never sent) and `reasons` says
 *              which lifecycle action did it — 'cancelled_before_delivery' or
 *              'closed_before_delivery'. `entry_id` names the dismissed entry.
 *
 * `after` (optional, on an otherwise UNCHANGED state): the order was
 * cancelled or closed in the ERP AFTER the agent had collected the voucher
 * (delivered / synced / failed) — the entry is left standing because the
 * Tally side is the owner's to decide (an owner question, recorded by the
 * integrator). 'cancelled_after_delivery' | 'closed_after_delivery'.
 *
 * null on the order means it was never sent through the staging path — a
 * draft, a Tally mirror (Tally's own order; the ERP never posts it), or a
 * PO sent before Phase 6 landed. The words for each state live in ONE
 * place: purchaseOrders.ts → tallyStateLine().
 */
export type TallyStagingState = 'disabled' | 'refused' | 'enqueued' | 'dismissed';

/**
 * The refusal codes the cloud names. `detail` is the server's own sentence
 * or identity (for item_unmapped: the item id + name); it is printed, never
 * parsed. Unknown codes fall through to their detail (or the code itself) —
 * a reason this build has not been taught is still better than a blank.
 * 'godown_unresolved' — the lines resolve to no single Tally godown to
 * allocate to. The two *_before_delivery codes ride ONLY on state
 * 'dismissed' (see TallyStagingState).
 */
export type TallyStagingReasonCode =
    | 'purchase_orders_disabled'
    | 'party_unmapped'
    | 'item_unmapped'
    | 'purchase_ledger_unmapped'
    | 'godown_unresolved'
    | 'no_lines'
    | 'cancelled_before_delivery'
    | 'closed_before_delivery';

export interface TallyStagingReason {
    code: TallyStagingReasonCode | string;
    detail?: string | null;
}

/** See TallyStagingState's `after` — the ERP acted after Tally had received the voucher; the entry stands. */
export type TallyStagingAfter = 'cancelled_after_delivery' | 'closed_after_delivery';

export interface TallyStaging {
    state: TallyStagingState;
    reasons?: TallyStagingReason[] | null;
    entry_id?: number | null;
    /** ISO instant the state was recorded. */
    at?: string | null;
    /** Additive (Phase 6 fix): present only when the lifecycle acted after delivery — the state above is unchanged. */
    after?: TallyStagingAfter | string | null;
}

// -------------------------------------------------------- lifecycle (P6-01) --

/**
 * WHICH LIFECYCLE ACTIONS THE SERVER ALLOWS RIGHT NOW — computed in
 * PurchaseOrderService by the same rules the POST actions enforce (amend only
 * in Draft; close from Sent/PartiallyReceived; cancel from Draft/Sent with
 * zero receipts; nothing on a Tally mirror). The buttons read these and
 * never re-derive the state machine client-side.
 */
export interface PurchaseOrderCan {
    amend: boolean;
    close: boolean;
    cancel: boolean;
    send: boolean;
}

/**
 * One row of `purchase_order_revisions` — what the lines WERE before an
 * amendment (kind 'amend') or what remained per line when the order was
 * closed (kind 'close'). Read-only history; the ERP appends, never edits.
 * `lines` is the server's JSON verbatim, so its rows are typed loosely and
 * printed defensively.
 */
export interface PurchaseOrderRevision {
    id: number;
    revision_no: number;
    kind?: 'amend' | 'close' | string;
    reason: string | null;
    amended_by?: { id: number; name: string } | string | number | null;
    /** The snapshot rows; a backend that prints the column name verbatim sends `lines_json` — revisionLines() reads either. */
    lines?: PurchaseOrderRevisionLine[];
    lines_json?: PurchaseOrderRevisionLine[];
    created_at: string | null;
}

export interface PurchaseOrderRevisionLine {
    id?: number;
    purchase_order_line_id?: number;
    item_id?: number;
    item?: { id: number; name: string; sku?: string | null } | null;
    quantity?: string | number;
    quantity_received?: string | number;
    remaining?: string | number;
    /** Same FC-06 rule as PurchaseOrderLine.unit_price: absent for a reader without finance access. */
    unit_price?: string | number | null;
    schedules?: { due_date: string; quantity: string | number }[];
}

/**
 * The receipts summary the show endpoint carries beside the order — one row
 * per arrival: identity, keys, date, store, quantity, line count and the
 * Receipt Note link (PurchaseOrderResource::receiptSummary). No line
 * detail, no lot, no rate — that is GET goods-receipts/{grn} and the trace.
 */
export interface PurchaseOrderReceiptSummary {
    id: number;
    /** "GRN-{id}". */
    document_number?: string;
    receipt_key?: string | null;
    reference?: string | null;
    receipt_note_reference?: string | null;
    tracking_number?: string | null;
    received_date: string | null;
    warehouse_id?: number | null;
    warehouse?: { id: number; name: string; code?: string | null } | null;
    lines_count?: number | null;
    /** The receipt's lines when the show endpoint carries them (quantities only — a rate rides FC-06's gate). */
    lines?: { id: number; item?: { id: number; name: string; sku?: string | null } | null; quantity: string }[];
    quantity?: string;
    tally?: TallyLink | null;
}

export interface PurchaseOrder {
    id: number;
    status: PurchaseOrderStatus;
    /** 'tally' = read-only mirror of the order living in Tally. */
    source: 'erp' | 'tally';
    tally_order_no: string | null;
    vendor: Vendor;
    purchase_requisition_id: number | null;
    order_date: string;
    expected_date: string | null;
    notes: string | null;
    lines: PurchaseOrderLine[];
    created_at: string;

    // ---- Phase 6 (WS-A's PurchaseOrderResource) — every key optional so a
    // list served by an older backend still renders; each reader below says
    // what an ABSENT key means for it. ----
    /** The Purchase Order voucher's queue entry (TallySyncLinkService::for), or null when none exists. */
    tally?: TallyLink | null;
    /** See TallyStaging — null/absent = never went through the staging path. */
    tally_staging?: TallyStaging | null;
    receipts_count?: number;
    revisions_count?: number;
    closed_reason?: string | null;
    /** Who did it — the server may send the user stub, a name, or a bare user id. */
    closed_by?: { id: number; name: string } | string | number | null;
    closed_at?: string | null;
    cancelled_reason?: string | null;
    cancelled_by?: { id: number; name: string } | string | number | null;
    cancelled_at?: string | null;
    /** Absent = the server has not said; the page then offers no lifecycle button (see canLabels). */
    can?: PurchaseOrderCan;
    /** ONLY on GET /procurement/purchase-orders/{id}. */
    revisions?: PurchaseOrderRevision[];
    /** ONLY on GET /procurement/purchase-orders/{id}. */
    receipts?: PurchaseOrderReceiptSummary[];
}

/**
 * The server-side filters GET /procurement/purchase-orders accepts
 * (ListPurchaseOrdersRequest over ListProcurementDocumentsRequest): vendor,
 * item, `from`/`to` on order_date (calendar days, inclusive), `q` (PO id in
 * any spelling — "PO-12", "po 12", "12" — a Tally order no., vendor name or
 * code), sort, paging, and `status` — one status or several (Phase 6
 * extends the single enum to accept an array). Every field optional; empties
 * never reach the URL.
 */
export interface PurchaseOrderListFilters {
    status?: PurchaseOrderStatus[];
    vendor_id?: number;
    item_id?: number;
    from?: string;
    to?: string;
    q?: string;
    sort?: string;
    page?: number;
    per_page?: number;
}

/** One arrival line's standing with Incoming QC — quantities only, never rates. */
export interface GoodsReceiptLineQc {
    /** The line's disposition when one exists (the quality service refuses a second, so 0..1). */
    inspection: {
        id: number;
        result: 'pass' | 'fail' | 'partial' | string;
        inspected_quantity: string;
        accepted_quantity: string;
        rejected_quantity: string;
        inspection_date: string | null;
    } | null;
    /** The physical hold, counted from the line's lots; null when the line has no bag-tracked lots. */
    bags: { waiting_qc: number; rejected_qc: number; total: number } | null;
}

export interface GoodsReceiptNoteLine {
    id: number;
    purchase_order_line_id: number;
    item: Item;
    quantity: string;
    /** Same rule as PurchaseOrderLine.unit_price: absent (not null) without finance access. */
    unit_cost?: string;
    material_lots?: MaterialLot[];
    /** Absent on an older backend. */
    qc?: GoodsReceiptLineQc;
}

export interface GoodsReceiptNote {
    id: number;
    /** "GRN-{id}" (Phase 6) — absent on an older backend. */
    document_number?: string;
    receipt_key?: string;
    purchase_order_id: number;
    warehouse: Warehouse;
    reference: string | null;
    /** Recorded at physical arrival; defaults deterministically when blank. */
    receipt_note_reference?: string | null;
    tracking_number?: string | null;
    received_date: string;
    notes: string | null;
    lines: GoodsReceiptNoteLine[];
    material_lots?: MaterialLot[];
    /** The Receipt Note's queue entry (status + flags + link only), null when none exists; absent on an older backend. */
    tally?: TallyLink | null;
    /**
     * What staging concluded at arrival (goods_receipt_notes.tally_staging,
     * written ONLY by GoodsReceiptService::recordTallyStaging — the PO
     * record's shape without 'dismissed'). NULL on receipts that predate the
     * column; absent on an older backend.
     */
    tally_staging?: TallyStaging | null;
    created_at: string;
}

// ------------------------------------------------------------ trace (P6-02) --

/**
 * GET /procurement/purchase-orders/{id}/trace — THE CHAIN BELOW ONE ORDER, in
 * the order it runs (PurchaseOrderTraceService): PO (lines, schedules) →
 * goods receipts (receipt_key, lines, quantities, Tally link) → each
 * receipt line's stock movement(s) (purpose Receipt) and material lots →
 * bags → where each bag was LOADED (machine or the common input, under
 * which batch segment) → the batch segments those loads fed, with the
 * day-bin consumption figure and the Consumption issues the batch wrote.
 * Read through the Inventory / Production / TallySync SERVICES on the
 * server; nothing here reaches a model. Shape mirrors the Sales trace.
 *
 * FC-06 ON THIS SHAPE: a purchase rate or amount is present ONLY for a reader
 * ProcurementRateVisibilityTest lets see it — for everyone else the server
 * OMITS the key and puts a `rate_withheld` note where the number would be.
 * The drawer prints "withheld" in that cell, never a blank — see rateCell().
 *
 * Every collection is optional because WS-A's endpoint and this drawer land
 * in the same phase and an older backend may serve a narrower shape: an
 * ABSENT collection is said as "could not be read", an EMPTY one as "none"
 * — two different sentences. Where the server may nest a collection (lots
 * and movements under each receipt line) or lay it flat, flattenTrace()
 * reads both.
 */
export interface TraceItemRef {
    id: number;
    name: string;
    sku?: string | null;
    uom?: string | null;
}

export interface TraceRateWithheld {
    /** The server's withheld note when it chose to say so rather than omit the key. */
    rate_withheld?: string | boolean | null;
    unit_cost_withheld?: string | boolean | null;
    unit_price_withheld?: string | boolean | null;
}

/** One of the order's own lines as the trace prints it — with what remains and its delivery windows. */
export interface TraceOrderLine extends TraceRateWithheld {
    id: number;
    item: TraceItemRef | null;
    quantity: string;
    quantity_received?: string;
    remaining?: string;
    unit_price?: string | null;
    schedules?: {
        id: number;
        due_date: string | null;
        quantity: string;
        quantity_received?: string;
        remaining?: string;
        tally_reference?: string | null;
    }[];
}

export interface TraceReceiptLine extends TraceRateWithheld {
    id: number;
    purchase_order_line_id?: number | null;
    item: TraceItemRef | null;
    quantity: string;
    unit_cost?: string | null;
    /** The ledger line(s) this receipt line wrote (purpose Receipt), when the server nests them here. */
    stock_movements?: TraceMovement[];
    material_lots?: TraceLot[];
    lots?: TraceLot[];
}

export interface TraceReceipt {
    id: number;
    document_number?: string;
    receipt_key?: string | null;
    receipt_note_reference?: string | null;
    reference?: string | null;
    tracking_number?: string | null;
    received_date: string | null;
    warehouse: { id: number; name: string; code?: string | null } | null;
    lines: TraceReceiptLine[];
    material_lots?: TraceLot[];
    lots?: TraceLot[];
    stock_movements?: TraceMovement[];
    movements?: TraceMovement[];
    tally?: TallyLink | null;
}

/**
 * Where ONE bag was poured — machine, or null = the common resin input —
 * and under which batch segment it was recorded (null = outside any batch
 * window). FC-01: neither is ownership; a bag belongs to no machine and no
 * batch. This is the day-bin ledger's LOAD row.
 */
export interface TraceLoad {
    id: number;
    work_center?: { id: number; code?: string | null; name: string } | null;
    shift_production_entry_id: number | null;
    batch_number?: string | null;
    quantity_kg: string;
    recorded_at?: string | null;
}

export interface TraceBag {
    id: number;
    barcode?: string | null;
    status?: string | null;
    original_kg?: string | null;
    remaining_kg?: string | null;
    loads?: TraceLoad[];
}

export interface TraceLot extends TraceRateWithheld {
    id: number;
    lot_no?: string | null;
    supplier_lot_no?: string | null;
    item?: TraceItemRef | null;
    goods_receipt_note_id?: number | null;
    grn_id?: number | null;
    goods_receipt_note_line_id?: number | null;
    bag_count?: number | null;
    bag_weight_kg?: string | null;
    total_received_kg?: string | null;
    quantity?: string | null;
    received_date?: string | null;
    /** Finance-only (FC-06) — absent for everyone else. */
    receipt_rate_per_kg?: string | null;
    current_rate_per_kg?: string | null;
    bags?: TraceBag[];
}

export interface TraceMovement extends TraceRateWithheld {
    id: number;
    /** 'in' | 'out' — the ledger's direction (StockMovementType); printed, never switched on. */
    type?: string | null;
    purpose: string;
    /** 'in' | 'out' or the server's own word; printed, never switched on. */
    direction?: string | null;
    item?: TraceItemRef | null;
    warehouse?: { id: number; name: string; code?: string | null } | null;
    warehouse_id?: number | null;
    quantity: string;
    unit_cost?: string | null;
    movement_date?: string | null;
    occurred_at?: string | null;
    moved_at?: string | null;
    reference?: string | null;
    goods_receipt_note_id?: number | null;
    shift_production_entry_id?: number | null;
}

/**
 * The day-bin ledger's figure for one (batch segment, item) — the design
 * formula opening + loaded − closing − returned; consumed_kg is null until a
 * closing count exists (not computable is not zero).
 */
export interface TraceDayBin {
    opening_kg?: string | null;
    loaded_kg?: string | null;
    returned_kg?: string | null;
    closing_kg?: string | null;
    consumed_kg?: string | null;
}

/**
 * One (batch segment, item) row the order's material was loaded under
 * (PurchaseOrderTraceService::consumptionRows): the segment, how many kg of
 * THIS order's bags were loaded under it, the day-bin figure for that
 * segment and item, and the Consumption issues the batch's completion
 * wrote. The older flat keys (shift_production_entry_id, quantity, …) are
 * read too so a narrower backend still renders a row.
 */
export interface TraceConsumption {
    shift_production_entry?: {
        id: number;
        batch_number?: string | null;
        batch_status?: string | null;
        production_date?: string | null;
        work_center?: { id: number; code?: string | null; name: string } | null;
    } | null;
    item?: TraceItemRef | null;
    loaded_kg_from_this_order?: string | null;
    day_bin?: TraceDayBin | null;
    stock_issues?: TraceMovement[];
    // ---- flat spellings a narrower backend may use ----
    shift_production_entry_id?: number | null;
    batch_number?: string | null;
    batch_no?: string | null;
    quantity?: string | null;
    production_date?: string | null;
    consumed_at?: string | null;
    machine?: { id: number; name: string } | string | null;
    lot_ids?: number[];
    lots?: TraceLot[];
}

export interface PurchaseOrderTrace {
    purchase_order?: {
        id: number;
        document_number?: string;
        status?: PurchaseOrderStatus;
        source?: 'erp' | 'tally';
        tally_order_no?: string | null;
        order_date?: string | null;
        expected_date?: string | null;
        vendor?: { id: number; code?: string | null; name: string } | null;
        tally?: TallyLink | null;
        tally_staging?: TallyStaging | null;
    };
    /** The order's own Tally link when the trace carries it at the top. */
    tally?: TallyLink | null;
    lines?: TraceOrderLine[];
    receipts?: TraceReceipt[];
    goods_receipts?: TraceReceipt[];
    material_lots?: TraceLot[];
    lots?: TraceLot[];
    stock_movements?: TraceMovement[];
    movements?: TraceMovement[];
    consumption?: TraceConsumption[];
    consumptions?: TraceConsumption[];
    production_entries?: TraceConsumption[];
}

// ---------------------------------------------------------- supplier bills --

export type SupplierBillStatus = 'draft' | 'recorded' | 'cancelled';

export interface SupplierBillLine {
    id: number;
    goods_receipt_note_line_id: number | null;
    item: Item;
    quantity: string;
    rate: string;
    amount: string;
    /** The matched arrival's quantity, for the billed-vs-received variance. */
    received_quantity?: string | null;
}

/**
 * The vendor's invoice recorded in the ERP. Every figure is a purchase
 * rate, so the whole surface is finance-gated (FC-06) — these types are
 * only ever populated for Owner/Accounts logins. No Tally fields exist:
 * Purchase Invoice posting is withheld while Q39/Q41/Q28 are open.
 */
export interface SupplierBill {
    id: number;
    /** "BILL-{id}" — the ERP's own reference; the vendor's is bill_number. */
    document_number: string;
    status: SupplierBillStatus;
    vendor: { id: number; code: string; name: string };
    purchase_order_id: number | null;
    bill_number: string;
    bill_date: string;
    purchase_ledger_name: string | null;
    subtotal: string;
    cgst: string;
    sgst: string;
    igst: string;
    rounding: string;
    total: string;
    attachment_name: string | null;
    has_attachment: boolean;
    notes: string | null;
    cancelled_reason: string | null;
    created_by?: string | null;
    recorded_by?: string | null;
    recorded_at?: string | null;
    lines: SupplierBillLine[];
    created_at: string;
}

export interface SupplierBillListFilters {
    status?: SupplierBillStatus | '';
    q?: string;
    vendor_id?: number;
    page?: number;
    per_page?: number;
}

/*
 * ── TALLY-ASSISTED PROCUREMENT (read-only from Tally) ────────────────────
 *
 * Two surfaces, both Owner/Accounts (FC-06): the vendor review, where a
 * person confirms what Tally says about a party before it reaches the vendor
 * master, and the vendor/item rate lookup on the purchase-order form.
 *
 * Nothing on either surface posts to Tally. The existing approved workflows
 * still handle voucher posting.
 */

/** How a Tally ledger was matched to an ERP vendor, or why it was not. */
export type TallyVendorMatchBasis = 'none' | 'ledger_guid' | 'gstin' | 'gstin_ambiguous';

/**
 * `new` — no ERP vendor for this party yet.
 * `conflict` — matched, and Tally now says something different.
 * `ambiguous` — the GSTIN could mean more than one party, so nothing is
 *   offered to apply. Measured: 23 GSTINs sit on more than one ledger in the
 *   live books, two Sundry Creditors among them sharing one.
 */
export type TallyVendorReviewKind = 'new' | 'conflict' | 'ambiguous';

export interface TallyVendorDifference {
    field: string;
    current: string | null;
    proposed: string;
}

export interface TallyVendorReviewRow {
    tally_ledger_guid: string;
    ledger_name: string;
    ledger_group: string | null;
    kind: TallyVendorReviewKind;
    match_basis: TallyVendorMatchBasis;
    proposed: Record<string, string | null>;
    differences: TallyVendorDifference[];
    vendor?: { vendor_id: number; code: string; name: string };
    ambiguous_with?: { vendor_id: number; code: string; name: string }[];
    name_clash?: { vendor_id: number; code: string; name: string } | null;
    links_identity?: boolean;
    source: 'tally';
    tally_synced_at: string | null;
}

export interface TallyVendorReviewQueue {
    groups: string[];
    group_census: Record<string, number>;
    rows: TallyVendorReviewRow[];
    last_synced_at: string | null;
}

/** One quoted voucher line — an agreed rate or a billed one. */
export interface TallyPurchaseRateQuote {
    voucher_type: 'purchase_order' | 'purchase_invoice';
    voucher_number: string | null;
    voucher_reference: string | null;
    voucher_date: string | null;
    party_ledger_name: string;
    party_gstin: string | null;
    stock_item_name: string;
    rate_value: string;
    rate_unit: string | null;
    quantity: string | null;
    quantity_unit: string | null;
    amount: string | null;
    gst: {
        cgst_rate: string | null;
        sgst_rate: string | null;
        igst_rate: string | null;
        cess_rate: string | null;
        hsn_code: string | null;
        purchase_ledger_name: string | null;
    };
    item_uom: string | null;
    unit_matches: boolean;
    /**
     * THE ONLY FIELD THE FORM MAY ACT ON BY ITSELF. Everything else is
     * information a person reads; this says whether the rate's basis was
     * confirmed to match the item's own unit. False means SHOW, never fill.
     */
    may_prefill: boolean;
    prefill_blocked_reason: string | null;
    source: 'tally';
    tally_synced_at: string | null;
}

export interface TallyVendorItemRate {
    vendor: { id: number; name: string; tally_ledger_name: string | null } | null;
    item: { id: number; name: string; uom: string | null } | null;
    purchase_order: TallyPurchaseRateQuote | null;
    purchase_invoice: TallyPurchaseRateQuote | null;
    /** The latest of the two by voucher date — the suggestion, never an instruction. */
    suggestion: TallyPurchaseRateQuote | null;
    unavailable_reason: string | null;
    last_synced_at: string | null;
}
