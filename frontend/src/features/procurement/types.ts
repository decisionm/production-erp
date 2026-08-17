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
    is_active: boolean;
    created_at: string;
}

export type PurchaseRequisitionStatus = 'draft' | 'approved' | 'rejected';

export interface PurchaseRequisitionLine {
    id: number;
    item: Item;
    quantity: string;
    notes: string | null;
}

export interface PurchaseRequisition {
    id: number;
    status: PurchaseRequisitionStatus;
    requested_by: string | null;
    needed_by_date: string | null;
    notes: string | null;
    lines: PurchaseRequisitionLine[];
    created_at: string;
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
 *
 * null on the order means it was never sent through the staging path — a
 * draft, a Tally mirror (Tally's own order; the ERP never posts it), or a
 * PO sent before Phase 6 landed. The words for each state live in ONE
 * place: purchaseOrders.ts → tallyStateLine().
 */
export type TallyStagingState = 'disabled' | 'refused' | 'enqueued';

/**
 * The refusal codes the cloud names. `detail` is the server's own sentence
 * or identity (for item_unmapped: the item id + name); it is printed, never
 * parsed. Unknown codes fall through to their detail (or the code itself) —
 * a reason this build has not been taught is still better than a blank.
 */
export type TallyStagingReasonCode =
    | 'purchase_orders_disabled'
    | 'party_unmapped'
    | 'item_unmapped'
    | 'purchase_ledger_unmapped'
    | 'no_lines';

export interface TallyStagingReason {
    code: TallyStagingReasonCode | string;
    detail?: string | null;
}

export interface TallyStaging {
    state: TallyStagingState;
    reasons?: TallyStagingReason[] | null;
    entry_id?: number | null;
    /** ISO instant the state was recorded. */
    at?: string | null;
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

export interface GoodsReceiptNoteLine {
    id: number;
    purchase_order_line_id: number;
    item: Item;
    quantity: string;
    /** Same rule as PurchaseOrderLine.unit_price: absent (not null) without finance access. */
    unit_cost?: string;
    material_lots?: MaterialLot[];
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
