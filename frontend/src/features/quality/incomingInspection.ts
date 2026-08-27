import dayjs from 'dayjs';
import { apiErrorParts, type ApiErrorParts } from '@/lib/apiError';
import type { PendingInspectionLine } from './types';

/**
 * THE INCOMING-QC DESK'S ARITHMETIC AND ITS MODAL STATE — as pure functions.
 *
 * Everything the Incoming Quality page decides lives here rather than inside
 * the component, for two reasons that are the whole point of this branch:
 *
 * 1. NO FLOATS EVER TOUCH A FACTORY QUANTITY. The page used to compare
 *    `Math.abs(a + r - i) < 0.0001` on JS numbers and to send
 *    `inspected_quantity: number`. The conversion that costs is on the
 *    SERVER side: PHP decodes a JSON number to a float, and `(string)` on a
 *    float renders at PHP's default precision of 14, so `12345678901.2345`
 *    reaches bcmath as `12345678901.235` — a larger figure than the operator
 *    typed. (That much is measured, in the backend suite. So is the
 *    consequence, on the unconditional MySQL CI leg where the column holds
 *    the received side exactly:
 *    `test_a_json_number_is_refused_against_a_column_that_stored_the_figure_exactly`
 *    sees a JSON number refused for being MORE than itself, and the decimal
 *    string the page now sends accepted on the same row.) Sending the
 *    operator's decimal STRING removes the conversion entirely and the
 *    question with it. The comparisons below are exact too, done on scaled
 *    BigInts at the column's own scale of 4.
 *
 * 2. RESET HAS TO BE TOTAL, AND PROVABLE. "Row A's numbers appeared under
 *    row B" is a state-transition bug, and a state-transition bug argued
 *    from the component's JSX is argued, not proved. `nextInspectionState`
 *    is the ONE place the modal's state changes, so every trigger — open,
 *    a different row picked, cancel, close, a successful submit — is one
 *    pinned transition in `incomingInspection.test.ts` rather than five
 *    hand-written `reset()` calls that have to each be remembered.
 *
 *    ONE THING IS OUTSIDE THIS REDUCER AND SHOULD STAY VISIBLE: TanStack
 *    Query's own mutation state. `mutation.reset()` cannot live here (this
 *    module is framework-free by design), so the page funnels every open and
 *    every dismissal through its two helpers — `inspect()` and `dismiss()` —
 *    which call it alongside the transition. A new close path that bypasses
 *    those two would leave a stale mutation error behind while the tests
 *    below still pass. There are exactly two such call sites; keep it that
 *    way.
 *
 * This repo's vitest runs in node with no DOM, so pure-and-tested is also
 * the only shape that CAN be tested here (see ConfigurationReviewPanel.test.tsx).
 */

// ---------------------------------------------------------------------------
// The server contract, mirrored — never widened
// ---------------------------------------------------------------------------

/**
 * `App\Rules\PlainDecimal::PATTERN`, character for character. The point of
 * that class is that there is no second definition of "a number a storekeeper
 * writes"; this is its client-side mirror, and it must not drift — a looser
 * pattern here sends the server something it will 422, a tighter one refuses
 * a spelling the server accepts.
 */
export const PLAIN_DECIMAL = /^[+-]?(\d+(\.\d*)?|\.\d+)$/;

/**
 * `decimal(15, 4)` — eleven integer digits. The same ceiling
 * `StoreIncomingInspectionRequest` spells as `max:99999999999`, read off the
 * migrations, not guessed.
 */
export const MAX_QUANTITY = '99999999999';

/** The scale every quantity comparison in this module is done at (bccomp(…, 4)). */
export const QUANTITY_SCALE = 4;

export type InspectionResultPreview = 'pass' | 'fail' | 'partial';

// ---------------------------------------------------------------------------
// Exact decimal arithmetic — no Number(), anywhere
// ---------------------------------------------------------------------------

/**
 * A plain decimal string as an integer count of 1/10^scale, or null when the
 * string is not one. Digits beyond the scale are TRUNCATED, not rounded,
 * which is what bcmath does at a fixed scale — mirroring it here keeps the
 * two sides from disagreeing about a figure neither column can store.
 */
export function toScaled(value: string, scale: number = QUANTITY_SCALE): bigint | null {
    if (typeof value !== 'string' || !PLAIN_DECIMAL.test(value)) return null;

    const negative = value.startsWith('-');
    const unsigned = value.replace(/^[+-]/, '');
    const [whole = '', fraction = ''] = unsigned.split('.');
    const padded = (fraction + '0'.repeat(scale)).slice(0, scale);
    const magnitude = BigInt((whole === '' ? '0' : whole) + padded);

    return negative ? -magnitude : magnitude;
}

/** bccomp(a, b, 4) — -1, 0, 1, or null when either side is not a plain decimal. */
export function compareQuantity(a: string, b: string): number | null {
    const left = toScaled(a);
    const right = toScaled(b);
    if (left === null || right === null) return null;

    return left === right ? 0 : left < right ? -1 : 1;
}

/** bcadd(a, b, 4) rendered back as a decimal string; null on a malformed side. */
export function addQuantity(a: string, b: string): string | null {
    const left = toScaled(a);
    const right = toScaled(b);
    if (left === null || right === null) return null;

    const total = left + right;
    const negative = total < 0n;
    const digits = (negative ? -total : total).toString().padStart(QUANTITY_SCALE + 1, '0');
    const whole = digits.slice(0, digits.length - QUANTITY_SCALE);
    const fraction = digits.slice(digits.length - QUANTITY_SCALE);

    return `${negative ? '-' : ''}${whole}.${fraction}`;
}

// ---------------------------------------------------------------------------
// The form
// ---------------------------------------------------------------------------

export interface InspectionFormValues {
    /** null only in the blank form — the modal never opens without a line. */
    goods_receipt_note_line_id: number | null;
    /**
     * The arrival's received quantity, carried IN the form rather than read
     * from a closure. It makes `validateInspection` a pure function of its
     * argument, so the "inspected <= received" check can never validate row
     * B's figure against row A's still-captured quantity.
     */
    received_quantity: string;
    inspected_quantity: string;
    accepted_quantity: string;
    rejected_quantity: string;
    inspection_date: string;
    notes: string;
}

/**
 * Today, in the convention this app already uses everywhere it defaults a
 * date input — `dayjs().format('YYYY-MM-DD')`, the browser's own wall clock
 * (ShiftSummaryPage, ReportsPage, GoodsReceiptsPage's `nowReceivedAt`). The
 * backend stores the wall-clock date it is given and converts nothing (see
 * lib/datetime.ts), so on a factory machine this is the factory's date. NO
 * TIMEZONE POLICY IS INVENTED HERE: introducing an explicit IST conversion on
 * this one form would make it disagree with every other date field in the app.
 */
export function todayFactoryDate(): string {
    return dayjs().format('YYYY-MM-DD');
}

/** The form with nothing in it — what cancel, close and a finished submit leave behind. */
export function blankInspectionForm(): InspectionFormValues {
    return {
        goods_receipt_note_line_id: null,
        received_quantity: '',
        inspected_quantity: '',
        accepted_quantity: '',
        rejected_quantity: '',
        inspection_date: '',
        notes: '',
    };
}

/**
 * The prefill for one pending line.
 *
 * The received quantity is copied as the STRING the server sent
 * ("123450.0000") into both inspected and accepted — the overwhelmingly
 * common case is "it all arrived and it is all good", and the figure has to
 * be byte-identical to the one the server will compare against, not a
 * re-rendered float. Rejected starts at exact zero.
 */
export function formValuesForLine(line: PendingInspectionLine): InspectionFormValues {
    return {
        goods_receipt_note_line_id: line.id,
        received_quantity: line.received_quantity,
        inspected_quantity: line.received_quantity,
        accepted_quantity: line.received_quantity,
        rejected_quantity: '0',
        inspection_date: todayFactoryDate(),
        notes: '',
    };
}

// ---------------------------------------------------------------------------
// Validation — the server's own refusals, said at the keyboard first
// ---------------------------------------------------------------------------

export type InspectionField = Exclude<keyof InspectionFormValues, 'goods_receipt_note_line_id' | 'received_quantity'>;

export interface InspectionValidation {
    /** Per-field, so the message renders against the input it belongs to. */
    errors: Partial<Record<InspectionField, string>>;
    valid: boolean;
    /** The result the server will derive, once the figures balance; null before. */
    result: InspectionResultPreview | null;
}

const MALFORMED = 'Enter an ordinary number — digits, with an optional decimal point.';

/**
 * Every rule here exists on the server already; none is invented.
 *
 *   PlainDecimal + max          StoreIncomingInspectionRequest
 *   gt:0 / min:0                StoreIncomingInspectionRequest
 *   inspected <= received       InvalidInspectionQuantityException::exceedsReceived
 *   accepted + rejected == inspected
 *                               InvalidInspectionQuantityException::mismatch
 *
 * The server stays the authority — this only means the operator finds out at
 * the keyboard instead of through a 422 after the modal has been filled in.
 */
export function validateInspection(values: InspectionFormValues): InspectionValidation {
    const errors: Partial<Record<InspectionField, string>> = {};

    const quantities: InspectionField[] = ['inspected_quantity', 'accepted_quantity', 'rejected_quantity'];
    for (const field of quantities) {
        const scaled = toScaled(values[field]);
        if (scaled === null) {
            errors[field] = MALFORMED;
            continue;
        }
        if (compareQuantity(values[field], MAX_QUANTITY) === 1) {
            errors[field] = `Cannot be more than ${MAX_QUANTITY}.`;
            continue;
        }
        if (scaled < 0n) errors[field] = 'Cannot be negative.';
    }

    if (errors.inspected_quantity === undefined && toScaled(values.inspected_quantity) === 0n) {
        errors.inspected_quantity = 'Must be greater than 0.';
    }

    // inspected <= received, exactly — equality is the ordinary case and must
    // never be refused (the whole point of the scaled-integer compare).
    if (errors.inspected_quantity === undefined && compareQuantity(values.inspected_quantity, values.received_quantity) === 1) {
        errors.inspected_quantity = `Cannot inspect more than the ${values.received_quantity} received on this line.`;
    }

    let result: InspectionResultPreview | null = null;
    if (errors.inspected_quantity === undefined && errors.accepted_quantity === undefined && errors.rejected_quantity === undefined) {
        const total = addQuantity(values.accepted_quantity, values.rejected_quantity);
        if (total === null || compareQuantity(total, values.inspected_quantity) !== 0) {
            errors.accepted_quantity = 'Accepted plus rejected must equal the inspected quantity.';
        } else {
            result = compareQuantity(values.rejected_quantity, '0') === 0
                ? 'pass'
                : compareQuantity(values.accepted_quantity, '0') === 0
                    ? 'fail'
                    : 'partial';
        }
    }

    if (values.inspection_date === '') errors.inspection_date = 'Inspection date is required.';

    return { errors, valid: Object.keys(errors).length === 0, result };
}

// ---------------------------------------------------------------------------
// What actually goes on the wire
// ---------------------------------------------------------------------------

export interface CreateIncomingInspectionPayload {
    goods_receipt_note_line_id: number;
    /** STRINGS. A JSON number would be a double, and a double loses the tail. */
    inspected_quantity: string;
    accepted_quantity: string;
    rejected_quantity: string;
    inspection_date: string;
    notes?: string;
}

/**
 * The form as the API takes it. `received_quantity` is a client-side crutch
 * and is deliberately NOT sent — the server reads the received quantity off
 * the GRN line itself, and a client-supplied copy would be a figure a payload
 * could disagree with.
 */
export function inspectionPayload(values: InspectionFormValues): CreateIncomingInspectionPayload {
    if (values.goods_receipt_note_line_id === null) {
        throw new Error('inspectionPayload: no arrival line selected');
    }

    return {
        goods_receipt_note_line_id: values.goods_receipt_note_line_id,
        inspected_quantity: values.inspected_quantity,
        accepted_quantity: values.accepted_quantity,
        rejected_quantity: values.rejected_quantity,
        inspection_date: values.inspection_date,
        ...(values.notes.trim() === '' ? {} : { notes: values.notes }),
    };
}

// ---------------------------------------------------------------------------
// The modal's state, and every way it changes
// ---------------------------------------------------------------------------

export interface InspectionModalState {
    /** The row being inspected; null means the modal is closed. */
    line: PendingInspectionLine | null;
    values: InspectionFormValues;
    /** The last refusal the SERVER gave, keyed by its own field names. */
    serverError: ApiErrorParts | null;
}

export type InspectionModalEvent =
    | { type: 'inspect'; line: PendingInspectionLine }
    | { type: 'cancel' }
    | { type: 'close' }
    | { type: 'submitted' }
    | { type: 'failed'; error: unknown }
    | { type: 'change'; field: InspectionField; value: string };

export function closedInspectionModal(): InspectionModalState {
    return { line: null, values: blankInspectionForm(), serverError: null };
}

/**
 * THE ONLY WAY THIS MODAL'S STATE CHANGES.
 *
 * `inspect` is both "open" and "a different row was picked" — there is no
 * separate open path that could forget to reset, which is exactly how row A's
 * figures reached row B. Every closing event returns the blank form AND drops
 * the server's errors: `reset()`-style value clearing alone would leave the
 * previous row's 422 messages hanging under a fresh set of inputs.
 */
export function nextInspectionState(
    state: InspectionModalState,
    event: InspectionModalEvent,
): InspectionModalState {
    switch (event.type) {
        case 'inspect':
            return { line: event.line, values: formValuesForLine(event.line), serverError: null };
        case 'cancel':
        case 'close':
        case 'submitted':
            return closedInspectionModal();
        case 'failed':
            return { ...state, serverError: apiErrorParts(event.error, 'Could not record the inspection.') };
        case 'change':
            return { ...state, values: { ...state.values, [event.field]: event.value } };
    }
}

/**
 * The server's messages for one input, if it named that input. A
 * DomainException (the two quantity refusals) carries only a sentence and no
 * field key, so those surface as the form-level message instead — which is
 * why the page renders both and not just this.
 */
export function serverErrorsFor(state: InspectionModalState, field: InspectionField): string[] {
    return state.serverError?.fields.find((f) => f.field === field)?.messages ?? [];
}
