import type { ConfigurationAbilities } from '@/components/configuration/types';

import type { Item, Warehouse } from '@/features/inventory/types';
import type { EntryFlags, TallySyncStatus } from '@/features/tally-sync/types';

export interface Customer {
    id: number;
    code: string;
    name: string;
    email: string | null;
    phone: string | null;
    address: string | null;
    gstin: string | null;
    state_code: string | null;
    is_active: boolean;
    /** Archived-by-soft-delete, distinct from is_active. */
    archived_at?: string | null;
    /**
     * The Configuration Lifecycle Contract's `can` block (DEC-20260817-002).
     * A null delete on index is UNDETERMINED, never "no" — the confirm
     * dialog asks show() for the authoritative answer.
     */
    can?: ConfigurationAbilities | null;
    created_at: string;
}

export type SalesOrderStatus = 'draft' | 'confirmed' | 'partially_delivered' | 'completed' | 'cancelled';

export interface SalesOrderLine {
    id: number;
    item: Item;
    quantity: string;
    unit_price: string;
    quantity_delivered: string;
}

export interface SalesOrder {
    id: number;
    /** "SO-{id}" — the spelling the server searches by and the sync payload uses (see `documentNumber`). */
    document_number: string;
    status: SalesOrderStatus;
    customer: Customer;
    order_date: string;
    /**
     * The customer's promise date — typed by hand on the order, never
     * derived. It is not a production ETA: nothing schedules against it.
     */
    expected_date: string | null;
    /**
     * Whether that promise date is already past on the FACTORY's calendar
     * (IST) while the order is still open. The server derives it on every
     * read; the browser's clock is never consulted and the flag is stored
     * nowhere.
     */
    is_overdue: boolean;
    notes: string | null;
    lines: SalesOrderLine[];
    /** Quantities across every line, as decimal strings (4dp) — never parsed for arithmetic here. */
    totals: SalesOrderTotals;
    deliveries_count: number;
    invoices_count: number;
    /**
     * Whether Cancel is allowed RIGHT NOW, by the same rule the server
     * enforces (draft or confirmed, nothing delivered, no invoices). The
     * button reads this; it never re-derives the rule client-side.
     */
    can_cancel: boolean;
    /**
     * Whether the expected date and notes may be changed RIGHT NOW (draft
     * or confirmed), by the same rule the server enforces. The Edit action
     * reads this; it never re-derives the rule client-side.
     */
    can_edit: boolean;
    created_at: string;
    /** The chain below this order — ONLY on GET /sales/sales-orders/{id}, never on the list. */
    trace?: SalesOrderTrace;
}

export interface SalesOrderTotals {
    ordered_quantity: string;
    delivered_quantity: string;
    invoiced_quantity: string;
}

/** The order a delivery or invoice hangs off, as the list carries it. */
export interface SalesOrderRef {
    id: number;
    document_number: string;
    status: SalesOrderStatus;
}

/** The party on a delivery / invoice row — a CUSTOMER, never a vendor (FC-06 has nothing to withhold here). */
export interface CustomerRef {
    id: number;
    code: string;
    name: string;
}

export interface DeliveryLine {
    id: number;
    sales_order_line_id: number;
    item: Item;
    quantity: string;
}

export interface Delivery {
    id: number;
    /** "DN-{id}" — the voucher_number the Delivery Note payload carries. */
    document_number: string;
    sales_order_id: number;
    sales_order: SalesOrderRef;
    customer: CustomerRef;
    warehouse: Warehouse;
    reference: string | null;
    delivered_date: string;
    notes: string | null;
    lines: DeliveryLine[];
    /** How many scanned cartons left on this delivery — 0 for a typed-quantity delivery. */
    carton_count: number;
    /** The Delivery Note's queue entry, or null when none exists (a pre-Phase-2 backfill, say). */
    tally: TallyLink | null;
    created_at: string;
    /** ONLY on GET /sales/deliveries/{id}. */
    trace?: DeliveryTrace;
}

export type InvoiceStatus = 'draft' | 'issued' | 'paid';

export interface InvoiceLine {
    id: number;
    sales_order_line_id: number;
    item: Item;
    quantity: string;
    unit_price: string;
}

export interface Invoice {
    id: number;
    /** "INV-{id}" — the voucher_number the Sales payload carries. */
    document_number: string;
    status: InvoiceStatus;
    sales_order_id: number;
    sales_order: SalesOrderRef;
    customer: Customer;
    invoice_date: string;
    due_date: string | null;
    notes: string | null;
    lines: InvoiceLine[];
    /** The Sales voucher's queue entry — null for a draft (nothing is queued until issue). */
    tally: TallyLink | null;
    created_at: string;
    /** ONLY on GET /sales/invoices/{id}. */
    trace?: InvoiceTrace;
}

// ------------------------------------------------------------- Tally link --

/**
 * WHERE A DOCUMENT'S VOUCHER STANDS IN THE SYNC QUEUE — the one hop from Sales
 * into TallySync (the server's TallySyncLinkService; nothing here reads Tally).
 *
 * STATUS + FLAGS + LINK, AND NOTHING ELSE. No payload, no rate, no rejection
 * text rides on this shape — the Tally Sync page (`link`) is where those live,
 * behind its own gates. `flags` is the entry's honesty flags verbatim: for a
 * Sales / Delivery Note voucher `unvalidated_builder` is set, and the note it
 * carries is the sentence to show (DEC-20260809-003 — Tally is the sales
 * system of record). null on the document means NO entry exists, which is a
 * different fact from "pending" and is rendered as a dash, never as a state.
 */
export interface TallyLink {
    entry_id: number;
    voucher_type: string;
    status: TallySyncStatus;
    voucher_number: string | null;
    synced_at: string | null;
    flags: EntryFlags;
    /** "/tally-sync?entry={id}" — the deep link the Tally Sync page honours. */
    link: string;
}

// ------------------------------------------------------------------ trace --

/**
 * One physical box on a delivery — read through Production, never invented
 * here. `batch_no` rides through the shift production entry; null when the
 * carton carries no entry (or the batch has no number yet).
 */
export interface TraceCarton {
    carton_no: string;
    pieces: string;
    shift_production_entry_id: number | null;
    batch_no: string | null;
}

export interface TraceLine {
    item: { id: number; name: string } | null;
    quantity: string;
}

export interface TraceDelivery {
    id: number;
    document_number: string;
    reference: string | null;
    delivered_date: string | null;
    warehouse: { id: number; name: string } | null;
    lines: TraceLine[];
    cartons: TraceCarton[];
    tally: TallyLink | null;
}

export interface TraceInvoice {
    id: number;
    document_number: string;
    status: InvoiceStatus;
    invoice_date: string | null;
    lines: (TraceLine & { unit_price: string })[];
    tally: TallyLink | null;
}

export interface TraceSalesOrder extends SalesOrderRef {
    customer: CustomerRef;
}

/** GET /sales/sales-orders/{id} — the chain below the order, in the order it runs. */
export interface SalesOrderTrace {
    deliveries: TraceDelivery[];
    invoices: TraceInvoice[];
}

/** GET /sales/deliveries/{id}. `sales_order` is null only if the order row is gone. */
export interface DeliveryTrace {
    sales_order: TraceSalesOrder | null;
    cartons: TraceCarton[];
    tally: TallyLink | null;
}

/** GET /sales/invoices/{id}. */
export interface InvoiceTrace {
    sales_order: TraceSalesOrder | null;
    tally: TallyLink | null;
}

// ----------------------------------------------------------- Tally mirror --

/**
 * GET /sales/tally-mirror — WHAT THESE PAGES ARE NOT.
 *
 * Real sales are invoiced in Tally (DEC-20260809-003); the ERP has no read
 * path from Tally, so Tally-side Sales / Sales Order vouchers are NOT
 * mirrored here and the documents on these pages are the ERP-originated
 * subset only. The server says so in its own words and the panel renders
 * THOSE words — never a sentence typed on this side, so the copy cannot
 * drift from the decision it stands on.
 */
export interface TallyMirror {
    mirrored: boolean;
    /** The decision id the copy stands on ("DEC-20260809-003"). */
    decision: string;
    headline: string;
    body: string;
    erp_invoice_builder: { validated: boolean; note: string };
    payments_recorded_here: boolean;
    payments_note: string;
}

// ---------------------------------------------------------------- filters --

/** Which of the three ERP-originated documents a list, drawer or reference is about. */
export type SalesDocumentKind = 'sales_order' | 'delivery' | 'invoice';

/**
 * The server-side filters the three list endpoints accept — one shape, and
 * filters.ts's per-document allowlist decides which keys each list may send
 * (a delivery has no `status`; an order has no `sales_order_id`). Every
 * field is optional; empties never reach the URL. `from`/`to` are calendar
 * days (YYYY-MM-DD, inclusive) on the document's own date — order_date,
 * delivered_date (factory-day boundaries, server-side), invoice_date.
 * `sort` is a field name, `-` prefixed for descending; the server's default
 * is newest first.
 */
export interface SalesListFilters {
    customer_id?: number;
    sales_order_id?: number;
    status?: string;
    from?: string;
    to?: string;
    /** Any line carrying this item. */
    item_id?: number;
    /** Document number in any spelling ("SO-12", "so 12", "12"), delivery reference, customer name or code. Not notes. */
    q?: string;
    sort?: string;
    page?: number;
    per_page?: number;
}

// ------------------------------------------------------------ cost & margin --

/**
 * WHAT AN ORDER COSTS — `GET /sales/sales-orders/{id}/cost-insight`.
 *
 * TWO ANSWERS, NEVER MIXED, and the server keeps them apart deliberately: an
 * ESTIMATE (what a piece WOULD cost from the material standing in the store
 * today — always available) and an ACTUAL (what a piece DID cost, from the
 * latest approved batch carrying a live cost allocation — absent until such a
 * batch exists). The UI must never present one wearing the other's label.
 *
 * EVERY MONEY FIELD IS A DECIMAL STRING OR NULL, never a number — bcmath
 * figures on the wire, parsed only at the last moment for display. null NEVER
 * means zero anywhere in this payload; it means "this could not be worked
 * out", and a `reason` sentence beside it says why. Print the sentence.
 */
export type SalesCostComponentStatus = 'priced' | 'unknown' | 'excluded';

/**
 * One costed (or deliberately uncosted) part of a piece.
 *
 * THREE STATES, and collapsing `excluded` into `unknown` is the mistake this
 * type exists to prevent. `unknown` means "we do not know this price" and it
 * nulls the total above it. `excluded` means "there is no price to know" — a
 * Clear bottle takes no masterbatch, and the tape whose unit is still an open
 * question in the factory is carried, named, and left out of the money. An
 * excluded part contributes nothing and does NOT null the total.
 *
 * THERE ARE NO BAG OR SUPPLIER IDENTITY KEYS, at any permission level. The
 * owner's correction (2-Aug) ended the "next bag out of the store" basis this
 * estimate once quoted: with ONE common resin input point serving every
 * machine, no single bag stands behind a price and naming one would be the
 * dead bag-to-batch claim wearing a different hat. Resin is priced at the
 * common pool's weighted average, and `source`/`rate_source` say so.
 */
export interface SalesCostComponent {
    /** Open vocabulary — 'resin', 'masterbatch', 'packaging', a packing kind. */
    kind: string;
    status: SalesCostComponentStatus;
    per_unit_cost: string | null;
    /** The identity-free explanation, in the server's own words. */
    source: string;
    reason: string | null;
    as_of: string;
    item: { id: number; name: string } | null;
    rate: string | null;
    /** e.g. 'per_kg', 'per_nos' — the unit the rate is quoted in. */
    rate_unit: string | null;
    /** See `salesRateSourceLabel`. */
    rate_source: string | null;
    basis: string | null;
}

/**
 * What a piece of this product WOULD cost, made from what is in the store today.
 *
 * `estimated_unit_cost` is null when any consumed material has no recorded
 * price — the server refuses a confident understatement — and `reason` names
 * which materials. `sources` carries the same explanations in words with no
 * bag or supplier identity in them and is present for EVERYONE.
 */
export interface SalesCostEstimate {
    label: 'estimate';
    /** When these figures were READ — the server stamps it at serialization. */
    as_of: string;
    estimated_unit_cost: string | null;
    estimated_margin_pct: string | null;
    reason: string | null;
    /**
     * kind → the explanation in words. Iterate with `Object.entries`; never
     * index by a kind you assume is there. Serializes as an empty JSON array
     * on the deleted-product path, which `Object.entries` reads identically.
     */
    sources: Record<string, string>;
    /**
     * THE ANATOMY IS FINANCE'S — the per-kg and per-unit rates behind each
     * part. ABSENT (not null, not empty) for anyone without
     * finance.view/finance.manage, and absent for everyone when the line's
     * product has left the item master. It carries no bag or supplier
     * identity for anyone: there is none to carry.
     *
     * Gate the breakdown on THE KEY BEING PRESENT rather than on a permission
     * check of your own: the server has already decided, it cannot go stale
     * against a cached /auth/me, and a second opinion here can only disagree.
     */
    components?: SalesCostComponent[];
}

/** Which batch an actual came out of. Carries no supplier or bag identity, so it is NOT finance-gated. */
export interface SalesCostActualSource {
    shift_production_entry_id: number;
    batch_number: string | null;
    /** The day the batch RAN — a calendar date, not an instant. */
    production_date: string | null;
    /** The moment the accountant approved it — a genuine instant. */
    approved_at: string | null;
    basis: string;
}

/**
 * What a piece of this product DID cost.
 *
 * THREE OUTCOMES, and the middle one is the one that gets flattened by
 * mistake:
 *
 *   `source` null            — no batch. `reason` says which silence it is:
 *                              nothing made yet, or a costing withdrawn by a
 *                              correction and not yet redone. Those send a
 *                              reader to completely different places.
 *   `source` set, cost null  — a batch EXISTS but could not be priced.
 *                              `reason` says why. Still show the batch: it is
 *                              the most actionable fact on this branch.
 *   `source` set, cost set   — a real actual.
 *
 * So the badge discriminator is `actual_unit_cost !== null`, never
 * `source !== null`. Read the reasons verbatim; never switch on their text.
 */
export interface SalesCostActual {
    label: 'actual';
    as_of: string;
    actual_unit_cost: string | null;
    actual_margin_pct: string | null;
    reason: string | null;
    source: SalesCostActualSource | null;
}

export interface SalesCostLine {
    sales_order_line_id: number;
    /** null when the line names a product that has left the item master. */
    item: { id: number; name: string; sku: string | null } | null;
    quantity: string;
    unit_price: string;
    estimate: SalesCostEstimate;
    actual: SalesCostActual;
}

/**
 * The order-level roll-up for one label. BOTH blocks are always present.
 *
 * NEVER A PARTIAL TOTAL: unless every line has a figure, `cost_total` and
 * `margin_pct` are null and `reason` says how many lines are missing.
 * `revenue_total` is filled in either way, so revenue-present/cost-absent is a
 * normal state rather than a bug.
 */
export interface SalesCostOrderTotal {
    label: 'estimate' | 'actual';
    as_of: string;
    cost_total: string | null;
    revenue_total: string | null;
    margin_pct: string | null;
    reason: string | null;
    /**
     * On `order_actual` only, and ALWAYS on it — present or absent figures
     * alike. There is no finished-goods cost allocation in this system, so an
     * order's actual is the batch figures of its products added up. Render it
     * every time: it is the difference between a number an accountant can use
     * and one they will later discover was not what they thought.
     */
    basis?: string;
}

export interface SalesCostInsight {
    sales_order_id: number;
    status: SalesOrderStatus;
    as_of: string;
    lines: SalesCostLine[];
    order_estimate: SalesCostOrderTotal;
    order_actual: SalesCostOrderTotal;
}

/**
 * A component's `rate_source` token in the words a reader understands.
 *
 * Unknown tokens fall through UNCHANGED rather than to a guess or a blank —
 * the same rule `batchCostSourceLabel` follows in Production. A provenance
 * this build has not been taught is still better evidence than no provenance,
 * and it is exactly the row worth looking at.
 */
export function salesRateSourceLabel(source: string | null | undefined): string {
    if (!source) return '—';
    switch (source) {
        case 'resin_pool_weighted_average':
            // The one that must never read as a bag's price: it is an
            // accounting allocation across every bag loaded into the common
            // input, not what any single bag cost.
            return "the common resin pool's weighted average";
        case 'average_fallback':
            return 'the store moving average';
        case 'store_average':
            return 'the store moving average';
        case 'unknown':
            return 'no recorded rate';
        default:
            return source;
    }
}
