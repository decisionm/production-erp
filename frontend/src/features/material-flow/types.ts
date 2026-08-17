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
}

export interface MaterialRequestLine {
    id: number;
    item_id: number;
    item: MaterialFlowItemRef | null;
    /** What the floor asked for. */
    quantity: string;
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
    bag_scans: StoreIssueBagScan[];
}

/** The store queue's filters — every one of them applied in SQL, not here. */
export interface MaterialRequestFilters {
    status?: MaterialRequestStatus | MaterialRequestStatus[];
    shift_id?: number;
    work_center_id?: number;
    item_id?: number;
    from?: string;
    to?: string;
    q?: string;
}

export interface CreateMaterialRequestLinePayload {
    item_id: number;
    quantity: number;
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

export interface ReturnToStorePayload {
    received_by?: number | null;
    notes?: string | null;
    lines: { store_issue_line_id: number; quantity: number }[];
}
