/**
 * THE WORDS FOR THE STORE → PRODUCTION MATERIAL FLOW.
 *
 * Every state, every transition and every refusal on the material-flow
 * screens is named here, once, in plain English — and nowhere else. Two
 * screens (the production request and the store queue) show the same
 * quantities to two different readers; if each spelled its own headings the
 * two would drift, and the drift would land exactly where it hurts most:
 * on the difference between "issued to production" and "consumed".
 *
 * THE RULE THIS FILE EXISTS TO ENFORCE — a store issue is NOT a consumption.
 * Three states stay distinct at every step:
 *
 *      In store  →  Issued to production (NOT yet consumed)  →  Consumed
 *                   └──────────────→  Returned to store
 *
 * A STATE IS NOT A PLACE. The owner named the places (DEC-20260817-001):
 * Raw Material Store → Production/WIP → Finished Goods Store. There is no
 * Day Bin. Production/WIP is the place that holds material issued to
 * production and not yet consumed — so "Issued to production" is the STATE
 * of a quantity, and "Production/WIP" is the warehouse it sits in. The two
 * label maps below are kept apart for that reason: naming a location
 * "Issued to Production" would mint a synonym for a warehouse row that
 * already exists, which the decision forbids.
 *
 * Pure module: no React, no network, no dates, no globals — so its words are
 * pinned by an ordinary unit test (words.test.ts), including the FC-06 pin
 * that not one string here carries money or supplier identity.
 */

/* ------------------------------------------------------------------ *
 * The places (DEC-20260817-001)
 * ------------------------------------------------------------------ */

export type MaterialLocation = 'raw_material_store' | 'production_wip' | 'finished_goods_store';

export const LOCATION_LABEL: Record<MaterialLocation, string> = {
    raw_material_store: 'Raw Material Store',
    production_wip: 'Production/WIP',
    finished_goods_store: 'Finished Goods Store',
};

export function locationLabel(location: MaterialLocation): string {
    return LOCATION_LABEL[location];
}

/* ------------------------------------------------------------------ *
 * The states
 * ------------------------------------------------------------------ */

export type MaterialState = 'in_store' | 'issued_to_production' | 'consumed' | 'returned_to_store';

/** The order a reader follows down the flow. */
export const STATE_ORDER: MaterialState[] = ['in_store', 'issued_to_production', 'consumed', 'returned_to_store'];

/** Full labels — used where there is room for the whole phrase. */
export const STATE_LABEL: Record<MaterialState, string> = {
    in_store: 'In store',
    issued_to_production: 'Issued to production — not yet consumed',
    consumed: 'Consumed by production',
    returned_to_store: 'Returned to store',
};

/** Column headings — shorter, and still carrying the qualifier that matters. */
export const STATE_COLUMN_LABEL: Record<MaterialState, string> = {
    in_store: 'In store',
    issued_to_production: 'Issued to production (not yet consumed)',
    consumed: 'Consumed',
    returned_to_store: 'Returned to store',
};

/** One plain sentence per state, shown under the heading it explains. */
export const STATE_HELP: Record<MaterialState, string> = {
    in_store: 'Still in the Raw Material Store. Nothing has left the store for this line yet.',
    issued_to_production:
        'Handed over to production and standing in Production/WIP. It has left the store, and it has NOT been consumed — the books still hold it as stock.',
    consumed: 'Booked against a completed batch. This is the only step that takes the material out of stock as production use.',
    returned_to_store: 'Handed back from Production/WIP to the Raw Material Store unused, and standing in the store again.',
};

/** Where a quantity in this state physically stands — consumption is an event, not a place. */
export const STATE_LOCATION: Record<MaterialState, MaterialLocation | null> = {
    in_store: 'raw_material_store',
    issued_to_production: 'production_wip',
    consumed: null,
    returned_to_store: 'raw_material_store',
};

/** Ant Design tag colours, so the same state reads the same on both screens. */
export const STATE_TONE: Record<MaterialState, string> = {
    in_store: 'default',
    issued_to_production: 'blue',
    consumed: 'purple',
    returned_to_store: 'gold',
};

export function stateLabel(state: MaterialState): string {
    return STATE_LABEL[state];
}

/**
 * The one note that goes on the material-flow screens.
 *
 * Deliberately SHORT. The long form said the same thing three ways and was
 * then printed twice on the same page — once as this note and again inside a
 * legend — which is what the owner asked to be cut back on 18-Aug. The states
 * themselves are already unambiguous where a reader meets them: the table's
 * own tag reads "Issued to production — not yet consumed", so the screen does
 * not need a paragraph to stop that being misread as consumption.
 */
export const ISSUE_IS_NOT_CONSUMPTION =
    'A store issue is not a consumption: material moves to Production/WIP and stays in stock until a batch is completed. Anything left over returns to the store.';

/**
 * Over-issuing is NORMAL here, and the screens must not treat it as an error:
 * the store hands over whole bags, so a 20 kg ask met with a 25 kg bag is a
 * 25 kg issue. The extra stands in Production/WIP with the rest and comes
 * back as a return if the shift does not use it.
 */
export const OVER_ISSUE_IS_ORDINARY =
    'Handing over more than was asked for is ordinary: a bag is not divisible, so a 20 kg ask met with a 25 kg bag is a 25 kg issue. The extra stands in Production/WIP like the rest, and comes back as a return if it is not used.';

/** FC-01: how far the bag trace goes, said out loud on the screens that scan bags. */
export const TRACE_STOPS_AT_THE_ISSUE =
    'The trace stops at the issue. This record says which bags were issued to production, by whom, when, and against which request. It never says a batch used a particular bag — batch consumption is calculated (FC-01).';

/* ------------------------------------------------------------------ *
 * Request statuses (the production side)
 * ------------------------------------------------------------------ */

export type MaterialRequestStatus = 'draft' | 'submitted' | 'partially_issued' | 'issued' | 'cancelled';

export const REQUEST_STATUS_LABEL: Record<MaterialRequestStatus, string> = {
    draft: 'Draft',
    submitted: 'Waiting on the store',
    partially_issued: 'Part issued',
    issued: 'Fully issued',
    cancelled: 'Cancelled',
};

export const REQUEST_STATUS_HELP: Record<MaterialRequestStatus, string> = {
    draft: 'Not sent yet. The store cannot see it.',
    submitted: 'In the store queue. Nothing has left the store for it yet.',
    partially_issued: 'The store has issued some of it. The rest is still to issue.',
    issued: 'Everything asked for has been issued to production. Issued is not consumed.',
    cancelled: 'Withdrawn. Whatever was already issued stays issued and must be returned if unused.',
};

export const REQUEST_STATUS_TONE: Record<MaterialRequestStatus, string> = {
    draft: 'default',
    submitted: 'orange',
    partially_issued: 'blue',
    issued: 'green',
    cancelled: 'red',
};

export function requestStatusLabel(status: MaterialRequestStatus): string {
    return REQUEST_STATUS_LABEL[status];
}

/* ------------------------------------------------------------------ *
 * Store issue statuses (the store side)
 * ------------------------------------------------------------------ */

export type StoreIssueStatus = 'issued' | 'partially_returned' | 'returned' | 'completed' | 'cancelled';

export const ISSUE_STATUS_LABEL: Record<StoreIssueStatus, string> = {
    issued: 'Issued to production — not yet consumed',
    partially_returned: 'Part returned to store',
    returned: 'Returned to store in full',
    completed: 'Closed — production kept it',
    cancelled: 'Cancelled',
};

export const ISSUE_STATUS_HELP: Record<StoreIssueStatus, string> = {
    issued: 'Handed over and standing in Production/WIP. It is stock, not consumption; whatever a batch did not use can still come back.',
    partially_returned: 'Some of it came back to the store. The rest is still standing in Production/WIP, unconsumed.',
    returned: 'All of it came back to the store. Nothing from this handover is standing with production.',
    completed: 'Closed by the store because production kept what it was given. No stock moves on closing — what a batch used is a different figure, worked out elsewhere.',
    cancelled: 'Reversed in full, with a reason. The material never stood with production.',
};

export const ISSUE_STATUS_TONE: Record<StoreIssueStatus, string> = {
    issued: 'blue',
    partially_returned: 'gold',
    returned: 'green',
    completed: 'default',
    cancelled: 'red',
};

export function issueStatusLabel(status: StoreIssueStatus): string {
    return ISSUE_STATUS_LABEL[status];
}

/** Is this handover still holding material in Production/WIP? */
export function issueIsOpen(status: StoreIssueStatus): boolean {
    return status === 'issued' || status === 'partially_returned';
}

/* ------------------------------------------------------------------ *
 * Transitions — the words on the buttons
 * ------------------------------------------------------------------ */

export type MaterialFlowTransition =
    | 'submit_request'
    | 'cancel_request'
    | 'start_issue'
    | 'scan_bag'
    | 'complete_issue'
    | 'cancel_issue'
    | 'return_to_store';

export const TRANSITION_LABEL: Record<MaterialFlowTransition, string> = {
    submit_request: 'Send to store',
    cancel_request: 'Cancel request',
    start_issue: 'Issue to production',
    scan_bag: 'Scan bag at handover',
    complete_issue: 'Complete handover',
    cancel_issue: 'Cancel handover',
    return_to_store: 'Accept return to store',
};

export const TRANSITION_HELP: Record<MaterialFlowTransition, string> = {
    submit_request: 'Puts the request in the store queue. Nothing moves in stock yet.',
    cancel_request: 'Withdraws the request. A reason is required and is kept with it.',
    start_issue: 'Moves the quantity you name from the Raw Material Store to Production/WIP. It becomes issued stock, not consumption.',
    scan_bag: 'Records a bag and its lot against this handover, with the weight, who issued it and who received it.',
    complete_issue: 'Closes the handover and recomputes what is still to issue on the request.',
    cancel_issue: 'Calls off the handover before it is completed.',
    return_to_store: 'Takes unused material back from Production/WIP into the Raw Material Store.',
};

export function transitionLabel(transition: MaterialFlowTransition): string {
    return TRANSITION_LABEL[transition];
}

/* ------------------------------------------------------------------ *
 * Refusals
 * ------------------------------------------------------------------ */

export type MaterialFlowRefusal =
    | 'machine_on_common_input'
    | 'machine_unknown_for_material'
    | 'issue_refused'
    | 'return_exceeds_issued'
    | 'quantity_not_positive'
    | 'request_not_submitted'
    | 'request_closed'
    | 'issue_already_completed'
    | 'issue_cancelled'
    | 'received_by_missing'
    | 'reason_missing'
    | 'bag_not_found'
    | 'bag_scanning_unavailable'
    | 'bag_on_qc_hold'
    | 'bag_to_batch_claim';

export const REFUSAL_MESSAGE: Record<MaterialFlowRefusal, string> = {
    machine_on_common_input:
        'This material is drawn from the factory’s one common loading point, which is piped to every machine, so no machine or area can be named on the request (FC-01).',
    machine_unknown_for_material:
        'The ERP has not been told whether a machine or area applies to this material, so none is named here. Nothing is guessed.',
    issue_refused: 'The store could not hand this over. The reason the server gave is shown above it — read that before trying again.',
    return_exceeds_issued: 'More than is standing in Production/WIP from this handover. A return can never give back more than went out and stayed out.',
    quantity_not_positive: 'Enter a quantity greater than zero.',
    request_not_submitted: 'This request has not been sent to the store yet, so nothing can be issued against it.',
    request_closed: 'This request is closed. Nothing further can be issued against it.',
    issue_already_completed: 'This handover is already complete. Start a new issue for anything more.',
    issue_cancelled: 'This handover was cancelled. Start a new issue if material is still needed.',
    received_by_missing: 'Name the person on the production side who is receiving the material. A handover has two names on it.',
    reason_missing: 'Give the reason. It is kept with the record.',
    bag_scanning_unavailable:
        'Bag scanning is not switched on for this instance, so a barcode resolves to nothing. The handover can still be recorded by quantity; ask an administrator about bag traceability.',
    bag_not_found: 'No bag with that barcode. Check the label, or use the manual entry the store keeps for unlabelled bags.',
    bag_on_qc_hold: 'This bag is on QC hold and cannot be issued to production until quality releases it.',
    bag_to_batch_claim:
        'The ERP does not record which bag a batch used. Bags are traced as far as the issue to production; what a batch used is calculated (FC-01).',
};

export function refusalMessage(refusal: MaterialFlowRefusal): string {
    return REFUSAL_MESSAGE[refusal];
}

/* ------------------------------------------------------------------ *
 * Quantities
 * ------------------------------------------------------------------ */

/**
 * "1250.5000" → "1250.5"; null/undefined/unparseable → "—".
 *
 * UNKNOWN IS NEVER ZERO. A remaining quantity the server did not state,
 * shown as 0, reads as "there is nothing left to issue" and would stop a
 * shift for no reason — so it is shown as an em dash and nothing is
 * computed from it.
 */
export function formatQuantity(quantity: string | number | null | undefined, uom?: string | null): string {
    if (quantity === null || quantity === undefined || quantity === '') return '—';
    const parsed = typeof quantity === 'number' ? quantity : parseFloat(quantity);
    if (Number.isNaN(parsed)) return '—';
    const trimmed = String(parseFloat(parsed.toFixed(4)));
    return uom ? `${trimmed} ${uom}` : trimmed;
}

/** The four quantities a request line carries, as the server states them. */
export interface RequestLineQuantities {
    requested: string | number | null | undefined;
    /** Total issued to production against this line — issued, NOT consumed. */
    issued: string | number | null | undefined;
    /** Still to issue, as the server recomputed it. Never derived here. */
    remaining: string | number | null | undefined;
    /** Handed back to the store unused. */
    returned: string | number | null | undefined;
}

export interface QuantityCell {
    key: 'requested' | 'issued' | 'remaining' | 'returned';
    label: string;
    help: string;
    value: string;
}

/**
 * A request line read as four separately named quantities, in the order the
 * floor reads them. Nothing is subtracted here: `remaining` is the server's
 * figure or an em dash, because a remaining quantity computed in the browser
 * from two possibly-unknown numbers is a made-up number on a factory screen.
 */
export function describeRequestLine(quantities: RequestLineQuantities, uom?: string | null): QuantityCell[] {
    return [
        {
            key: 'requested',
            label: 'Requested',
            help: 'What production asked the store for.',
            value: formatQuantity(quantities.requested, uom),
        },
        {
            key: 'issued',
            label: STATE_COLUMN_LABEL.issued_to_production,
            help: STATE_HELP.issued_to_production,
            value: formatQuantity(quantities.issued, uom),
        },
        {
            key: 'remaining',
            label: 'Still to issue',
            help: 'What the store has not issued yet against this line.',
            value: formatQuantity(quantities.remaining, uom),
        },
        {
            key: 'returned',
            label: STATE_COLUMN_LABEL.returned_to_store,
            help: STATE_HELP.returned_to_store,
            value: formatQuantity(quantities.returned, uom),
        },
    ];
}

/* ------------------------------------------------------------------ *
 * The machine/area field (FC-01, Q50)
 * ------------------------------------------------------------------ */

export interface MachineFieldDecision {
    show: boolean;
    note: string;
}

/**
 * Whether the request may name a machine or area for this material.
 *
 * A RESIN request carries none: one common loading point, crane-fed and
 * piped to all ten machines (DEC-20260807-006, FC-01). A consumable —
 * film, cartons, tape — does carry one, because there the machine or area
 * is a real physical fact.
 *
 * The caller passes what the SERVER said. `null` means the server did not
 * say, and the answer is then "no machine, and no guess": deciding it here
 * from a name, an SKU or a unit would be inventing a factory classification.
 */
export function machineFieldDecision(machineApplies: boolean | null | undefined): MachineFieldDecision {
    if (machineApplies === true) {
        return { show: true, note: 'Name the machine or area this material is going to.' };
    }
    if (machineApplies === false) {
        return { show: false, note: REFUSAL_MESSAGE.machine_on_common_input };
    }
    return { show: false, note: REFUSAL_MESSAGE.machine_unknown_for_material };
}

/**
 * Does a machine or area apply to a whole request?
 *
 * `true` only when the server says so for EVERY material on it; `false` as
 * soon as one common-input material is on it — one such line makes the whole
 * request a common-input request, because there is one loading point and it
 * belongs to no machine (FC-01, DEC-20260807-006); `null` when the server has
 * not said, which is not a licence to decide it here.
 *
 * Takes the smallest shape it needs, so it stays a pure function of the
 * server's own word and nothing else. Absent entries (a line with no material
 * picked yet) are ignored.
 */
export function machineAppliesToRequest(
    materials: ({ machine_applies: boolean | null } | null | undefined)[],
): boolean | null {
    const named = materials.filter(
        (material): material is { machine_applies: boolean | null } => material !== null && material !== undefined,
    );
    if (named.length === 0) return null;
    if (named.some((material) => material.machine_applies === false)) return false;
    if (named.every((material) => material.machine_applies === true)) return true;
    return null;
}

/* ------------------------------------------------------------------ *
 * The store queue's status filter
 * ------------------------------------------------------------------ */

/** "Still to issue" is these two statuses, as the backend spells the filter. */
export const OPEN_REQUEST_STATUSES: MaterialRequestStatus[] = ['submitted', 'partially_issued'];

/** What the queue's status dropdown offers, in order. */
export type QueueStatusChoice = 'open' | 'all' | MaterialRequestStatus;

/**
 * The `status` filter a dropdown choice means.
 *
 * `undefined` is not "no opinion" — it is ALL REQUESTS. Omitting the status
 * is how the server is asked for everything, and it is the only way the store
 * reaches a request it has already finished.
 *
 * This exists as a pure function because of what it cost when it was inline:
 * a fully issued request leaves the default view, so the storekeeper finished
 * a handover and watched the row vanish, with nothing on screen to say the
 * work still existed. Reported from the floor as "after the approval it went
 * blank — where do we see the history".
 */
export function queueStatusFilter(choice: QueueStatusChoice): MaterialRequestStatus[] | MaterialRequestStatus | undefined {
    if (choice === 'open') return [...OPEN_REQUEST_STATUSES];
    if (choice === 'all') return undefined;

    return choice;
}

/**
 * What an EMPTY queue should say. On the default filter the honest answer is
 * not "nothing here" — it is "nothing is OUTSTANDING, and here is where the
 * finished ones are".
 */
export function queueEmptyText(status: MaterialRequestStatus[] | MaterialRequestStatus | undefined): string {
    return Array.isArray(status)
        ? 'Nothing is still to issue. Requests the store has already finished are under "Fully issued" — or "All requests" to see everything.'
        : 'No requests match these filters.';
}

/**
 * MAY A QUANTITY IN THIS UNIT HAVE A FRACTIONAL PART?
 *
 * The browser-side mirror of the backend's MeasurementType. The server is the
 * authority and refuses a fractional count outright — this only keeps the
 * storekeeper from typing a figure that is going to be rejected, and from
 * believing 12.5 trays is a thing the store can hand over.
 *
 * Unknown units permit fractions, exactly as the backend does: refusing a
 * decimal on a unit nobody has classified would block real work on a guess.
 * Kept deliberately narrow — the several older kg-detecting copies elsewhere
 * in this app are pre-existing and are not converged here on purpose.
 */
// MIRRORED VERBATIM from MeasurementType::COUNT_UNITS, dotted spellings and
// all. The first attempt normalised by stripping one trailing dot instead,
// which disagreed with the server on `piece.`, `pieces.`, `each.` and `ea.` —
// and disagreed in the DANGEROUS direction: the browser refusing a figure the
// server would have accepted blocks real work, where the reverse is merely a
// wasted round trip. No live item can spell a unit that way today; matching
// the list exactly is still cheaper than relying on that.
const COUNT_UNITS = ['nos', 'nos.', 'no', 'no.', 'pcs', 'pcs.', 'pc', 'pc.', 'piece', 'pieces', 'each', 'ea'];

export function permitsFractions(uom: string | null | undefined): boolean {
    // PHP's trim() strips " \t\n\r\0\x0B" and nothing else; JS's strips all
    // Unicode whitespace. So `\u00A0nos.` normalised to `nos.` here while the
    // server left it Unknown — the browser refusing a decimal the server would
    // have taken, which is the direction that blocks real work.
    const trimmed = (uom ?? '').replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '');

    return !COUNT_UNITS.includes(trimmed.toLowerCase());
}
