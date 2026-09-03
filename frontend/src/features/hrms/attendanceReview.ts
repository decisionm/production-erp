import dayjs from 'dayjs';
import { ISSUE_LABELS, RESOLUTION_LABELS } from './list';
import type {
    AttendanceImportCounts,
    AttendanceImportIssue,
    AttendanceImportLine,
    AttendanceImportLineFilter,
    AttendanceImportResolution,
    DayState,
} from './types';

/**
 * THE REVIEW SCREEN'S PURE HALF — what a day is called, what colour it
 * draws, how far the month has got, and which one-click answer a filter
 * earns. Kept out of the page so the numbers a reviewer acts on are pinned
 * by a test rather than read off a screenshot.
 */

export const DAY_STATE_LABELS: Record<DayState, string> = {
    ...RESOLUTION_LABELS,
    needs_fix: 'Needs an answer',
};

/**
 * One warm colour in the whole strip, and it belongs to the only thing the
 * reviewer has to act on. Everything decided is cool or neutral: an
 * absence is a fact, not an alarm, so it is grey rather than red.
 */
export const DAY_STATE_COLORS: Record<DayState, string> = {
    present: '#2e7d32',
    half_day: '#9ccc65',
    absent: '#9e9e9e',
    on_leave: '#1e88e5',
    week_off: '#e8e8e8',
    needs_fix: '#fb8c00',
};

/** "Wed 1 Jul" — the factory reads a muster by day name, not by 2026-07-01. */
export function dayLabel(date: string): string {
    return dayjs(date).format('ddd D MMM');
}

/** What the punch clock actually recorded, in words rather than dashes. */
export function punchLine(line: Pick<AttendanceImportLine, 'first_in' | 'last_out'>): string {
    if (line.first_in && line.last_out) return `In ${line.first_in}, out ${line.last_out}`;
    if (line.first_in) return `In ${line.first_in}, no out`;
    if (line.last_out) return `Out ${line.last_out}, no in`;

    return 'No punch';
}

/** "589 of 1,829 days need an answer" — or the finished line once none do. */
export function progressLine(counts: AttendanceImportCounts | undefined, dayCount: number | undefined): string {
    if (!counts || dayCount === undefined) return '';
    const answered = counts.resolved;
    const total = counts.open + answered;
    if (total === 0) return `${dayCount.toLocaleString()} days, nothing to answer`;
    if (counts.open === 0) return `All ${total.toLocaleString()} answered, ready to apply`;

    return `${counts.open.toLocaleString()} of ${dayCount.toLocaleString()} days need an answer · ${answered.toLocaleString()} answered`;
}

export function progressPercent(counts: AttendanceImportCounts | undefined): number {
    if (!counts) return 0;
    const total = counts.open + counts.resolved;

    return total === 0 ? 100 : Math.round((counts.resolved / total) * 100);
}

export interface BulkOffer {
    issue: AttendanceImportIssue;
    resolution: AttendanceImportResolution;
    /** The button, naming the count so nobody presses it blind. */
    label: string;
    /** The one thing the answer still needs from a person, if any. */
    time: 'check_in' | 'check_out' | null;
    timeLabel: string;
}

/**
 * The one-click answer a filter earns, or nothing.
 *
 * Only the two questions a month actually repeats are offered. An unknown
 * employee is deliberately NOT offered one: the fix there is to add the
 * person, and a button that answered it would be inventing whose day it
 * was. `resolved` and `clean` have nothing left to answer.
 */
export function bulkOffer(
    filter: AttendanceImportLineFilter | '' | undefined,
    counts: AttendanceImportCounts | undefined,
): BulkOffer | null {
    if (!counts) return null;

    if (filter === 'no_punch' && counts.no_punch > 0) {
        return {
            issue: 'no_punch',
            resolution: 'absent',
            label: `Mark all ${counts.no_punch} as Absent`,
            time: null,
            timeLabel: '',
        };
    }

    if (filter === 'in_no_out' && counts.in_no_out > 0) {
        return {
            issue: 'in_no_out',
            resolution: 'present',
            label: `Set the out-time for all ${counts.in_no_out}`,
            time: 'check_out',
            timeLabel: 'Out time',
        };
    }

    if (filter === 'out_no_in' && counts.out_no_in > 0) {
        return {
            issue: 'out_no_in',
            resolution: 'present',
            label: `Set the in-time for all ${counts.out_no_in}`,
            time: 'check_in',
            timeLabel: 'In time',
        };
    }

    return null;
}

/** What a finished bulk answer says back, skips included and named. */
export function bulkOutcome(result: { resolved: number; skipped: number; skipped_codes: string[] }): string {
    const answered = `${result.resolved} ${result.resolved === 1 ? 'day' : 'days'} answered`;
    if (result.skipped === 0) return `${answered}.`;

    return `${answered}. ${result.skipped} skipped, ${result.skipped_codes.join(', ')} not in the employee master.`;
}

/** The issue's name, or the plain truth that the day was already fine. */
export function issueLabel(issue: AttendanceImportIssue | null): string {
    return issue ? ISSUE_LABELS[issue] : 'No issue';
}
