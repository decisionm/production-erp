import { describe, expect, it } from 'vitest';
import {
    completedTodaySummary,
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
