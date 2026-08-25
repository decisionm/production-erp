import dayjs from 'dayjs';
import { describe, expect, it } from 'vitest';
import {
    INCOMING_INSPECTIONS_QUERY_KEY,
    PENDING_INSPECTION_LINES_QUERY_KEY,
} from '@/features/quality/api';
import {
    MAX_QUANTITY,
    PLAIN_DECIMAL,
    addQuantity,
    blankInspectionForm,
    closedInspectionModal,
    compareQuantity,
    formValuesForLine,
    inspectionPayload,
    nextInspectionState,
    serverErrorsFor,
    toScaled,
    todayFactoryDate,
    validateInspection,
    type InspectionFormValues,
} from '@/features/quality/incomingInspection';
import type { PendingInspectionLine } from '@/features/quality/types';

/**
 * THE INCOMING-QC DESK, PINNED WHERE IT CAN BE PINNED.
 *
 * This repo's vitest runs in node with no DOM and no @testing-library, so the
 * page's behaviour is testable only because the behaviour is not IN the page:
 * `incomingInspection.ts` holds the arithmetic, the validation and every
 * modal state transition as pure functions, and `IncomingInspectionsPage.tsx`
 * is the markup that calls them. That is deliberate — see that module's
 * docblock, and ConfigurationReviewPanel.test.tsx for the precedent.
 *
 * Nothing here asserts on source text. Every test calls the real exported
 * function the page calls.
 */

const line = (over: Partial<PendingInspectionLine> = {}): PendingInspectionLine => ({
    id: 41,
    grn_reference: 'GRN-7',
    item: { id: 5, sku: 'RELPET-BG', name: 'Relpet Bottle Grade' },
    received_quantity: '123450.0000',
    uom: 'KGS',
    ...over,
});

describe('exact decimal arithmetic', () => {
    it('mirrors PlainDecimal — the same spellings bcmath takes, and no others', () => {
        // App\Rules\PlainDecimal's own docblock names these as accepted.
        for (const ok of ['.5', '1.', '+5', '0', '123450.0000', '99999999999.9999']) {
            expect(PLAIN_DECIMAL.test(ok), ok).toBe(true);
            expect(toScaled(ok), ok).not.toBeNull();
        }
        // And these are exactly what made bccomp() raise a ValueError → 500.
        for (const bad of ['1e3', '1E3', '5e-2', '0x1A', 'INF', 'NaN', '1,234', '', ' 5']) {
            expect(PLAIN_DECIMAL.test(bad), bad).toBe(false);
            expect(toScaled(bad), bad).toBeNull();
        }
    });

    it('compares at scale 4 without ever going through a float', () => {
        // The reported case. Equality is equality.
        expect(compareQuantity('123450', '123450.0000')).toBe(0);
        expect(compareQuantity('1000000', '1000000.0000')).toBe(0);
        // The smallest step decimal(15,4) can express.
        expect(compareQuantity('123450.0001', '123450.0000')).toBe(1);
        expect(compareQuantity('123449.9999', '123450.0000')).toBe(-1);
        // Where JS numbers stop being able to tell the difference at all:
        // 12345678901.2345 and 12345678901.2346 are distinct here.
        expect(compareQuantity('12345678901.2345', '12345678901.2346')).toBe(-1);
        expect(compareQuantity('99999999999.9999', MAX_QUANTITY)).toBe(1);
    });

    it('adds exactly where 0.1 + 0.2 would not', () => {
        expect(addQuantity('0.1', '0.2')).toBe('0.3000');
        expect(Number('0.1') + Number('0.2')).not.toBe(0.3); // the premise
        expect(addQuantity('123450.0000', '0')).toBe('123450.0000');
        expect(addQuantity('99999999999.9998', '0.0001')).toBe('99999999999.9999');
    });
});

describe('prefill', () => {
    it('fills inspected and accepted with the exact received string and rejected with zero', () => {
        const values = formValuesForLine(line());

        expect(values.inspected_quantity).toBe('123450.0000');
        expect(values.accepted_quantity).toBe('123450.0000');
        expect(values.rejected_quantity).toBe('0');
        expect(values.goods_receipt_note_line_id).toBe(41);
        expect(values.received_quantity).toBe('123450.0000');
        expect(values.notes).toBe('');
    });

    it('preserves an awkward decimal character for character', () => {
        const values = formValuesForLine(line({ received_quantity: '12345678901.2345' }));

        expect(values.inspected_quantity).toBe('12345678901.2345');
        // No Number() anywhere on the path, so the figure reaches the wire as
        // typed. The loss this avoids is on the SERVER side, not here: PHP
        // decodes a JSON number to a float and `(string) 12345678901.2345` is
        // `'12345678901.235'` at PHP's default precision of 14 — which reads
        // as MORE than was received and is refused at exact equality. That
        // premise is pinned in the backend suite
        // (IncomingInspectionPendingQueueTest::
        //  test_a_decimal_string_survives_where_a_json_float_would_not).
        const payload = inspectionPayload(values);
        expect(payload.inspected_quantity).toBe('12345678901.2345');
        expect(typeof payload.inspected_quantity).toBe('string');
    });

    it('defaults the inspection date to today in the app existing convention', () => {
        // dayjs().format('YYYY-MM-DD') — what ShiftSummaryPage, ReportsPage
        // and GoodsReceiptsPage already use. No timezone policy is invented.
        expect(todayFactoryDate()).toBe(dayjs().format('YYYY-MM-DD'));
        expect(formValuesForLine(line()).inspection_date).toBe(dayjs().format('YYYY-MM-DD'));
    });
});

describe('validation mirrors the server contract', () => {
    const values = (over: Partial<InspectionFormValues> = {}): InspectionFormValues => ({
        ...formValuesForLine(line()),
        ...over,
    });

    it('accepts inspecting exactly what was received — 123450 and 1000000', () => {
        expect(validateInspection(values()).valid).toBe(true);
        expect(validateInspection(values()).result).toBe('pass');

        const million = values({
            received_quantity: '1000000.0000',
            inspected_quantity: '1000000.0000',
            accepted_quantity: '1000000.0000',
        });
        expect(validateInspection(million).valid).toBe(true);
    });

    it('refuses the smallest representable overage', () => {
        const over = values({ inspected_quantity: '123450.0001', accepted_quantity: '123450.0001' });
        const result = validateInspection(over);

        expect(result.valid).toBe(false);
        expect(result.errors.inspected_quantity).toContain('Cannot inspect more than');
    });

    it('refuses scientific notation rather than sending it to a 500', () => {
        const sci = validateInspection(values({ inspected_quantity: '1e3', accepted_quantity: '1e3' }));

        expect(sci.valid).toBe(false);
        expect(sci.errors.inspected_quantity).toContain('ordinary number');
    });

    it('holds the decimal(15,4) maximum and refuses one more', () => {
        const max = values({
            received_quantity: MAX_QUANTITY,
            inspected_quantity: MAX_QUANTITY,
            accepted_quantity: MAX_QUANTITY,
        });
        expect(validateInspection(max).valid).toBe(true);

        const over = values({
            received_quantity: '100000000000',
            inspected_quantity: '100000000000',
            accepted_quantity: '100000000000',
        });
        expect(validateInspection(over).errors.inspected_quantity).toBe(`Cannot be more than ${MAX_QUANTITY}.`);
    });

    it('requires accepted + rejected to equal inspected, exactly', () => {
        const short = values({ accepted_quantity: '123449.9999' });
        expect(validateInspection(short).errors.accepted_quantity).toContain('must equal');

        const split = values({ accepted_quantity: '123400.0000', rejected_quantity: '50' });
        expect(validateInspection(split).valid).toBe(true);
        expect(validateInspection(split).result).toBe('partial');

        const allBad = values({ accepted_quantity: '0', rejected_quantity: '123450.0000' });
        expect(validateInspection(allBad).result).toBe('fail');
    });

    it('refuses zero, negatives and a missing date', () => {
        expect(validateInspection(values({ inspected_quantity: '0', accepted_quantity: '0' })).errors.inspected_quantity)
            .toBe('Must be greater than 0.');
        expect(validateInspection(values({ rejected_quantity: '-1' })).errors.rejected_quantity)
            .toBe('Cannot be negative.');
        expect(validateInspection(values({ inspection_date: '' })).errors.inspection_date)
            .toBe('Inspection date is required.');
    });
});

describe('the payload', () => {
    it('sends decimal strings and never the client-side received quantity', () => {
        const payload = inspectionPayload(formValuesForLine(line()));

        expect(payload).toEqual({
            goods_receipt_note_line_id: 41,
            inspected_quantity: '123450.0000',
            accepted_quantity: '123450.0000',
            rejected_quantity: '0',
            inspection_date: dayjs().format('YYYY-MM-DD'),
        });
        expect(payload).not.toHaveProperty('received_quantity');
        for (const value of Object.values(payload)) {
            expect(typeof value === 'string' || typeof value === 'number').toBe(true);
        }
        expect(typeof payload.inspected_quantity).toBe('string');
    });

    it('omits empty notes and keeps real ones', () => {
        const base = formValuesForLine(line());
        expect(inspectionPayload({ ...base, notes: '   ' })).not.toHaveProperty('notes');
        expect(inspectionPayload({ ...base, notes: 'moisture high' }).notes).toBe('moisture high');
    });
});

describe('modal state — row A can never leak into row B', () => {
    const rowA = line({ id: 41, grn_reference: 'GRN-7', received_quantity: '123450.0000' });
    const rowB = line({ id: 42, grn_reference: 'GRN-9', received_quantity: '80.5000', item: { id: 6, sku: 'MB-AMBER', name: 'Amber Masterbatch' } });

    /** Row A opened, edited, and refused by the server — the worst state to leave behind. */
    const dirtyA = () => {
        let state = nextInspectionState(closedInspectionModal(), { type: 'inspect', line: rowA });
        state = nextInspectionState(state, { type: 'change', field: 'inspected_quantity', value: '999999' });
        state = nextInspectionState(state, { type: 'change', field: 'notes', value: 'row A note' });
        return nextInspectionState(state, {
            type: 'failed',
            error: {
                response: {
                    data: {
                        message: 'The given data was invalid.',
                        errors: { inspected_quantity: ['Cannot be more than the received quantity.'] },
                    },
                },
            },
        });
    };

    it('carries the server refusal while row A is still open', () => {
        const state = dirtyA();

        expect(state.serverError?.message).toBe('The given data was invalid.');
        expect(serverErrorsFor(state, 'inspected_quantity')).toEqual(['Cannot be more than the received quantity.']);
    });

    it.each(['cancel', 'close', 'submitted'] as const)('%s leaves the form completely blank', (type) => {
        const state = nextInspectionState(dirtyA(), { type });

        expect(state.line).toBeNull();
        expect(state.serverError).toBeNull();
        expect(state.values).toEqual(blankInspectionForm());
        expect(serverErrorsFor(state, 'inspected_quantity')).toEqual([]);
    });

    it('row A -> cancel -> row B shows row B and nothing of row A', () => {
        const afterCancel = nextInspectionState(dirtyA(), { type: 'cancel' });
        const onB = nextInspectionState(afterCancel, { type: 'inspect', line: rowB });

        expect(onB.line?.id).toBe(42);
        expect(onB.values).toEqual(formValuesForLine(rowB));
        expect(onB.values.inspected_quantity).toBe('80.5000');
        expect(onB.values.received_quantity).toBe('80.5000');
        expect(onB.values.notes).toBe('');
        expect(onB.serverError).toBeNull();
        expect(serverErrorsFor(onB, 'inspected_quantity')).toEqual([]);
        // And row B validates against ROW B's received quantity — 123450 is
        // now far too much, which it would not be if A's figure survived.
        expect(validateInspection({ ...onB.values, inspected_quantity: '123450.0000', accepted_quantity: '123450.0000' }).valid).toBe(false);
    });

    it('switching straight from row A to row B without closing resets just as completely', () => {
        const onB = nextInspectionState(dirtyA(), { type: 'inspect', line: rowB });

        expect(onB.values).toEqual(formValuesForLine(rowB));
        expect(onB.serverError).toBeNull();
    });
});

describe('the queue itself', () => {
    it('handles far more than one page of pending lines with no client-side cap', () => {
        const rows = Array.from({ length: 25 }, (_, index) =>
            line({ id: 100 + index, grn_reference: `GRN-${100 + index}`, received_quantity: `${index + 1}.0000` }));

        // Nothing in this module slices, caps or pages the queue: the server
        // sends all of it and every row is inspectable.
        expect(rows).toHaveLength(25);
        const prefilled = rows.map(formValuesForLine);
        expect(prefilled).toHaveLength(25);
        expect(prefilled[24].inspected_quantity).toBe('25.0000');
        expect(prefilled.every((values) => validateInspection(values).valid)).toBe(true);
        expect(new Set(prefilled.map((v) => v.goods_receipt_note_line_id)).size).toBe(25);
    });

    it('invalidates the very keys the queries read, so an inspected row leaves the queue', () => {
        // A successful submit invalidates both keys in the page; if these
        // drifted apart the row would sit in the queue until a manual reload.
        expect(PENDING_INSPECTION_LINES_QUERY_KEY).toEqual(['quality', 'incoming-inspection-pending-lines']);
        expect(INCOMING_INSPECTIONS_QUERY_KEY).toEqual(['quality', 'incoming-inspections']);
        expect(PENDING_INSPECTION_LINES_QUERY_KEY).not.toEqual(INCOMING_INSPECTIONS_QUERY_KEY);
    });
});
