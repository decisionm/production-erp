import { api } from '@/lib/api';
import { type ListParamsSpec, compactParams } from '@/lib/listParams';
import type { Paginated } from '@/lib/types';
import { buildPurchaseOrderQuery, unwrapTraceResponse } from './purchaseOrders';
import type {
    GoodsReceiptListFilters,
    GoodsReceiptListParams,
    GoodsReceiptNote,
    PurchaseOrder,
    PurchaseOrderListFilters,
    PurchaseOrderTrace,
    PurchaseRequisition,
    PurchaseRequisitionListFilters,
    SupplierBill,
    SupplierBillListFilters,
    TallyVendorItemRate,
    TallyVendorReviewQueue,
    Vendor,
} from './types';

/**
 * ONE page of the vendor master, for a screen that renders its own pager.
 *
 * Paged on the SERVER (VendorService::paginate + Controller::perPage), not
 * sliced in the browser: the Vendors table used to call this with no
 * arguments and draw the answer with `pagination={false}`, so it showed the
 * server's default first 20 and said nothing about the rest — the 21st
 * vendor was simply not on the screen. Same defect the pickers had, same
 * cure as the Customers table: ask for the page you are showing.
 *
 * The defaults keep every earlier no-argument caller working; a picker must
 * still use listAllVendors(), which is what pickerFullList.test.ts pins.
 */
/**
 * The vendor list, optionally narrowed by `q` (name or code).
 *
 * Searching is SERVER-SIDE. Filtering the loaded page in the browser would
 * search 50 rows out of 628 and answer "no such vendor" for one that plainly
 * exists — the defect four pickers in this app were fixed for.
 */
export async function listVendors(page = 1, perPage = 50, search?: string): Promise<Paginated<Vendor>> {
    const term = search?.trim() ?? '';
    const { data } = await api.get<Paginated<Vendor>>('/procurement/vendors', {
        params: { page, per_page: perPage, ...(term !== '' ? { q: term } : {}) },
    });
    return data;
}

/**
 * Full reference list for a picker (all rows, not the default first page).
 *
 * A picker fed from the paged list silently offers only the newest 20 — the
 * vendor a buyer needs is simply absent, with nothing on screen saying so.
 * That is how the resin PO could not be raised on 12-Aug: the ITEM picker had
 * the same defect, and every picker in the app shared it.
 */
export async function listAllVendors(): Promise<Paginated<Vendor>> {
    const { data } = await api.get<Paginated<Vendor>>('/procurement/vendors', { params: { per_page: 1000 } });
    return data;
}

export interface CreateVendorPayload {
    /** Omitted by the form: the server mints "V-0001" and steps the sequence on. */
    code?: string;
    name: string;
    email?: string;
    phone?: string;
    address?: string;
    gstin?: string;
    state_code?: string;
    /** The vendor's Tally ledger name (Phase 6) — typed, never pulled; empty string clears it. */
    tally_ledger_name?: string;
}

export async function createVendor(payload: CreateVendorPayload): Promise<Vendor> {
    const { data } = await api.post<{ data: Vendor }>('/procurement/vendors', payload);
    return data.data;
}

export type UpdateVendorPayload = Partial<CreateVendorPayload> & { is_active?: boolean };

export async function updateVendor(id: number, payload: UpdateVendorPayload): Promise<Vendor> {
    const { data } = await api.put<{ data: Vendor }>(`/procurement/vendors/${id}`, payload);
    return data.data;
}

/**
 * The requisition register, SERVER-PAGED. It used to take the endpoint's
 * default page and render it with the pager switched off, so the queue showed
 * the newest 20 and gave no sign the other rows existed.
 */
export async function listPurchaseRequisitions(
    filters: PurchaseRequisitionListFilters = {},
): Promise<Paginated<PurchaseRequisition>> {
    // '' means "no filter" to the select; the server should not see the key.
    const { status, q, ...rest } = filters;
    const { data } = await api.get<Paginated<PurchaseRequisition>>('/procurement/purchase-requisitions', {
        params: {
            per_page: 50,
            ...rest,
            ...(status ? { status } : {}),
            ...(q && q.trim() !== '' ? { q: q.trim() } : {}),
        },
    });
    return data;
}

export interface CreatePurchaseRequisitionPayload {
    needed_by_date?: string;
    notes?: string;
    lines: { item_id: number; quantity: number; notes?: string }[];
}

export async function createPurchaseRequisition(
    payload: CreatePurchaseRequisitionPayload,
): Promise<PurchaseRequisition> {
    const { data } = await api.post<{ data: PurchaseRequisition }>('/procurement/purchase-requisitions', payload);
    return data.data;
}

export async function approvePurchaseRequisition(id: number): Promise<PurchaseRequisition> {
    const { data } = await api.post<{ data: PurchaseRequisition }>(
        `/procurement/purchase-requisitions/${id}/approve`,
    );
    return data.data;
}

export async function rejectPurchaseRequisition(id: number): Promise<PurchaseRequisition> {
    const { data } = await api.post<{ data: PurchaseRequisition }>(
        `/procurement/purchase-requisitions/${id}/reject`,
    );
    return data.data;
}

/**
 * The list, narrowed SERVER-SIDE by ListPurchaseOrdersRequest's filters
 * (status — one or several —, vendor, item, order-date range, `q`, sort,
 * paging; see PurchaseOrderListFilters). buildPurchaseOrderQuery() drops
 * empties and anything the server would 422. No argument = the unfiltered
 * first page every earlier caller still gets (the GRN page's order picker
 * calls it that way).
 */
export async function listPurchaseOrders(filters?: PurchaseOrderListFilters): Promise<Paginated<PurchaseOrder>> {
    const { data } = await api.get<Paginated<PurchaseOrder>>('/procurement/purchase-orders', {
        params: buildPurchaseOrderQuery(filters),
    });
    return data;
}

/**
 * GET /procurement/purchase-orders/{id} — the order with lines, schedules,
 * revisions, receipts summary, its Tally link/staging and `can` (Phase 6,
 * P6-02). What the detail drawer reads; the list row is only its placeholder.
 */
export async function getPurchaseOrder(id: number): Promise<PurchaseOrder> {
    const { data } = await api.get<{ data: PurchaseOrder }>(`/procurement/purchase-orders/${id}`);
    return data.data;
}

/**
 * GET /procurement/purchase-orders/{id}/trace — the chain below the order:
 * receipts → lots → stock movements → consumption. Answered bare or wrapped
 * in `data`; both are read. Rates on it are the server's call (FC-06).
 */
export async function getPurchaseOrderTrace(id: number): Promise<PurchaseOrderTrace> {
    const { data } = await api.get<PurchaseOrderTrace | { data: PurchaseOrderTrace }>(`/procurement/purchase-orders/${id}/trace`);
    return unwrapTraceResponse(data);
}

export interface CreatePurchaseOrderPayload {
    vendor_id: number;
    purchase_requisition_id?: number;
    order_date: string;
    expected_date?: string;
    notes?: string;
    /** 'tally' records a read-only mirror of the order living in Tally. */
    source?: 'erp' | 'tally';
    tally_order_no?: string;
    lines: {
        item_id: number;
        quantity: number;
        unit_price: number;
        schedules?: { due_date: string; quantity: number; tally_reference?: string }[];
    }[];
}

export async function createPurchaseOrder(payload: CreatePurchaseOrderPayload): Promise<PurchaseOrder> {
    const { data } = await api.post<{ data: PurchaseOrder }>('/procurement/purchase-orders', payload);
    return data.data;
}

export async function sendPurchaseOrder(id: number): Promise<PurchaseOrder> {
    const { data } = await api.post<{ data: PurchaseOrder }>(`/procurement/purchase-orders/${id}/send`);
    return data.data;
}

// ---- lifecycle (Phase 6, P6-01) — append-only POST actions, never PUT/DELETE.
// Each transition adds a row (a revision) or moves the status; the server
// refuses what its state machine forbids with a 422 whose message the
// screen prints verbatim. None of these touches stock or posts to Tally.

/**
 * POST purchase-orders/{id}/amend — Draft ONLY: replaces the lines (and
 * their schedules) and records the prior lines as a revision with `reason`.
 * The same line shape as create. Amending after Send is refused by the
 * server (that would be a new category of Tally write — an owner question).
 */
export interface AmendPurchaseOrderPayload {
    reason?: string;
    lines: CreatePurchaseOrderPayload['lines'];
}

export async function amendPurchaseOrder(id: number, payload: AmendPurchaseOrderPayload): Promise<PurchaseOrder> {
    const { data } = await api.post<{ data: PurchaseOrder }>(`/procurement/purchase-orders/${id}/amend`, payload);
    return data.data;
}

/** POST purchase-orders/{id}/close — Sent | PartiallyReceived → Closed, with a required reason (short-close). */
export async function closePurchaseOrder(id: number, reason: string): Promise<PurchaseOrder> {
    const { data } = await api.post<{ data: PurchaseOrder }>(`/procurement/purchase-orders/${id}/close`, { reason });
    return data.data;
}

/** POST purchase-orders/{id}/cancel — Draft | Sent with ZERO receipts → Cancelled, with a required reason. */
export async function cancelPurchaseOrder(id: number, reason: string): Promise<PurchaseOrder> {
    const { data } = await api.post<{ data: PurchaseOrder }>(`/procurement/purchase-orders/${id}/cancel`, { reason });
    return data.data;
}

/**
 * THE REGISTER PAGE'S URL STATE (useListParams): `q`, `page`, `per_page`
 * are every list's; `po` and `grn` are this page's own deep links. Module-
 * level, as the hook requires. The key builder and the URL→wire mapping sit
 * beside the read they feed, so the render test can seed the exact key the
 * page derives from its URL.
 */
export const GOODS_RECEIPT_LIST_SPEC: ListParamsSpec = { numbers: ['po', 'grn'] };

/** The page's URL → the request the server gets. Compacted: `{}` and `{ q: '' }` are one key. */
export function goodsReceiptServerFilters(params: GoodsReceiptListParams): GoodsReceiptListFilters {
    const { po, grn, ...rest } = params;

    return compactParams({ ...rest, purchase_order_id: po, id: grn });
}

/** Under the ['procurement', 'goods-receipts'] prefix every receipt-side mutation already invalidates. */
export function goodsReceiptsQueryKey(filters: GoodsReceiptListFilters) {
    return ['procurement', 'goods-receipts', 'list', filters] as const;
}

/**
 * ONE page of the register, narrowed and paged on the SERVER
 * (GoodsReceiptService through ListGoodsReceiptsRequest). No argument is
 * the unfiltered first page every earlier caller still gets; a picker that
 * needs the whole register asks for `per_page` at the server's ceiling.
 */
export async function listGoodsReceipts(
    params: GoodsReceiptListFilters = {},
): Promise<Paginated<GoodsReceiptNote> & { traceability_enabled?: boolean }> {
    const { data } = await api.get<Paginated<GoodsReceiptNote> & { traceability_enabled?: boolean }>(
        '/procurement/goods-receipts',
        { params: compactParams(params) },
    );
    return data;
}

/** GET /procurement/goods-receipts/{id} — one receipt with its lines, lots and Tally link (Phase 6). */
export async function getGoodsReceipt(id: number): Promise<GoodsReceiptNote> {
    const { data } = await api.get<{ data: GoodsReceiptNote }>(`/procurement/goods-receipts/${id}`);
    return data.data;
}

/**
 * One idempotent, atomic GRN payload. When a mass-material line carries
 * lots, Procurement records the aggregate receipt and Inventory fans out
 * its physical bags in the same transaction.
 */
export interface CreateGoodsReceiptPayload {
    /** Stable across retries: replay returns the original receipt without moving stock twice. */
    receipt_key: string;
    purchase_order_id: number;
    warehouse_id: number;
    reference?: string;
    /**
     * The real date AND time the material was received, as plain
     * `YYYY-MM-DD HH:mm` wall-clock text. Without it the backend stamps the
     * receipt (and its stock movement) with the moment the form was submitted.
     */
    received_date: string;
    notes?: string;
    /** Recorded at physical arrival; server defaults both when blank. */
    receipt_note_reference?: string;
    tracking_number?: string;
    lines: {
        purchase_order_line_id: number;
        quantity: number;
        unit_cost?: number;
        /** Edited oldest-due preview; omitted = server allocates oldest-due itself. */
        schedule_allocations?: { purchase_order_schedule_id: number; quantity: number }[];
        lots?: {
            supplier_lot_no?: string;
            bag_count: number;
            bag_weight_kg?: number;
            total_received_kg: number;
            barcodes?: string[];
            bag_weights?: number[];
            notes?: string;
        }[];
    }[];
}

export async function createGoodsReceipt(payload: CreateGoodsReceiptPayload): Promise<GoodsReceiptNote> {
    const { data } = await api.post<{ data: GoodsReceiptNote }>('/procurement/goods-receipts', payload);
    return data.data;
}

// ---------------------------------------------------------- supplier bills --
// FC-06: finance-gated server-side; these calls 403 for anyone else.

export interface SupplierBillLinePayload {
    goods_receipt_note_line_id?: number | null;
    item_id: number;
    quantity: number;
    rate: number;
    amount: number;
}

export interface SupplierBillPayload {
    vendor_id: number;
    purchase_order_id?: number | null;
    bill_number: string;
    bill_date: string;
    purchase_ledger_name?: string | null;
    subtotal: number;
    cgst?: number;
    sgst?: number;
    igst?: number;
    rounding?: number;
    total: number;
    notes?: string;
    lines: SupplierBillLinePayload[];
}

export async function listSupplierBills(filters: SupplierBillListFilters = {}): Promise<Paginated<SupplierBill>> {
    const { status, q, ...rest } = filters;
    const { data } = await api.get<Paginated<SupplierBill>>('/procurement/supplier-bills', {
        params: {
            per_page: 50,
            ...rest,
            ...(status ? { status } : {}),
            ...(q && q.trim() !== '' ? { q: q.trim() } : {}),
        },
    });
    return data;
}

export async function createSupplierBill(payload: SupplierBillPayload): Promise<SupplierBill> {
    const { data } = await api.post<{ data: SupplierBill }>('/procurement/supplier-bills', payload);
    return data.data;
}

export async function updateSupplierBill(id: number, payload: SupplierBillPayload): Promise<SupplierBill> {
    const { data } = await api.put<{ data: SupplierBill }>(`/procurement/supplier-bills/${id}`, payload);
    return data.data;
}

export async function recordSupplierBill(id: number): Promise<SupplierBill> {
    const { data } = await api.post<{ data: SupplierBill }>(`/procurement/supplier-bills/${id}/record`);
    return data.data;
}

export async function cancelSupplierBill(id: number, reason: string): Promise<SupplierBill> {
    const { data } = await api.post<{ data: SupplierBill }>(`/procurement/supplier-bills/${id}/cancel`, { reason });
    return data.data;
}

export async function attachSupplierBillFile(id: number, file: File): Promise<SupplierBill> {
    const form = new FormData();
    form.append('file', file);
    const { data } = await api.post<{ data: SupplierBill }>(`/procurement/supplier-bills/${id}/attachment`, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
    });
    return data.data;
}

/** Vendor identities for the bill's header picker — finance-gated; identity only (id, code, name). */
export async function listSupplierBillVendorOptions(q = ''): Promise<{ id: number; code: string; name: string }[]> {
    const { data } = await api.get<{ data: { id: number; code: string; name: string }[] }>(
        '/procurement/supplier-bills/vendor-options',
        { params: q.trim() !== '' ? { q: q.trim() } : {} },
    );
    return data.data;
}

/** A vendor's orders for the bill's optional PO reference — finance-gated; id, date, status only. */
export async function listSupplierBillOrderOptions(vendorId: number): Promise<{ id: number; order_date: string | null; status: string }[]> {
    const { data } = await api.get<{ data: { id: number; order_date: string | null; status: string }[] }>(
        '/procurement/supplier-bills/order-options',
        { params: { vendor_id: vendorId } },
    );
    return data.data;
}

/** An order's arrival lines for the bill's optional matching — finance-gated. */
export async function listSupplierBillReceiptLineOptions(
    purchaseOrderId: number,
): Promise<{ id: number; goods_receipt_note_id: number; item: { id: number; sku: string; name: string; uom: string | null } | null; quantity: string }[]> {
    const { data } = await api.get<{ data: { id: number; goods_receipt_note_id: number; item: { id: number; sku: string; name: string; uom: string | null } | null; quantity: string }[] }>(
        '/procurement/supplier-bills/receipt-line-options',
        { params: { purchase_order_id: purchaseOrderId } },
    );
    return data.data;
}

/**
 * Item identities for the bill's line picker, served inside the finance
 * gate: an Accounts login holds no inventory permission, and /inventory/items
 * answered it 403 — an empty picker on a screen that expressly supports
 * unmatched bills.
 */
export async function listSupplierBillItemOptions(q = ''): Promise<{ id: number; sku: string; name: string; uom: string | null }[]> {
    const { data } = await api.get<{ data: { id: number; sku: string; name: string; uom: string | null }[] }>(
        '/procurement/supplier-bills/item-options',
        { params: q.trim() !== '' ? { q: q.trim() } : {} },
    );
    return data.data;
}

/** The pulled Tally ledger names for the bill's purchase-ledger picker (the accountant selects; the ERP derives nothing — Q39). */
export async function listSupplierBillLedgerOptions(q = ''): Promise<{ name: string; group: string | null }[]> {
    const { data } = await api.get<{ data: { name: string; group: string | null }[] }>(
        '/procurement/supplier-bills/ledger-options',
        { params: q.trim() !== '' ? { q: q.trim() } : {} },
    );
    return data.data;
}

/*
 * ── TALLY-ASSISTED PROCUREMENT ───────────────────────────────────────────
 * All five sit behind module:finance and the FC-06 predicate — a login
 * without Owner/Accounts standing is refused the whole answer, not a thinned
 * one. None of them writes to Tally.
 */

/** The queue of vendor decisions Tally has raised — computed fresh each call. */
export async function fetchTallyVendorReview(): Promise<TallyVendorReviewQueue> {
    const { data } = await api.get<{ data: TallyVendorReviewQueue }>('/procurement/tally/vendor-review');
    return data.data;
}

/** Name the Tally ledger groups whose parties are candidate vendors — an owner act. */
export async function setTallyVendorGroups(groups: string[]): Promise<TallyVendorReviewQueue> {
    const { data } = await api.put<{ data: TallyVendorReviewQueue }>('/procurement/tally/vendor-review/groups', { groups });
    return data.data;
}

/** Create the vendor a "new" row proposes. */
export async function confirmTallyVendorNew(tallyLedgerGuid: string): Promise<void> {
    await api.post('/procurement/tally/vendor-review/confirm-new', { tally_ledger_guid: tallyLedgerGuid });
}

/** Apply the named differences — and only those — to the matched vendor. */
export async function confirmTallyVendorFields(tallyLedgerGuid: string, vendorId: number, fields: string[]): Promise<void> {
    await api.post('/procurement/tally/vendor-review/confirm-fields', {
        tally_ledger_guid: tallyLedgerGuid,
        vendor_id: vendorId,
        fields,
    });
}

/** Set one difference, or a whole ledger, aside — against the value seen. */
export async function dismissTallyVendorDifference(tallyLedgerGuid: string, field: string): Promise<TallyVendorReviewQueue> {
    const { data } = await api.post<{ data: TallyVendorReviewQueue }>('/procurement/tally/vendor-review/dismiss', {
        tally_ledger_guid: tallyLedgerGuid,
        field,
    });
    return data.data;
}

/** What Tally says this vendor last charged for this item. */
export async function fetchTallyVendorItemRate(vendorId: number, itemId: number): Promise<TallyVendorItemRate> {
    const { data } = await api.get<{ data: TallyVendorItemRate }>('/procurement/tally/vendor-item-rate', {
        params: { vendor_id: vendorId, item_id: itemId },
    });
    return data.data;
}
