import type { ConfigurationAbilities } from '@/components/configuration';
/*
 * The production request's status, on the STORE's queue row, from the module
 * that owns the state machine. A type-only import, erased at build, so the
 * two modules stay one definition apart rather than two spellings of four
 * strings that would drift the first time a state is added.
 */
import type { ProductionRequestStatus } from '@/features/production/types';

export type ItemTrackingType = 'none' | 'batch' | 'serial';

export interface Item {
    id: number;
    sku: string;
    name: string;
    description: string | null;
    uom: string;
    hsn_sac_code: string | null;
    reorder_level: string;
    nominal_weight_grams: string | null;
    /** Product packing master — null until the standards data load arrives. */
    nos_per_tray: number | null;
    trays_per_box: number | null;
    nos_per_box: number | null;
    /** Pouch packing standards (Wave A) — null for items not pouch-packed. */
    nos_per_pouch: number | null;
    pouches_per_box: number | null;
    /** Product colour (drives masterbatch suggestion); "Clear" means no MB. */
    colour: string | null;
    /** Standard cycle time, seconds per shot — decimal string e.g. "10.60". */
    standard_cycle_time: string | null;
    /** Standard cavity count of the item's mold. */
    standard_cavities: number | null;
    tracking_type: ItemTrackingType;
    /**
     * The Tally stock item this product IS, once Tally has synced it. Present
     * means vouchers naming this product will post; absent means production can
     * still run and only the voucher waits — the "Tally mapping pending" state.
     */
    tally_stock_item_guid: string | null;
    /**
     * Phase 5 (P5-02): TRUE while the SKU is the name-derived one the Tally
     * pull seeded and no person has set it; a manual SKU edit clears it.
     * Optional — absent on a backend that predates the column. Marks the
     * row; never says what the SKU should be (the SKU format programme is
     * the owner's).
     */
    sku_provisional?: boolean;
    /**
     * Archived-by-soft-delete, ISO. Nothing archives these this way today
     * (Archive clears `is_active`), but a Tally pull can restore a trashed
     * row — so the screen is told rather than guessing.
     */
    archived_at?: string | null;
    /**
     * The Configuration Lifecycle Contract's `can` block (DEC-20260817-002).
     * Optional: absent on a backend that predates the wiring, and the row
     * actions then offer nothing rather than invent permission. `delete:
     * null` on an index row means UNDETERMINED — the confirm asks.
     */
    can?: ConfigurationAbilities | null;
    is_active: boolean;
    /**
     * Whether the floor may ask the store for this item. Configuration the
     * owner controls — never inferred from a name, an SKU or a unit.
     */
    is_production_input: boolean;
    created_at: string;
}

export interface Warehouse {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
    /** Set only for godowns pulled from Tally — a safe voucher godown. */
    tally_guid: string | null;
    /**
     * Archived-by-soft-delete, ISO. Nothing archives these this way today
     * (Archive clears `is_active`), but a Tally pull can restore a trashed
     * row — so the screen is told rather than guessing.
     */
    archived_at?: string | null;
    /**
     * The Configuration Lifecycle Contract's `can` block (DEC-20260817-002).
     * Optional: absent on a backend that predates the wiring, and the row
     * actions then offer nothing rather than invent permission. `delete:
     * null` on an index row means UNDETERMINED — the confirm asks.
     */
    can?: ConfigurationAbilities | null;
    created_at: string;
}

export interface StockBalance {
    id: number;
    item: Item;
    warehouse: Warehouse;
    quantity: string;
    /**
     * Weighted average of the purchase rates received into this balance —
     * served only to finance.view/manage eyes (FC-06); the key is ABSENT,
     * never null, for everyone else. Presence is the server's ruling.
     */
    average_cost?: string;
}

export type StockMovementType = 'receipt' | 'issue' | 'transfer_in' | 'transfer_out';

export interface Batch {
    id: number;
    item: Item;
    batch_number: string;
    manufactured_date: string | null;
    expiry_date: string | null;
    notes: string | null;
    created_at: string;
}

export interface BatchOnHand {
    warehouse_id: number;
    warehouse_code: string | null;
    quantity: string;
}

export interface BatchLedger {
    batch: Batch;
    on_hand: BatchOnHand[];
    movements: StockMovement[];
}

export type SerialNumberStatus = 'registered' | 'in_stock' | 'consumed' | 'sold' | 'scrapped';

export interface SerialNumber {
    id: number;
    item: Item;
    serial_number: string;
    status: SerialNumberStatus;
    warehouse: Warehouse | null;
    movements?: StockMovement[];
    created_at: string;
}

export interface StockMovement {
    id: number;
    type: StockMovementType;
    item: Item;
    warehouse: Warehouse;
    batch?: Batch | null;
    serial_number?: SerialNumber | null;
    quantity: string;
    /** GRN purchase rate — served only to finance.view/manage eyes; absent otherwise. */
    unit_cost?: string | null;
    reference: string | null;
    transfer_group: string | null;
    movement_date: string;
    notes: string | null;
}

// ------------------------------------------------------- store fulfilment --

/**
 * THE FIVE STATES A SALES ORDER LINE CAN BE IN, as
 * `FulfilmentQueueService::STATES` computes them. Server-computed, always:
 * the browser renders the answer it was given and never re-derives the state
 * machine, so a button and the 422 behind it cannot tell two stories.
 *
 * `over_reserved` is asked FIRST on the server and beats even a covered line
 * (S8) — more pieces are promised than the factory holds, and somebody has to
 * decide whose order gives way.
 */
export type FulfilmentState =
    | 'untouched'
    | 'partially_allocated'
    | 'awaiting_production'
    | 'over_reserved'
    | 'fully_allocated';

/** The party a hold is being kept for — the queue row's own, today. */
export interface FulfilmentParty {
    id: number;
    name: string | null;
}

/** The product a queue row, a hold or a planning row is about. */
export interface FulfilmentItemRef {
    id: number;
    sku: string | null;
    name: string | null;
}

/**
 * ONE HOLD standing against a line — "held for {customer} since {date}".
 *
 * `quantity` is what was held; `consumed_quantity` is what a delivery has
 * already spent out of it. Neither is the figure that is still holding stock
 * away from other orders — the row's own `reserved` is that, folded on the
 * server from every hold's outstanding.
 */
export interface FulfilmentHold {
    reservation_id: number;
    quantity: string;
    consumed_quantity: string;
    /** ISO instant the hold was taken. Never null in practice; typed honestly. */
    held_since: string | null;
    customer: FulfilmentParty | null;
    sales_order_id: number;
}

/** The open production request on a line, as the queue row carries it. */
export interface FulfilmentQueueRequestRef {
    id: number;
    status: ProductionRequestStatus;
    priority: number;
    quantity: string;
}

/**
 * WHAT THE STORE MAY DO WITH THIS ROW, decided by the same predicates
 * StockReservationService and ProductionRequestService refuse on. Read it;
 * never re-derive it from the quantities beside it.
 */
export interface FulfilmentAbilities {
    reserve: boolean;
    release: boolean;
    repoint: boolean;
    send_to_production: boolean;
}

/**
 * One row of GET /inventory/fulfilment/queue.
 *
 * `ordered` / `delivered` / `reserved` / `shortfall` are the LINE's figures;
 * `free` and `over_reserved` are the ITEM's, in the finished-goods store, and
 * are why the row sits where it does. Every quantity is a 4dp decimal STRING
 * — parsed for display only, never for arithmetic that reaches the wire.
 *
 * FC-06: no rate, no cost, no amount. The line carries a unit_price and the
 * server deliberately does not read it — this is the store's screen.
 */
export interface FulfilmentQueueRow {
    line_id: number;
    sales_order_id: number;
    customer: FulfilmentParty | null;
    item: FulfilmentItemRef | null;
    ordered: string;
    delivered: string;
    reserved: string;
    shortfall: string;
    free: string;
    over_reserved: string;
    fulfilment_state: FulfilmentState;
    holds: FulfilmentHold[];
    request: FulfilmentQueueRequestRef | null;
    can: FulfilmentAbilities;
}

/**
 * What the queue read accepts. `state` NARROWS and its absence is not
 * "everything": with no state the server hides `fully_allocated` lines (S16),
 * and naming the state is how they are asked for. It is ONE value, never a
 * list — `Rule::in(STATES)` — so this is a plain select, not a multi-select.
 */
export interface FulfilmentQueueFilters {
    state?: FulfilmentState;
    page?: number;
    per_page?: number;
}

/**
 * A HOLD as reserve, release and re-point return it.
 *
 * `outstanding_quantity` is what it is still holding away from other orders
 * (quantity − consumed − released) and is deliberately not the same as
 * `quantity`. A hold MOVES NO STOCK (invariant 1), so this shape carries no
 * movement, no balance and no valuation.
 */
export interface StockReservation {
    id: number;
    item_id: number;
    warehouse_id: number;
    sales_order_line_id: number;
    quantity: string;
    consumed_quantity: string;
    released_quantity: string;
    outstanding_quantity: string;
    status: 'active' | 'released' | 'consumed';
    released_reason: string | null;
    created_by: number | null;
    released_by: number | null;
    created_at: string | null;
}

// ---------------------------------------------------- fulfilment planning --

/**
 * WHY A LINE HAS NO DATE. Three tokens, and the middle one CASCADES (S12):
 * once something ahead in the queue cannot be estimated, nothing behind it
 * can be either, and the server refuses to quote a caveat-date instead.
 *
 * Typed as a union widened with `(string & {})` on purpose — an unrecognised
 * token from a newer server must fall through to the screen unchanged rather
 * than to a blank, the same rule `salesRateSourceLabel` follows.
 */
export type CannotEstimateReason =
    | 'no_production_standard'
    | 'items_ahead_without_standard'
    | 'no_active_shift_hours';

/**
 * WHAT THE NUMBERS ON THE PLANNING DASHBOARD STAND ON — printed under the
 * table as figures, never as a paragraph. `shift_hours` is the hours in ONE
 * shift (the sum of the real active shift rows divided by how many there
 * are), so shift_hours × shifts_per_day is the factory's day.
 */
export interface FulfilmentPlanningBasis {
    shifts_per_day: number;
    parallel_lines: number;
    /** Decimal string, or null when no active shift carries a clock. */
    shift_hours: string | null;
    /** IANA name — the factory timezone all day math is done in. */
    timezone: string;
    source: 'active_shifts' | 'no_active_shifts' | (string & {});
}

/**
 * One line's ETA, or its refusal to give one.
 *
 * NO DATE IS EVER STORED (S11) — this is computed on read and gone again,
 * because a saved date is wrong the moment somebody reorders the queue.
 * `capacity_per_shift` and `shifts_needed` are NUMBERS (pieces, shifts),
 * while `needed` and `free` are 4dp decimal strings; the mix is the server's
 * and is deliberate.
 */
export interface FulfilmentPlanningRow {
    line_id: number;
    item: FulfilmentItemRef | null;
    customer: FulfilmentParty | null;
    needed: string;
    free: string;
    /** How many open requests sit in front of this one. */
    queued_ahead: number;
    capacity_per_shift: number | null;
    shifts_needed: number | null;
    /** YYYY-MM-DD in the factory timezone, or null when it cannot be said. */
    estimated_ready_date: string | null;
    cannot_estimate: boolean;
    reason: CannotEstimateReason | (string & {}) | null;
}

/** What the floor should be working on today — a priority read, not a capacity claim. */
export interface FulfilmentPlanningTarget {
    request_id: number;
    item: FulfilmentItemRef | null;
    quantity: string;
    priority: number;
}

/** GET /inventory/fulfilment/planning — a bare object, not a row collection. */
export interface FulfilmentPlanningResult {
    data: FulfilmentPlanningRow[];
    basis: FulfilmentPlanningBasis;
    today_targets: FulfilmentPlanningTarget[];
}
