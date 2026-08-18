import { describe, expect, it } from 'vitest';
import type { Paginated } from '@/lib/types';
import {
    CORRECTION_READ_MAX_PAGES,
    CORRECTION_READ_PER_PAGE,
    correctionLists,
    walkEntryPages,
} from './correctionReads';
import type { ShiftProductionEntry } from './types';

/**
 * A list-resource entry with only the keys the two predicates read
 * (status · batch_status · quality.checked · correction.awaiting_correction)
 * — the cast keeps the fixture honest about being a partial wire shape.
 */
const entry = (id: number, overrides: Record<string, unknown> = {}): ShiftProductionEntry =>
    ({
        id,
        batch_number: `B-${id}`,
        production_date: '2026-08-16',
        status: 'pending',
        batch_status: 'completed',
        quality: { checked: false },
        correction: { awaiting_correction: false, latest_return_reason: null, returns: [], amendments: [] },
        ...overrides,
    }) as unknown as ShiftProductionEntry;

const sentBack = (id: number, overrides: Record<string, unknown> = {}): ShiftProductionEntry =>
    entry(id, {
        correction: { awaiting_correction: true, latest_return_reason: 'Weight looks wrong', returns: [{}], amendments: [] },
        ...overrides,
    });

const page = (
    rows: ShiftProductionEntry[],
    meta: Partial<Paginated<ShiftProductionEntry>['meta']> = {},
): Paginated<ShiftProductionEntry> => ({
    data: rows,
    meta: { current_page: 1, last_page: 1, per_page: CORRECTION_READ_PER_PAGE, total: rows.length, ...meta },
});

describe('walkEntryPages', () => {
    it('reads one page when the server says there is one — the common case costs one request', async () => {
        const calls: number[] = [];
        const walk = await walkEntryPages(async (p) => {
            calls.push(p);
            return page([entry(1), entry(2)]);
        });
        expect(calls).toEqual([1]);
        expect(walk.entries.map((e) => e.id)).toEqual([1, 2]);
        expect(walk.truncated).toBe(false);
    });

    it('walks to meta.last_page and concatenates in server order', async () => {
        const calls: number[] = [];
        const walk = await walkEntryPages(async (p) => {
            calls.push(p);
            return page([entry(p * 10), entry(p * 10 + 1)], { current_page: p, last_page: 3 });
        });
        expect(calls).toEqual([1, 2, 3]);
        expect(walk.entries.map((e) => e.id)).toEqual([10, 11, 20, 21, 30, 31]);
        expect(walk.truncated).toBe(false);
    });

    it('stops at the page cap and says so — a bound, not a verdict', async () => {
        const calls: number[] = [];
        const walk = await walkEntryPages(async (p) => {
            calls.push(p);
            return page([entry(p)], { current_page: p, last_page: 40 });
        }, 3);
        expect(calls).toEqual([1, 2, 3]);
        expect(walk.entries.map((e) => e.id)).toEqual([1, 2, 3]);
        expect(walk.truncated).toBe(true);
    });

    it('a malformed page (no meta, no data) ends the walk after one request instead of spinning', async () => {
        const calls: number[] = [];
        const walk = await walkEntryPages(async (p) => {
            calls.push(p);
            return undefined;
        });
        expect(calls).toEqual([1]);
        expect(walk.entries).toEqual([]);
        expect(walk.truncated).toBe(false);
    });

    it('the default bound is the same 500 rows the old status=pending walk carried (25 × 20 = 5 × 100)', () => {
        expect(CORRECTION_READ_PER_PAGE * CORRECTION_READ_MAX_PAGES).toBe(500);
    });
});

describe('correctionLists', () => {
    it('undefined reads (still loading, or 403 for a login without production.view) derive two empty lists', () => {
        const lists = correctionLists({ awaiting: undefined, correctable: undefined, completedToday: [] });
        expect(lists.awaitingCorrection).toEqual([]);
        expect(lists.correctableEarlier).toEqual([]);
    });

    it('awaitingCorrection is the server list, order kept, and the parity guard drops a row the resource does not flag', () => {
        const rows = [sentBack(3), entry(4), sentBack(5)];
        const lists = correctionLists({ awaiting: rows, correctable: [], completedToday: [] });
        expect(lists.awaitingCorrection.map((e) => e.id)).toEqual([3, 5]);
    });

    it('correctableEarlier = correctable rows minus the sent-back ones minus what Completed Today already shows', () => {
        const shownToday = entry(7, { production_date: '2026-08-17' });
        const lists = correctionLists({
            awaiting: [sentBack(5)],
            correctable: [entry(6), sentBack(5), shownToday, entry(8)],
            completedToday: [shownToday],
        });
        expect(lists.correctableEarlier.map((e) => e.id)).toEqual([6, 8]);
    });

    it('the parity guard on correctable drops a row the resource says is not amendable (approved, running, or quality-checked)', () => {
        const lists = correctionLists({
            awaiting: [],
            correctable: [
                entry(1, { status: 'pm_approved' }),
                entry(2, { batch_status: 'in_progress' }),
                entry(3, { quality: { checked: true } }),
                entry(4),
            ],
            completedToday: [],
        });
        expect(lists.correctableEarlier.map((e) => e.id)).toEqual([4]);
    });

    it('a sent-back batch is never listed twice — it belongs to the amber panel, not the quiet line', () => {
        const back = sentBack(9);
        const lists = correctionLists({ awaiting: [back], correctable: [back], completedToday: [] });
        expect(lists.awaitingCorrection.map((e) => e.id)).toEqual([9]);
        expect(lists.correctableEarlier).toEqual([]);
    });
});
