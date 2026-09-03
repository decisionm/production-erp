import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderToString } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import { hoursLabel, turnoutPoints, turnoutScale } from './attendanceChart';
import type { AttendanceInsights, AttendanceTurnoutDay } from './types';

/**
 * THE THREE QUESTIONS A TALLY CANNOT ANSWER — as the manager sees them.
 */

vi.mock('@/lib/api', () => ({
    api: { get: vi.fn(async () => ({ data: { data: {} } })), post: vi.fn() },
}));

import AttendanceInsightsCard from './components/AttendanceInsightsCard';

const range = { from: '2026-07-01', to: '2026-07-03' };

const day = (date: string, over: Partial<AttendanceTurnoutDay> = {}): AttendanceTurnoutDay => ({
    date,
    present: 0,
    half_day: 0,
    absent: 0,
    on_leave: 0,
    week_off: 0,
    needs_review: 0,
    ...over,
});

const insights: AttendanceInsights = {
    from: range.from,
    to: range.to,
    turnout: [
        day('2026-07-01', { present: 40, half_day: 2, absent: 5 }),
        day('2026-07-02', { present: 12, absent: 30, needs_review: 4 }),
        day('2026-07-03', { present: 38, absent: 3 }),
    ],
    hours: {
        days: 1829, total_minutes: 900000, average_minutes: 492,
        long_days: 210, very_long_days: 44, short_days: 96, implausible_days: 7,
    },
    longest_days: [
        { employee_id: 3, employee_code: 'SPP-07', name: 'Mayavathi', department: 'Production Department', long_days: 18, minutes: 11400 },
    ],
    most_mismatched: [
        { employee_id: 5, employee_code: 'SPP-11', name: 'Pandiyan', department: 'Stores Department', mismatches: 14, unanswered: 6 },
    ],
};

function render(seed: (client: QueryClient) => void): string {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } });
    seed(client);

    return renderToString(
        <QueryClientProvider client={client}>
            <MemoryRouter>
                <AttendanceInsightsCard range={range} />
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

describe('the turnout maths', () => {
    it('counts a half day as somebody being there', () => {
        // The question is how many people were on the floor, and half a
        // person's day is a whole person present.
        const points = turnoutPoints(insights.turnout);

        expect(points[0].worked).toBe(42);
        expect(points[1].worked).toBe(12);
    });

    it('keeps unanswered days out of the absences', () => {
        const points = turnoutPoints(insights.turnout);

        expect(points[1].needsReview).toBe(4);
        expect(points[1].absent).toBe(30);
    });

    it('scales to the tallest stacked bar', () => {
        // 40 present + 2 half + 0 unanswered is the tallest day.
        expect(turnoutScale(turnoutPoints(insights.turnout)).max).toBeGreaterThanOrEqual(42);
    });

    it('says hours the way the factory says them', () => {
        expect(hoursLabel(492)).toBe('8h 12m');
        expect(hoursLabel(480)).toBe('8h');
        expect(hoursLabel(45)).toBe('45m');
        expect(hoursLabel(0)).toBe('—');
    });
});

describe('AttendanceInsightsCard', () => {
    it('draws the floor day by day and names who works longest', () => {
        const html = render((client) => {
            client.setQueryData(['hrms', 'attendance', 'insights', range.from, range.to], insights);
        });

        expect(html).toContain('Insights');
        expect(html).toContain('On the floor, day by day');
        expect(html).toContain('<svg');
        // The hours, off the clock.
        expect(html).toContain('8h 12m');
        expect(html).toContain('Over 10h');
        // An impossible day is shown apart rather than counted as somebody's.
        expect(html).toContain('Impossible days');
        // And the two lists of people.
        expect(html).toContain('Mayavathi');
        expect(html).toContain('Pandiyan');
        expect(html).toContain('190h');
    });

    it('says the period is empty rather than drawing an empty chart', () => {
        const html = render((client) => {
            client.setQueryData(['hrms', 'attendance', 'insights', range.from, range.to], {
                ...insights,
                turnout: [],
            });
        });

        expect(html).toContain('Nothing recorded in this period.');
    });
});
