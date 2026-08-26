import { describe, expect, it } from 'vitest';
import { groupQueueByProduct, sumQuantities } from '@/features/production/queueGroups';
import type { ProductionQueuePlanning, ProductionQueueRow } from '@/features/production/types';

const planning = (over: Partial<ProductionQueuePlanning> = {}): ProductionQueuePlanning => ({
    free: '0.0000',
    queued_ahead: 0,
    capacity_per_shift: 10000,
    shifts_needed: 2,
    estimated_ready_date: '2026-09-01',
    cannot_estimate: false,
    reason: null,
    ...over,
});

let nextId = 1;

const row = (over: Partial<ProductionQueueRow> = {}): ProductionQueueRow => {
    const id = over.id ?? nextId++;

    return {
        id,
        request_number: `PR-${id}`,
        priority: id,
        status: 'queued',
        item: { id: 7, sku: 'BTL-500', name: '500ml PET Bottle' },
        quantity: '1000.0000',
        sales_order_line_id: id,
        sales_order: { id, document_number: `SO-${id}`, customer_name: 'Aqua Traders' },
        requested_by: null,
        requested_at: null,
        cancelled_reason: null,
        can: { start: true, cancel: true, reorder: true },
        planning: planning(),
        ...over,
    };
};

describe('sumQuantities', () => {
    it('adds 4dp decimal strings without float drift', () => {
        // 0.1 + 0.2 through parseFloat is 0.30000000000000004; a supervisor
        // must never read that off a factory screen.
        expect(sumQuantities(['0.1000', '0.2000'])).toBe('0.3000');
        expect(sumQuantities(['20000.0000', '5500.5000'])).toBe('25500.5000');
    });

    it('ignores values it cannot read rather than poisoning the total with NaN', () => {
        expect(sumQuantities(['100.0000', null, undefined, ''])).toBe('100.0000');
        expect(sumQuantities([])).toBe('0.0000');
    });
});

describe('groupQueueByProduct', () => {
    it('keeps the server order — of the groups, and inside them', () => {
        const bottle = row({ id: 1, priority: 1 });
        const jar = row({ id: 2, priority: 2, item: { id: 9, sku: 'JAR-1L', name: '1L Jar' } });
        const bottleAgain = row({ id: 3, priority: 3 });

        const groups = groupQueueByProduct([bottle, jar, bottleAgain]);

        // The bottle group leads because ITS first request does — grouping
        // must not re-sort the thing the reorder buttons write.
        expect(groups.map((group) => group.key)).toEqual(['item-7', 'item-9']);
        expect(groups[0].rows.map((r) => r.id)).toEqual([1, 3]);
        expect(groups[0].priority).toBe(1);
    });

    it('sums the quantities across customers', () => {
        const groups = groupQueueByProduct([
            row({ quantity: '20000.0000' }),
            row({ quantity: '5000.0000' }),
        ]);

        expect(groups[0].quantity).toBe('25000.0000');
        expect(groups[0].rows).toHaveLength(2);
    });

    it('takes free stock ONCE and never sums it', () => {
        // free is ITEM-level: the same 800 bottles on every row for this
        // product. Summed, the screen would claim the store holds 2400.
        const groups = groupQueueByProduct([
            row({ planning: planning({ free: '800.0000' }) }),
            row({ planning: planning({ free: '800.0000' }) }),
            row({ planning: planning({ free: '800.0000' }) }),
        ]);

        expect(groups[0].free).toBe('800.0000');
    });

    it('cascades cannot_estimate to the whole group', () => {
        // S12: one undatable job holds the line for an unknown time, so the
        // datable one behind it is not datable either — the group takes the
        // refusal rather than the date of the child that happened to have one.
        const groups = groupQueueByProduct([
            row({ planning: planning({ estimated_ready_date: '2026-09-01' }) }),
            row({ planning: planning({ cannot_estimate: true, estimated_ready_date: null, reason: 'no_production_standard' }) }),
        ]);

        expect(groups[0].cannot_estimate).toBe(true);
        expect(groups[0].estimated_ready_date).toBeNull();
        expect(groups[0].reason).toBe('no_production_standard');
    });

    it('dates a group by its LAST job, not its first', () => {
        const groups = groupQueueByProduct([
            row({ planning: planning({ estimated_ready_date: '2026-09-01' }) }),
            row({ planning: planning({ estimated_ready_date: '2026-09-08' }) }),
        ]);

        // The product is not finished until every order for it is.
        expect(groups[0].estimated_ready_date).toBe('2026-09-08');
        expect(groups[0].cannot_estimate).toBe(false);
    });

    it('says NOTHING about dates when the caller may not read the planning block', () => {
        // The floor-visibility owner question: the block is absent, not null. "Not yours to read" must never
        // be rendered as the factory refusing to estimate.
        const groups = groupQueueByProduct([row({ planning: undefined }), row({ planning: undefined })]);

        expect(groups[0].planned).toBe(false);
        expect(groups[0].cannot_estimate).toBe(false);
        expect(groups[0].estimated_ready_date).toBeNull();
        expect(groups[0].free).toBeUndefined();
        expect('free' in groups[0]).toBe(false);
    });

    it('never folds two unknown products into one setup', () => {
        const groups = groupQueueByProduct([row({ id: 11, item: null }), row({ id: 12, item: null })]);

        expect(groups).toHaveLength(2);
        expect(groups.map((group) => group.key)).toEqual(['request-11', 'request-12']);
    });
});
