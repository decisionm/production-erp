import dayjs, { type Dayjs } from 'dayjs';

/**
 * THE ATTENDANCE PAGE'S PERIOD BUTTONS.
 *
 * Today, Yesterday, Last week, Last month — plus This month, because the
 * punch report arrives a month at a time and the month in progress is what
 * the office is usually looking at.
 *
 * "Last week" and "Last month" mean the WEEK and MONTH THAT ENDED, not the
 * last seven or thirty days. A factory reads its attendance by the calendar
 * it pays on, and "last month" that included half of this one would be a
 * figure nobody could reconcile against a payslip.
 *
 * Dates are computed in the BROWSER'S timezone, which on the factory's own
 * machines is IST. The server is given two plain dates and does no
 * conversion, so what a person picks is what they get.
 */
export type RangePreset = 'today' | 'yesterday' | 'this_week' | 'last_week' | 'this_month' | 'last_month';

export interface DateRange {
    from: string;
    to: string;
}

const iso = (day: Dayjs): string => day.format('YYYY-MM-DD');

/** The preset resolved against a given day — `today` is injectable so the test is not a clock. */
export function rangeFor(preset: RangePreset, today: Dayjs = dayjs()): DateRange {
    switch (preset) {
        case 'today':
            return { from: iso(today), to: iso(today) };
        case 'yesterday': {
            const day = today.subtract(1, 'day');
            return { from: iso(day), to: iso(day) };
        }
        case 'this_week':
            // Monday to today: a factory week starts on Monday, and a week
            // that ran to Sunday would read as empty every Monday morning.
            return { from: iso(today.startOf('week').add(1, 'day')), to: iso(today) };
        case 'last_week': {
            const monday = today.startOf('week').add(1, 'day').subtract(1, 'week');
            return { from: iso(monday), to: iso(monday.add(6, 'day')) };
        }
        case 'this_month':
            return { from: iso(today.startOf('month')), to: iso(today) };
        case 'last_month': {
            const month = today.subtract(1, 'month');
            return { from: iso(month.startOf('month')), to: iso(month.endOf('month')) };
        }
    }
}

export const RANGE_PRESETS: { value: RangePreset; label: string }[] = [
    { value: 'today', label: 'Today' },
    { value: 'yesterday', label: 'Yesterday' },
    { value: 'this_week', label: 'This week' },
    { value: 'last_week', label: 'Last week' },
    { value: 'this_month', label: 'This month' },
    { value: 'last_month', label: 'Last month' },
];

/** The preset a range came from, or null when somebody picked their own dates. */
export function presetFor(range: DateRange, today: Dayjs = dayjs()): RangePreset | null {
    return RANGE_PRESETS.map((preset) => preset.value).find((preset) => {
        const candidate = rangeFor(preset, today);

        return candidate.from === range.from && candidate.to === range.to;
    }) ?? null;
}

/** "Wed 2 Sep" for one day, "1 – 30 Sep 2026" for a range. */
export function rangeLabel(range: DateRange): string {
    const from = dayjs(range.from);
    const to = dayjs(range.to);
    if (range.from === range.to) return from.format('ddd D MMM YYYY');
    if (from.isSame(to, 'month')) return `${from.format('D')} – ${to.format('D MMM YYYY')}`;

    return `${from.format('D MMM')} – ${to.format('D MMM YYYY')}`;
}
