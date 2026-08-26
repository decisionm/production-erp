import { describe, expect, it } from 'vitest';
import { cannotEstimateReason, planningBasisLine, planningEtaCell } from './planning';
import type { FulfilmentPlanningBasis, FulfilmentPlanningRow } from './types';

/**
 * WHAT THE PLANNING DASHBOARD SAYS WHEN IT CANNOT SAY A DATE.
 *
 * The load-bearing claim is a NEGATIVE one: an unestimable line gets no date
 * and no caveat-date either (S12). The cascade is the reason — once anything
 * ahead in the queue has no standard, nothing behind it can be dated, and a
 * "probably Friday" printed on those rows would be a factory number nobody
 * computed. So the row below carrying BOTH a date and `cannot_estimate` is not
 * a hypothetical: it is the shape a future server bug would take, and the
 * honest half has to win.
 *
 * The basis footer is pinned as FIGURES. "Numbers, not prose" is the brief's
 * own wording and the floor's own preference; a paragraph is also where an
 * unverified claim hides.
 */

function planningRow(overrides: Partial<FulfilmentPlanningRow> = {}): FulfilmentPlanningRow {
    return {
        line_id: 12,
        item: { id: 7, sku: 'BTL-1L', name: '1 Litre Bottle' },
        customer: { id: 4, name: 'Aqua Foods' },
        needed: '5000.0000',
        free: '0.0000',
        queued_ahead: 0,
        capacity_per_shift: 2500,
        shifts_needed: 2,
        estimated_ready_date: '2026-08-28',
        cannot_estimate: false,
        reason: null,
        ...overrides,
    };
}

const basis: FulfilmentPlanningBasis = {
    shifts_per_day: 3,
    parallel_lines: 1,
    shift_hours: '8.0000',
    timezone: 'Asia/Kolkata',
    source: 'active_shifts',
};

describe('one planning row s ETA cell', () => {
    it('shows the server s date and the shifts behind it', () => {
        const cell = planningEtaCell(planningRow());

        expect(cell.dated).toBe(true);
        expect(cell.date).toBe('2026-08-28');
        expect(cell.shifts).toBe('2 shifts');
        expect(cell.refusal).toBeNull();
    });

    it('says "1 shift", not "1 shifts"', () => {
        expect(planningEtaCell(planningRow({ shifts_needed: 1 })).shifts).toBe('1 shift');
    });

    it('prints the refusal, and no date, when the line cannot be estimated', () => {
        const cell = planningEtaCell(
            planningRow({
                cannot_estimate: true,
                reason: 'no_production_standard',
                estimated_ready_date: null,
                shifts_needed: null,
                capacity_per_shift: null,
            }),
        );

        expect(cell.dated).toBe(false);
        expect(cell.date).toBeNull();
        expect(cell.refusal).toBe('cannot estimate — no standard for this product');
        expect(cell.shifts).toBeNull();
    });

    it('trusts `cannot_estimate` over a date that should not be there', () => {
        // S12 says an unestimable line gets no caveat-date. A row carrying
        // both is a server that has gone wrong, and the honest half wins.
        const cell = planningEtaCell(
            planningRow({ cannot_estimate: true, reason: 'items_ahead_without_standard', estimated_ready_date: '2026-09-01' }),
        );

        expect(cell.dated).toBe(false);
        expect(cell.date).toBeNull();
        expect(cell.refusal).toBe('cannot estimate — a job ahead of it has no standard');
    });
});

describe('the reason for having no date', () => {
    it.each([
        ['no_production_standard', 'no standard for this product'],
        ['items_ahead_without_standard', 'a job ahead of it has no standard'],
        ['no_active_shift_hours', 'no active shift carries a clock'],
    ])('says %s in words', (token, words) => {
        expect(cannotEstimateReason(token)).toBe(words);
    });

    it('passes an unknown reason through unchanged rather than blanking it', () => {
        // Blanking it would make an unestimable row look like an estimable one
        // that merely has no date yet.
        expect(cannotEstimateReason('some_future_reason')).toBe('some_future_reason');
    });

    it('still says something when the server sent no reason at all', () => {
        expect(cannotEstimateReason(null)).toBe('cannot estimate');
    });
});

describe('the basis footer', () => {
    it('is figures, not prose', () => {
        expect(planningBasisLine(basis)).toBe('3 shifts/day · 8.0000 h/shift · 1 line · Asia/Kolkata');
    });

    it('says the factory has no active shifts rather than dressing it up as zero', () => {
        // "0 shifts/day" reads as a factory that ran nothing today. The truth
        // is that nobody has told the ERP its shifts, which is why every row
        // above says it cannot estimate.
        expect(planningBasisLine({ ...basis, shifts_per_day: 0, shift_hours: null, source: 'no_active_shifts' })).toBe(
            'No active shifts · Asia/Kolkata',
        );
    });

    it('admits an unknown shift length instead of printing a made-up one', () => {
        expect(planningBasisLine({ ...basis, shift_hours: null })).toContain('shift hours —');
    });

    it('counts parallel lines in the plural the factory actually runs', () => {
        expect(planningBasisLine({ ...basis, parallel_lines: 2 })).toContain('2 lines');
    });
});
