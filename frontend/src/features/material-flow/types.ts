import type { ListParams } from '@/lib/listParams';
import type { MaterialRequestStatus, StoreIssueStatus } from './words';

/**
 * The shapes the material-flow screens read.
 *
 * The request shapes match the Phase 7.5 backend resources field for field
 * (`MaterialRequestResource`, `MaterialRequestLineResource`). The store-issue
 * shapes below them are written to the Phase 7.5 contract while that half of
 * the backend is being built alongside this one — see api.ts, where every URL
 * hangs off one prefix so a single edit re-points them.
 *
 * They are declared HERE rather than imported from another feature so these
 * screens compile and load on their own: the route test imports every page
 * module on disk, and a page reaching into a module still being written
 * elsewhere would take that test down with it.
 *
 * FC-06 is a shape rule as much as a screen rule: nothing here carries a
 * purchase figure, a money column or a supplier identity, so none can reach a
 * floor or store reader through these screens.
 */

export interface MaterialFlowItemRef {
    id: number;
    sku: string;
    name: string;
    uom: string;
}

/**
 * A material a request may ask for, with the server's word on whether a
 * machine or area may be named for it (FC-01/Q50).
 *
 * `machine_applies` is false for a material of the common-input (kg) family —
 * the one loading point, crane-fed and piped to all ten machines — true for a
 * consumable that really does go to one machine or area, and null when the
 * backend did not answer, in which case the screens name no machine and guess
 * nothing. It is never decided from a name or an SKU here.
 */
export interface MaterialFlowMaterial extends MaterialFlowItemRef {
    machine_applies: boolean | null;
    /**
     * What is USABLY standing in Production/WIP for this material right now
     * (DEC-20260831-001) — the figure the next request nets off. A negative
     * balance and a unit that disagrees with the handover both report
     * "0.0000": neither may be subtracted from what the floor needs.
     */
    available_in_production: string;
    /**
     * What is ACTUALLY standing in Production/WIP, netted or not — negative
     * included. A negative balance and a unit the master no longer agrees with
     * are both excluded from the netting and both stay visible
     * (DEC-20260831-005): showing only the usable figure prints 0 for each,
     * which reads as an empty floor.
     */
    standing_in_production: string;
    /**
     * False only when the material IS standing on the floor but in a unit the
     * item master no longer agrees with (FC-03). The quantity is real and the
     * netting is refused — the screen must not read as "the floor is empty".
     */
    production_unit_matches: boolean;
}

export interface MaterialRequestLine {
    id: number;
    item_id: number;
    item: MaterialFlowItemRef | null;
    /** WHAT WAS ASKED OF THE STORE. Where the request netted, this is the balance. */
    quantity: string;
    /**
     * What production needed in total, and what was already standing on the
     * floor when the request was raised (DEC-20260831-001).
     *
     * NULL — not zero — on a request that never considered the floor: zero
     * would claim it was empty.
     */
    required_quantity: string | null;
    available_in_production: string | null;
    /** Snapshotted from the item when the request was raised (FC-03). */
    uom: string;
    /** Handed over — standing in Production/WIP, NOT consumed. */
    issued_quantity: string | null;
    /** What the store still owes on this line. */
    remaining_quantity: string | null;
    /**
     * Handed back to the store unused. Returns are recorded against the
     * HANDOVER, so a request line carries this figure only where the backend
     * chooses to roll it up; absent means unknown, and unknown renders as an
     * em dash, never as zero.
     */
    returned_quantity?: string | null;
    notes: string | null;
}

export interface MaterialRequestCan {
    submit: boolean;
    cancel: boolean;
    issue: boolean;
}

export interface MaterialRequest {
    id: number;
    request_number: string;
    status: MaterialRequestStatus;
    requested_by: number | null;
    requested_by_name: string | null;
    requested_at: string | null;
    shift_id: number | null;
    shift_name: string | null;
    /** Null on a common-input request, BY RULE (FC-01) — an answer, not a gap. */
    work_center_id: number | null;
    work_center_code: string | null;
    work_center_name: string | null;
    notes: string | null;
    submitted_at: string | null;
    cancelled_by_name: string | null;
    cancelled_at: string | null;
    cancelled_reason: string | null;
    lines: MaterialRequestLine[];
    /** The handovers made against this request, where the endpoint carries them. */
    issues?: StoreIssue[];
    can: MaterialRequestCan;
}

/** One bag handed over, with both names on it. Never tied to a batch (FC-01). */
export interface StoreIssueBagScan {
    id: number;
    store_issue_line_id: number;
    material_request_line_id: number | null;
    material_bag_id: number | null;
    /** The bag's own barcode, where the relation was loaded. */
    barcode?: string | null;
    material_lot_id: number | null;
    /** The lot number printed on the bag — identity, never a purchase figure. */
    supplier_lot_no?: string | null;
    quantity_kg: string;
    issued_by: number | null;
    issued_by_name?: string | null;
    received_by: number | null;
    received_by_name?: string | null;
    scanned_at: string | null;
    notes: string | null;
}

export interface StoreIssueLine {
    id: number;
    store_issue_id: number;
    material_request_line_id: number | null;
    item_id: number;
    item_name?: string | null;
    item_sku?: string | null;
    uom: string | null;
    from_warehouse_id: number | null;
    to_warehouse_id: number | null;
    quantity_requested: string | null;
    /** What left the store on this handover. */
    quantity_issued: string;
    /** What came back to the store. */
    quantity_returned: string;
    /** What is standing in Production/WIP now — issued, not consumed. */
    quantity_outstanding: string | null;
    /** What the REQUEST still wants after this handover. */
    quantity_remaining_on_request: string | null;
    notes: string | null;
}

export interface StoreIssue {
    id: number;
    issue_number: string;
    material_request_id: number | null;
    status: StoreIssueStatus;
    /** The server's own sentence for the state. Preferred over any local wording. */
    state_label?: string | null;
    /** True while the handover is still holding material in Production/WIP. */
    is_open: boolean;
    issued_by: number | null;
    issued_by_name?: string | null;
    received_by: number | null;
    received_by_name?: string | null;
    issued_at: string | null;
    closed_at: string | null;
    cancellation_reason: string | null;
    notes: string | null;
    lines: StoreIssueLine[];
    /**
     * Absent, not empty, when the endpoint didn't load the relation — the
     * resource wraps it in `whenLoaded('bagScans')`, and the list endpoint
     * behind the queue doesn't eager-load it. Absent means "no scans shown
     * here", never "safe to read .length".
     */
    bag_scans?: StoreIssueBagScan[];
}

/**
 * The store queue's filters — every one of them applied in SQL, not here.
 * `q`, `page` and `per_page` come with ListParams; `q` is the request NUMBER
 * in any spelling ("MR-12", "mr 12", "12") and nothing else, by the
 * server's own rule (MaterialRequestService::applyFilters).
 */
export interface MaterialRequestFilters extends ListParams {
    status?: MaterialRequestStatus | MaterialRequestStatus[];
    shift_id?: number;
    work_center_id?: number;
    item_id?: number;
    from?: string;
    to?: string;
    q?: string;
    /**
     * Production's OWN screen asking for its unsubmitted drafts. The store
     * never sends it, and could not benefit if it did — the server grants it
     * by permission, not by query string.
     */
    /**
     * Typed as the literal `1`, not `boolean`, and the reason is written down
     * in `features/production/api.ts` already: Laravel's `boolean` rule takes
     * `1`, `0`, `"1"` and `"0"` but NOT `"true"`, which is exactly what axios
     * puts on the wire for a JS `true`. This flag was typed `boolean`, sent
     * `true`, and answered the floor's own page with a 422 and a blank table.
     * The literal makes that a compile error instead.
     */
    include_unsubmitted?: 1;
}

/**
 * The store-issue list's query — every key applied in SQL. `q` is the
 * handover's IDENTITY: its issue number, the request it fulfils in any
 * spelling, or a material on any of its lines by SKU or name; never notes.
 */
export interface StoreIssueListParams extends ListParams {
    material_request_id?: number;
    status?: StoreIssueStatus;
    item_id?: number;
}

export interface CreateMaterialRequestLinePayload {
    item_id: number;
    /**
     * What is asked of the store. Sent alongside `required_quantity` so the
     * API keeps the field it has always required — but the SERVER decides the
     * figure it stores, by subtracting the floor as it stands at that moment.
     * A tab left open since the morning cannot net against a floor that has
     * since been consumed or returned.
     */
    quantity: number;
    /** What production needs in total (DEC-20260831-001). The netting input. */
    required_quantity?: number | null;
    notes?: string | null;
}

export interface CreateMaterialRequestPayload {
    shift_id?: number | null;
    /** Sent only for a material the server says takes a machine or area. */
    work_center_id?: number | null;
    notes?: string | null;
    lines: CreateMaterialRequestLinePayload[];
}

/**
 * The handover, as the store writes it: who received it (a user), and one
 * line per material with the request line it answers carried through, so the
 * request's remaining quantity is the server's arithmetic and not a number
 * this screen kept in step by hand.
 */
export interface IssueToProductionPayload {
    /**
     * The idempotency key for this handover. A retry carrying the same key
     * replays the original issue instead of moving the material a second
     * time; the same key with DIFFERENT quantities is refused outright, so a
     * correction can never be mistaken for a retry.
     *
     * Always sent by this app. It is optional on the wire only so an existing
     * integration keeps working — an optional protection nobody exercises
     * protects nothing.
     */
    issue_key: string;
    material_request_id: number;
    received_by?: number | null;
    notes?: string | null;
    lines: {
        material_request_line_id: number;
        item_id: number;
        quantity: number;
        quantity_requested?: string | null;
        uom?: string | null;
    }[];
}

export interface BagScanPayload {
    barcode: string;
    /** Blank for a whole bag — the server reads the bag's own kilograms. */
    quantity_kg?: number | null;
    received_by?: number | null;
    material_request_line_id?: number | null;
    notes?: string | null;
}

/* ------------------------- the daily return home ------------------------- */

/**
 * One material standing in the production area, SPLIT by what may come back
 * which way.
 *
 * The split is the point. `attributed` is held by open store issues and has
 * to go home against their lines so each handover's arithmetic closes;
 * `unattributed` answers no document and is the only part a return with no
 * store issue behind it may draw on. One total would leave the storekeeper
 * guessing, and guessing is how a return quietly takes another issue's
 * kilograms.
 *
 * `on_floor` may be NEGATIVE — a batch may consume more than was issued —
 * and is shown as it is. `unattributed` is then "0.0000": you cannot send
 * back less than nothing.
 */
export interface ProductionReturnable {
    item_id: number;
    sku: string | null;
    name: string | null;
    display_name?: string | null;
    uom: string | null;
    /** Deactivated materials still have a way home; the flag explains the row, it does not block it. */
    item_is_active: boolean;
    warehouse_id: number;
    on_floor: string;
    attributed: string;
    unattributed: string;
    store_issue_lines: {
        store_issue_line_id: number;
        store_issue_id: number;
        issue_number: string;
        status: string;
        outstanding: string;
        to_warehouse_id: number;
    }[];
}

/**
 * ONE door for both kinds of line. A line names a store issue line (the
 * return closes that handover), or a material (the return answers no
 * document). Never both kinds in one line — the server refuses a material
 * that contradicts the handover it is sent with.
 *
 * `to_warehouse_id` addresses the UNATTRIBUTED lines only: an attributed
 * line goes home to the store it came out of, which is a fact about the
 * original handover and not this screen's to redirect.
 */
/**
 * What condition material came back in, mirroring the server's
 * ReturnedQualityState. Optional on the wire: a payload that omits it is
 * recorded as `good`, which is how every return written before the field
 * existed reads.
 */
export type ReturnedQualityState = 'good' | 'damaged';

export interface ProductionReturnPayload {
    to_warehouse_id: number;
    notes?: string | null;
    lines: {
        item_id?: number | null;
        store_issue_line_id?: number | null;
        quantity: number;
        quality_state?: ReturnedQualityState;
    }[];
}

export interface ReturnToStorePayload {
    received_by?: number | null;
    notes?: string | null;
    lines: { store_issue_line_id: number; quantity: number; quality_state?: ReturnedQualityState }[];
}

/**
 * One material STANDING in Production/WIP right now.
 *
 * Sourced from the Production/WIP stock balance, never from request history:
 * a request says what was asked for and an issue says what was handed over,
 * but only the balance says what is still there after a batch has consumed
 * some and a return has taken some back.
 *
 * There is no machine on this shape, and there never should be — a bag belongs
 * to no machine and no batch (FC-01), so the floor's stock is per MATERIAL.
 *
 * There is no BAG COUNT either: `material_bags.current_warehouse_id` is written
 * once at receipt and never maintained, so a count of bags standing in
 * Production/WIP could only ever be zero. Publishing that beside 300 kg of
 * resin would say "this is not bag-tracked" on a system where every bag carries
 * a barcode. See Q57.
 */
export interface ProductionFloorStock {
    item_id: number;
    sku: string | null;
    /** Tally's wire name; `display_name` is the ERP's own, null when unset. */
    name: string | null;
    display_name?: string | null;
    uom: string | null;
    quantity: string;
    last_issued_at: string | null;
    last_issue_number: string | null;
    issued_by: string | null;
    received_by: string | null;
}

/** The floor panel's response — the rows, and whether the location even exists. */
export interface ProductionFloorStockResult {
    data: ProductionFloorStock[];
    meta: { wip_configured: boolean };
}
