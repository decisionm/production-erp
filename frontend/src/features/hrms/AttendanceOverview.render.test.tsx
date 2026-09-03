import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderToString } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import type { AttendancePersonRange, AttendanceSummary } from './types';

/**
 * THE ATTENDANCE PAGE'S TWO NEW HALVES.
 *
 * One person, picked from a dropdown, with their days and their totals; and
 * the factory by department, which only a manager is shown — and which the
 * server refuses to a view-only login as well, so hiding it is the smaller
 * half of the gate.
 */

vi.mock('@/lib/api', () => ({
    api: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
}));

import DepartmentAttendanceCard from './components/DepartmentAttendanceCard';
import PersonAttendanceCard from './components/PersonAttendanceCard';

const range = { from: '2026-09-01', to: '2026-09-30' };

const person: AttendancePersonRange = {
    employee: { id: 11, employee_code: 'SPP-01', name: 'Mayavathi', department: 'Production Department', designation: 'Packing Staff' },
    from: range.from,
    to: range.to,
    days: [
        { id: 1, date: '2026-09-01', status: 'present', check_in: '2026-09-01T00:30:00+00:00', check_out: '2026-09-01T08:30:00+00:00', notes: null },
        { id: 2, date: '2026-09-02', status: 'absent', check_in: null, check_out: null, notes: 'no punch' },
    ],
    summary: { present: 18, absent: 2, half_day: 1, on_leave: 1, recorded: 22 },
};

const summary: AttendanceSummary = {
    from: range.from,
    to: range.to,
    departments: [
        { department: 'Production Department', present: 40, absent: 5, half_day: 2, on_leave: 1, recorded: 48, employees: 12, present_percent: 85.4 },
        { department: 'Stores Department', present: 8, absent: 0, half_day: 0, on_leave: 0, recorded: 8, employees: 2, present_percent: 100 },
    ],
    totals: { present: 48, absent: 5, half_day: 2, on_leave: 1, recorded: 56, employees: 14, departments: 2, present_percent: 87.5 },
    most_absent: [{ employee_id: 7, employee_code: 'SPP-40', name: 'Pandiyan', department: 'Logistics & Transportation', absent: 5 }],
};

function render(node: React.ReactElement, seed: (client: QueryClient) => void): string {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } });
    seed(client);

    return renderToString(
        <QueryClientProvider client={client}>
            <MemoryRouter>{node}</MemoryRouter>
        </QueryClientProvider>,
    );
}

describe('PersonAttendanceCard', () => {
    it('names the person, their department, and totals the period', () => {
        const html = render(<PersonAttendanceCard range={range} />, (client) => {
            client.setQueryData(['hrms', 'employees', 'all'], {
                data: [{ id: 11, employee_code: 'SPP-01', name: 'Mayavathi', status: 'active' }],
                meta: { current_page: 1, per_page: 1000, total: 1, last_page: 1 },
            });
            client.setQueryData(['hrms', 'attendance', 'person', 11, range.from, range.to], person);
        });

        expect(html).toContain('One person');
        expect(html).toContain('Mayavathi');
        expect(html).toContain('Production Department');
        // The tally, as tiles.
        expect(html).toContain('Present');
        expect(html).toContain('>18<');
        expect(html).toContain('Days recorded');
        expect(html).toContain('>22<');
        // The days, by name rather than as ISO dates.
        expect(html).toContain('Tue 1 Sep');
        expect(html).toContain('Wed 2 Sep');
        expect(html).toContain('no punch');
    });

    it('says the period is empty for that person rather than showing a bare table', () => {
        const html = render(<PersonAttendanceCard range={range} />, (client) => {
            client.setQueryData(['hrms', 'employees', 'all'], {
                data: [{ id: 11, employee_code: 'SPP-01', name: 'Mayavathi', status: 'active' }],
                meta: { current_page: 1, per_page: 1000, total: 1, last_page: 1 },
            });
            client.setQueryData(['hrms', 'attendance', 'person', 11, range.from, range.to], {
                ...person,
                days: [],
                summary: { present: 0, absent: 0, half_day: 0, on_leave: 0, recorded: 0 },
            });
        });

        expect(html).toContain('Nothing recorded for this person in this period.');
    });

    it('offers only active employees in the dropdown', () => {
        const html = render(<PersonAttendanceCard range={range} />, (client) => {
            client.setQueryData(['hrms', 'employees', 'all'], {
                data: [
                    { id: 11, employee_code: 'SPP-01', name: 'Mayavathi', status: 'active' },
                    { id: 12, employee_code: 'SPP-05', name: 'Velvizhi', status: 'inactive' },
                ],
                meta: { current_page: 1, per_page: 1000, total: 2, last_page: 1 },
            });
        });

        // The picker opens on the first ACTIVE person.
        expect(html).toContain('SPP-01 — Mayavathi');
        expect(html).not.toContain('SPP-05 — Velvizhi');
    });
});

describe('DepartmentAttendanceCard', () => {
    it('totals the factory, lists each department and names who is most absent', () => {
        const html = render(<DepartmentAttendanceCard range={range} />, (client) => {
            client.setQueryData(['hrms', 'attendance', 'summary', range.from, range.to], summary);
        });

        expect(html).toContain('By department');
        // The factory's own line.
        expect(html).toContain('People');
        expect(html).toContain('>14<');
        expect(html).toContain('Days recorded');
        expect(html).toContain('>56<');
        // A row per department, busiest first.
        expect(html.indexOf('Production Department')).toBeLessThan(html.indexOf('Stores Department'));
        expect(html).toContain('85.4');
        // And the people carrying the absence.
        expect(html).toContain('Most absent');
        expect(html).toContain('SPP-40');
        expect(html).toContain('5 days');
    });

    it('says the period is empty rather than drawing an empty chart', () => {
        const html = render(<DepartmentAttendanceCard range={range} />, (client) => {
            client.setQueryData(['hrms', 'attendance', 'summary', range.from, range.to], {
                ...summary,
                departments: [],
                totals: { present: 0, absent: 0, half_day: 0, on_leave: 0, recorded: 0, employees: 0, departments: 0, present_percent: 0 },
                most_absent: [],
            });
        });

        expect(html).toContain('No attendance recorded in this period.');
        expect(html).not.toContain('Most absent');
    });
});
