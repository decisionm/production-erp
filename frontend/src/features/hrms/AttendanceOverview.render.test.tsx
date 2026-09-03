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
        { id: 1, date: '2026-09-01', status: 'present', check_in: '2026-09-01T00:30:00+00:00', check_out: '2026-09-01T08:30:00+00:00', notes: null, source: 'attendance', needs_review: false, provisional: false },
        { id: 2, date: '2026-09-02', status: 'absent', check_in: null, check_out: null, notes: 'no punch', source: 'attendance', needs_review: false, provisional: false },
        // Read from an upload nobody has applied, and not yet answered.
        { id: null, date: '2026-09-03', status: null, check_in: '2026-09-03T00:30:00+00:00', check_out: null, notes: null, source: 'import', needs_review: true, provisional: true },
    ],
    summary: { present: 18, absent: 2, half_day: 1, on_leave: 1, recorded: 22, week_off: 2, needs_review: 3, from_import: 5, mismatches: 3 },
};

const summary: AttendanceSummary = {
    from: range.from,
    to: range.to,
    departments: [
        { department: 'Production Department', present: 40, absent: 5, half_day: 2, on_leave: 1, recorded: 48, week_off: 4, needs_review: 6, from_import: 40, mismatches: 6, employees: 12, present_percent: 85.4 },
        { department: 'Stores Department', present: 8, absent: 0, half_day: 0, on_leave: 0, recorded: 8, week_off: 0, needs_review: 0, from_import: 0, mismatches: 0, employees: 2, present_percent: 100 },
    ],
    totals: { present: 48, absent: 5, half_day: 2, on_leave: 1, recorded: 56, week_off: 4, needs_review: 6, from_import: 40, mismatches: 6, employees: 14, departments: 2, present_percent: 87.5 },
    imports: [{ id: 1, file_name: 'july.xlsx', status: 'review', period_from: '2026-09-01', period_to: '2026-09-30' }],
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
        // The sheet is what the floor corrects on, so printing is offered
        // beside the person rather than buried in Downloads.
        expect(html).toContain('Print sheet');
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
                summary: { present: 0, absent: 0, half_day: 0, on_leave: 0, recorded: 0, week_off: 0, needs_review: 0, from_import: 0, mismatches: 0 },
            });
        });

        expect(html).toContain('Nothing recorded for this person in this period.');
    });

    it('says which upload the provisional days came from, and stays silent once it is applied', () => {
        const seed = (client: QueryClient) => {
            client.setQueryData(['hrms', 'employees', 'all'], {
                data: [{ id: 11, employee_code: 'SPP-01', name: 'Mayavathi', status: 'active' }],
                meta: { current_page: 1, per_page: 1000, total: 1, last_page: 1 },
            });
        };

        const provisional = render(<PersonAttendanceCard range={range} />, (client) => {
            seed(client);
            client.setQueryData(['hrms', 'attendance', 'person', 11, range.from, range.to], person);
        });
        expect(provisional).toContain('5 days');
        expect(provisional).toContain('not been applied yet');

        // APPLIED: the week offs still come from the upload — applying
        // writes no row for one — but the month is finished, and saying
        // otherwise for ever after would be worse than saying nothing.
        const applied = render(<PersonAttendanceCard range={range} />, (client) => {
            seed(client);
            client.setQueryData(['hrms', 'attendance', 'person', 11, range.from, range.to], {
                ...person,
                days: person.days.map((day) => ({ ...day, provisional: false })),
                summary: { ...person.summary, from_import: 0 },
            });
        });
        expect(applied).not.toContain('not been applied yet');
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

    it('does not call an applied month provisional', () => {
        const html = render(<DepartmentAttendanceCard range={range} />, (client) => {
            client.setQueryData(['hrms', 'attendance', 'summary', range.from, range.to], {
                ...summary,
                departments: summary.departments.map((row) => ({ ...row, from_import: 0 })),
                totals: { ...summary.totals, from_import: 0 },
                imports: [{ id: 1, file_name: 'july.xlsx', status: 'applied', period_from: '2026-09-01', period_to: '2026-09-30' }],
            });
        });

        expect(html).toContain('Production Department');
        expect(html).not.toContain('applied yet');
    });

    it('names the upload the provisional days came from', () => {
        const html = render(<DepartmentAttendanceCard range={range} />, (client) => {
            client.setQueryData(['hrms', 'attendance', 'summary', range.from, range.to], summary);
        });

        expect(html).toContain('july.xlsx');
        expect(html).toContain('applied yet');
    });

    it('says the period is empty rather than drawing an empty chart', () => {
        const html = render(<DepartmentAttendanceCard range={range} />, (client) => {
            client.setQueryData(['hrms', 'attendance', 'summary', range.from, range.to], {
                ...summary,
                departments: [],
                totals: { present: 0, absent: 0, half_day: 0, on_leave: 0, recorded: 0, week_off: 0, needs_review: 0, from_import: 0, mismatches: 0, employees: 0, departments: 0, present_percent: 0 },
                imports: [],
                most_absent: [],
            });
        });

        expect(html).toContain('No attendance recorded in this period.');
        expect(html).not.toContain('Most absent');
    });
});
