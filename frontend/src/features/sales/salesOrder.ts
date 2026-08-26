import type { SalesOrder } from '@/features/sales/types';

/**
 * The sales order's EXPECTED DATE — the customer's promise date, typed by
 * hand and owned by whoever types it. It is not a production ETA and
 * nothing derives it; whether the floor can meet it is a separate question
 * this screen does not answer.
 *
 * Every decision the list and the drawer make about that date lives here as
 * plain functions, so it is testable without a DOM (this project's vitest
 * suite is pure logic — there is no jsdom and no component renderer).
 */

/** What a save sends. Absent key = leave alone; explicit null = clear. */
export interface SalesOrderEditPayload {
    expected_date?: string | null;
    notes?: string | null;
}

/** The form behind the Edit action, as the fields read after formatting. */
export interface SalesOrderEditForm {
    /** ISO date, or null when the picker was cleared. */
    expected_date: string | null;
    /** The textarea's text; empty is the same as none. */
    notes: string | null;
}

/**
 * Is Edit offered? The SERVER's `can_edit` and nothing else — the rule
 * (draft or confirmed) lives in SalesOrder::isEditable() on the backend and
 * is never re-derived here, so the button and the refusal cannot drift
 * apart. A row without the flag (an older cached page) hides the action
 * rather than offering a write that would come back 422.
 */
export function canEditSalesOrder(order: Pick<SalesOrder, 'can_edit'> | null | undefined): boolean {
    return order?.can_edit === true;
}

/**
 * The Expected Date cell: the date as the server spelled it, or a dash, and
 * whether to flag it. `is_overdue` is the server's own answer on the
 * factory's calendar (IST) — the browser's clock is never consulted, so a
 * laptop in another timezone reads the same cell as the floor.
 */
export function expectedDateCell(order: Pick<SalesOrder, 'expected_date' | 'is_overdue'>): {
    text: string;
    overdue: boolean;
} {
    return {
        text: order.expected_date ?? '—',
        overdue: order.is_overdue === true && order.expected_date !== null,
    };
}

/**
 * The overdue badge, or null when there is nothing to flag. The word
 * "Overdue" is the indicator; the colour only repeats it, so the cell still
 * says what it means to a screen reader or a colour-blind reader. `label`
 * is the accessible name — it carries the date the badge is about, which
 * the bare word does not.
 */
export function overdueBadge(
    order: Pick<SalesOrder, 'expected_date' | 'is_overdue'>,
): { text: string; label: string } | null {
    const cell = expectedDateCell(order);
    if (!cell.overdue) {
        return null;
    }

    return { text: 'Overdue', label: `Overdue — expected ${cell.text}` };
}

/** '' and whitespace-only are the same fact as "no notes": null. */
function normaliseNotes(notes: string | null | undefined): string | null {
    const trimmed = (notes ?? '').trim();

    return trimmed === '' ? null : trimmed;
}

/**
 * The body a save sends: ONLY the fields the form actually changed.
 *
 * An untouched field is left OUT of the payload rather than echoed back —
 * the endpoint reads an absent key as "leave it alone", so a note typed
 * elsewhere between the drawer opening and Save is not clobbered by a stale
 * copy of itself. A cleared date is sent as an explicit null, which is a
 * different request from an absent key and clears the date.
 *
 * An empty object means there is nothing to save (Save stays disabled).
 */
export function salesOrderEditPayload(
    order: Pick<SalesOrder, 'expected_date' | 'notes'>,
    form: SalesOrderEditForm,
): SalesOrderEditPayload {
    const payload: SalesOrderEditPayload = {};

    const date = form.expected_date === null || form.expected_date === '' ? null : form.expected_date;
    if (date !== (order.expected_date ?? null)) {
        payload.expected_date = date;
    }

    const notes = normaliseNotes(form.notes);
    if (notes !== normaliseNotes(order.notes)) {
        payload.notes = notes;
    }

    return payload;
}

/** Nothing typed differs from what is stored — Save has nothing to do. */
export function hasSalesOrderEdits(payload: SalesOrderEditPayload): boolean {
    return Object.keys(payload).length > 0;
}
