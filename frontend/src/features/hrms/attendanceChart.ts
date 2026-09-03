import type { AttendanceTally } from '@/features/hrms/types';

/** One bar: what it counts, how many, and which of the six colours it takes. */
export interface AttendanceBar {
    key: keyof AttendanceTally;
    label: string;
    value: number;
    tone: 'present' | 'half' | 'absent' | 'leave' | 'off' | 'review' | 'mismatch';
}

/**
 * A MONTH'S SHAPE, in the order somebody reads it: what was worked, then
 * what was not, then what nobody has settled.
 *
 * Zeroes are kept rather than dropped. A month with no absences and a month
 * whose absences were never counted look identical once the empty bars are
 * removed, and only one of those is good news.
 */
export function tallyBars(tally: AttendanceTally): AttendanceBar[] {
    return [
        { key: 'present', label: 'Present', value: tally.present, tone: 'present' },
        { key: 'half_day', label: 'Half Day', value: tally.half_day, tone: 'half' },
        { key: 'absent', label: 'Absent', value: tally.absent, tone: 'absent' },
        { key: 'on_leave', label: 'On Leave', value: tally.on_leave, tone: 'leave' },
        { key: 'week_off', label: 'Week Off', value: tally.week_off, tone: 'off' },
        { key: 'needs_review', label: 'Needs review', value: tally.needs_review, tone: 'review' },
        { key: 'mismatches', label: 'Mismatches', value: tally.mismatches, tone: 'mismatch' },
    ];
}

/**
 * A friendly ceiling and four even ticks above zero, so the axis reads in
 * whole days. An all-zero month still gets a 1 ceiling rather than dividing
 * by nothing.
 */
export function barScale(bars: AttendanceBar[]): { max: number; ticks: number[] } {
    const raw = Math.max(0, ...bars.map((bar) => bar.value));
    const max = raw <= 0 ? 1 : niceCeiling(raw);
    const step = max / 4;

    return { max, ticks: [0, step, step * 2, step * 3, max] };
}

/**
 * The next round number at or above the value — whole days only, since half
 * a day is a status here and never an axis label.
 */
function niceCeiling(value: number): number {
    if (value <= 4) return Math.max(1, Math.ceil(value));

    const magnitude = 10 ** Math.floor(Math.log10(value));
    const candidates = [1, 2, 2.5, 4, 5, 10].map((multiple) => multiple * magnitude);
    const ceiling = candidates.find((candidate) => candidate >= value) ?? 10 * magnitude;

    // Four ticks over a ceiling that does not divide by four gives fractional
    // day labels, which nobody counts attendance in.
    return Math.ceil(ceiling / 4) * 4;
}
