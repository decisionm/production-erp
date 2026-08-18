import { describe, expect, it } from 'vitest';
import {
    completedTodaySummary,
    completedTodayUnits,
    createdWithinShiftWindow,
    floorStatusCounts,
    isRunningForOtherShift,
    machineFloorState,
    type MachineFloorState,
} from './shiftFloorSummary';
import type { ShiftProductionEntry } from './types';

/**
 * A completed entry as the list resource sends it — only the keys the summary
 * reads are meaningful, so the cast keeps the fixture honest about being a
 * partial wire shape rather than a hand-built domain object.
 */
const entry = (overrides: Partial<ShiftProductionEntry> = {}): ShiftProductionEntry =>
    ({
        id: 41,
        batch_status: 'completed',
        quantity_produced: '4800',
        quantity_scrap: '120',
        metrics: { expected_pieces: '5000', efficiency_pct: 96 },
        quality: { checked: false, rejected_nos: null },
        ...overrides,
    }) as unknown as ShiftProductionEntry;

describe('machineFloorState — the card\'s own priority order', () => {
    it('puts a breakdown ahead of everything, including a running batch', () => {
        expect(machineFloorState({ down: true, moldChange: true, running: true, runningForOtherShift: true })).toBe('down');
    });

    it('puts an in-progress mold change ahead of Running', () => {
        expect(machineFloorState({ down: false, moldChange: true, running: true, runningForOtherShift: false })).toBe('mold_change');
    });

    it('separates a run that is ours from one another shift has not handed over', () => {
        expect(machineFloorState({ down: false, moldChange: false, running: true, runningForOtherShift: false })).toBe('running');
        expect(machineFloorState({ down: false, moldChange: false, running: true, runningForOtherShift: true })).toBe('running_other_shift');
    });

    it('is idle only when nothing at all is open on the machine', () => {
        expect(machineFloorState({ down: false, moldChange: false, running: false, runningForOtherShift: false })).toBe('idle');
    });
});

describe('isRunningForOtherShift — every unknown falls through to "ours"', () => {
    const tabs = new Set([1, 2, 3]);

    it('is true only for a run filed under a DIFFERENT shift that has a tab here', () => {
        expect(isRunningForOtherShift({ runningShiftId: 2, effectiveShiftId: 1, shiftTabIds: tabs, hasRunning: true })).toBe(true);
    });

    it('is false for a run filed under the shift being viewed', () => {
        expect(isRunningForOtherShift({ runningShiftId: 1, effectiveShiftId: 1, shiftTabIds: tabs, hasRunning: true })).toBe(false);
    });

    it('is false when there is no run, no shift on the payload, or no shift picked yet', () => {
        expect(isRunningForOtherShift({ runningShiftId: 2, effectiveShiftId: 1, shiftTabIds: tabs, hasRunning: false })).toBe(false);
        expect(isRunningForOtherShift({ runningShiftId: null, effectiveShiftId: 1, shiftTabIds: tabs, hasRunning: true })).toBe(false);
        expect(isRunningForOtherShift({ runningShiftId: 2, effectiveShiftId: undefined, shiftTabIds: tabs, hasRunning: true })).toBe(false);
    });

    it('is false for a shift with no tab on this page — the machine must stay completable', () => {
        expect(isRunningForOtherShift({ runningShiftId: 9, effectiveShiftId: 1, shiftTabIds: tabs, hasRunning: true })).toBe(false);
    });
});

describe('floorStatusCounts', () => {
    it('counts one machine into exactly one bucket', () => {
        const states: MachineFloorState[] = ['running', 'running', 'idle', 'down', 'mold_change', 'running_other_shift'];

        expect(floorStatusCounts(states)).toEqual({
            total: 6,
            running: 2,
            idle: 1,
            down: 1,
            moldChange: 1,
            runningOtherShift: 1,
        });
    });

    it('answers an empty floor with zeroes, never with nothing', () => {
        expect(floorStatusCounts([])).toEqual({ total: 0, running: 0, idle: 0, down: 0, moldChange: 0, runningOtherShift: 0 });
    });
});

describe('completedTodaySummary — sums of the server\'s own figures', () => {
    it('has no figures at all for an empty or absent list', () => {
        for (const rows of [[], undefined, null]) {
            expect(completedTodaySummary(rows)).toEqual({
                batches: 0,
                goodPieces: null,
                expectedPieces: null,
                rejectPieces: null,
                qcRejectedPieces: null,
                outputVsExpectedPct: null,
                withoutExpected: 0,
            });
        }
    });

    it('adds the decimal strings as sent and states the ratio to 1dp', () => {
        const summary = completedTodaySummary([
            entry({ quantity_produced: '4800', quantity_scrap: '120', metrics: { expected_pieces: '5000' } as never }),
            entry({ quantity_produced: '2400.5', quantity_scrap: '30', metrics: { expected_pieces: '2500' } as never }),
        ]);

        expect(summary.batches).toBe(2);
        expect(summary.goodPieces).toBe(7200.5);
        expect(summary.expectedPieces).toBe(7500);
        expect(summary.rejectPieces).toBe(150);
        // 7200.5 / 7500 = 96.0066…% → 96.0
        expect(summary.outputVsExpectedPct).toBe(96);
        expect(summary.withoutExpected).toBe(0);
    });

    it('leaves a row with no expected figure out of BOTH sides of the ratio, and says how many', () => {
        const summary = completedTodaySummary([
            entry({ quantity_produced: '4800', metrics: { expected_pieces: '5000' } as never }),
            // An unconfigured product: real output, no target. Counted in the
            // good total, excluded from the ratio — otherwise the floor reads as
            // under-performing purely because a product has no standard.
            entry({ quantity_produced: '3000', metrics: null }),
        ]);

        expect(summary.goodPieces).toBe(7800);
        expect(summary.expectedPieces).toBe(5000);
        expect(summary.outputVsExpectedPct).toBe(96);
        expect(summary.withoutExpected).toBe(1);
    });

    it('has no ratio at all when not one row carries an expected figure', () => {
        const summary = completedTodaySummary([entry({ quantity_produced: '3000', metrics: null }), entry({ metrics: null })]);

        expect(summary.expectedPieces).toBeNull();
        expect(summary.outputVsExpectedPct).toBeNull();
        expect(summary.withoutExpected).toBe(2);
    });

    it('counts a target with no produced count as a shortfall, not as a row to drop', () => {
        const summary = completedTodaySummary([
            entry({ quantity_produced: '4000', metrics: { expected_pieces: '5000' } as never }),
            entry({ quantity_produced: null, metrics: { expected_pieces: '5000' } as never }),
        ]);

        expect(summary.expectedPieces).toBe(10000);
        expect(summary.goodPieces).toBe(4000);
        expect(summary.outputVsExpectedPct).toBe(40);
        expect(summary.withoutExpected).toBe(0);
    });

    it('adds quality rejections only for the batches quality has actually checked', () => {
        const summary = completedTodaySummary([
            entry({ quality: { checked: true, rejected_nos: 25 } as never }),
            entry({ quality: { checked: false, rejected_nos: 999 } as never }),
        ]);

        expect(summary.qcRejectedPieces).toBe(25);
    });

    it('never turns a missing figure into a zero that sums', () => {
        const summary = completedTodaySummary([entry({ quantity_produced: null, quantity_scrap: null as never, metrics: null })]);

        expect(summary.goodPieces).toBeNull();
        expect(summary.rejectPieces).toBeNull();
        expect(summary.qcRejectedPieces).toBeNull();
    });

    it('reads an unparseable string as missing rather than as NaN', () => {
        const summary = completedTodaySummary([entry({ quantity_produced: 'n/a', quantity_scrap: '', metrics: null })]);

        expect(summary.goodPieces).toBeNull();
        expect(summary.rejectPieces).toBeNull();
    });
});

describe('floorStatusCounts — the KPI strip can never disagree with the grid', () => {
    /**
     * THE SANITY RULE THE WHOLE STRIP RESTS ON: every machine drawn as a card
     * is counted into exactly one tile, and no machine is counted twice. If
     * this ever fails, the tiles are telling the supervisor a different story
     * about the same floor — which is worse than showing no tiles at all.
     */
    it('puts every machine in exactly one bucket, for every mix of states', () => {
        const states: MachineFloorState[] = ['down', 'mold_change', 'running_other_shift', 'running', 'idle'];
        // Every floor of up to three machines, over all five states.
        const floors: MachineFloorState[][] = [[]];
        for (let size = 1; size <= 3; size += 1) {
            const previous = floors.filter((f) => f.length === size - 1);
            for (const floor of previous) for (const state of states) floors.push([...floor, state]);
        }

        for (const floor of floors) {
            const counts = floorStatusCounts(floor);
            expect(counts.total).toBe(floor.length);
            expect(counts.running + counts.idle + counts.down + counts.moldChange + counts.runningOtherShift).toBe(counts.total);
        }
    });
});

describe('completedTodayUnits — the unit is read off the rows, never assumed', () => {
    /** The item master's own spelling, not a word this screen chose. */
    const inUom = (uom: string | null) => entry({ item: { uom } } as never);

    it('states the unit when every batch agrees on it', () => {
        expect(completedTodayUnits([inUom('Nos.'), inUom('Nos.')])).toEqual({
            uom: 'Nos.',
            mixed: false,
            uoms: ['Nos.'],
        });
    });

    it('treats a spelling that differs only by case or padding as the same unit', () => {
        const units = completedTodayUnits([inUom('Nos.'), inUom('  nos.  ')]);
        expect(units.mixed).toBe(false);
        // The FIRST spelling survives — it is how the item master writes it.
        expect(units.uom).toBe('Nos.');
    });

    it('refuses to name one unit when the batches genuinely disagree', () => {
        // `Nos.` and `pcs` may well be one unit to this factory, but that is
        // the owner's call to record, not a display function's to assume. Two
        // spellings read as two units, which withholds the total rather than
        // labelling it with one row's word.
        const units = completedTodayUnits([inUom('Nos.'), inUom('kg')]);
        expect(units.mixed).toBe(true);
        expect(units.uom).toBeNull();
        expect(units.uoms).toEqual(['Nos.', 'kg']);
    });

    it('says nothing at all rather than guessing when no row carries a unit', () => {
        for (const rows of [[], undefined, null, [inUom(null), inUom('   ')]]) {
            expect(completedTodayUnits(rows)).toEqual({ uom: null, mixed: false, uoms: [] });
        }
    });
});

describe('createdWithinShiftWindow — a start time is shown only when it can be honest', () => {
    const NIGHT = { start_time: '22:00', end_time: '06:00' };
    const DAY = { start_time: '06:00', end_time: '14:00' };

    /** A local-zone instant, written the way the browser will read it back. */
    const at = (y: number, mo: number, d: number, h: number, mi: number) =>
        new Date(y, mo - 1, d, h, mi, 0, 0).toISOString();

    it('accepts a night batch recorded after midnight — the case the gate must NOT suppress', () => {
        expect(createdWithinShiftWindow({
            createdAt: at(2026, 8, 19, 2, 0),
            productionDate: '2026-08-18',
            shift: NIGHT,
        })).toBe(true);
    });

    it('accepts a night batch recorded before midnight, on its own production date', () => {
        expect(createdWithinShiftWindow({
            createdAt: at(2026, 8, 18, 23, 30),
            productionDate: '2026-08-18',
            shift: NIGHT,
        })).toBe(true);
    });

    /**
     * THE BUG THIS FUNCTION EXISTS FOR. The previous gate compared
     * `productionDateFor(shift, created)` against `production_date`, and
     * `productionDateFor` maps EVERY clock time before an overnight shift's
     * start back to the previous day. So 10:00 on the 19th "belonged to" the
     * 18th, the check passed, and a 22:00–06:00 card printed "started 10:00".
     */
    it('refuses late paperwork typed the next morning for a night shift', () => {
        expect(createdWithinShiftWindow({
            createdAt: at(2026, 8, 19, 10, 0),
            productionDate: '2026-08-18',
            shift: NIGHT,
        })).toBe(false);
    });

    it('refuses a batch back-dated to an earlier day', () => {
        expect(createdWithinShiftWindow({
            createdAt: at(2026, 8, 19, 9, 0),
            productionDate: '2026-08-17',
            shift: DAY,
        })).toBe(false);
    });

    it('accepts a day batch inside its window and refuses one after it closed', () => {
        expect(createdWithinShiftWindow({ createdAt: at(2026, 8, 18, 9, 0), productionDate: '2026-08-18', shift: DAY })).toBe(true);
        // 14:00 is the boundary and counts; 14:01 is after the shift.
        expect(createdWithinShiftWindow({ createdAt: at(2026, 8, 18, 14, 0), productionDate: '2026-08-18', shift: DAY })).toBe(true);
        expect(createdWithinShiftWindow({ createdAt: at(2026, 8, 18, 14, 1), productionDate: '2026-08-18', shift: DAY })).toBe(false);
    });

    it('answers false for anything it cannot check, rather than guessing', () => {
        const good = { createdAt: at(2026, 8, 18, 9, 0), productionDate: '2026-08-18', shift: DAY };
        expect(createdWithinShiftWindow({ ...good, createdAt: null })).toBe(false);
        expect(createdWithinShiftWindow({ ...good, createdAt: 'not-a-date' })).toBe(false);
        expect(createdWithinShiftWindow({ ...good, productionDate: null })).toBe(false);
        expect(createdWithinShiftWindow({ ...good, productionDate: 'nonsense' })).toBe(false);
        expect(createdWithinShiftWindow({ ...good, shift: null })).toBe(false);
        expect(createdWithinShiftWindow({ ...good, shift: { start_time: 'x', end_time: 'y' } })).toBe(false);
        // A zero-length span is not a window anybody ran a batch in.
        expect(createdWithinShiftWindow({ ...good, shift: { start_time: '06:00', end_time: '06:00' } })).toBe(false);
    });

    it('accepts seconds on the shift times, as the server sends them', () => {
        expect(createdWithinShiftWindow({
            createdAt: at(2026, 8, 18, 9, 0),
            productionDate: '2026-08-18',
            shift: { start_time: '06:00:00', end_time: '14:00:00' },
        })).toBe(true);
    });
});
