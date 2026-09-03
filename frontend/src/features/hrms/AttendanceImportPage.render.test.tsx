import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderToString } from 'react-dom/server';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import type { Paginated } from '@/lib/types';
import type {
    AttendanceImport,
    AttendanceImportEmployee,
    AttendanceImportLine,
    AttendanceImportLineListParams,
} from './types';

/**
 * ONE RUN'S REVIEW PAGE, at both grains.
 *
 * PEOPLE is what the page opens on, because that is where the work is: one
 * row per person, their month drawn beside them, the count of what they
 * still need. DAYS is the flat list behind `?view=days`, with the chips
 * carrying the server's counts, an open line showing its punches and a
 * Correct button for a manager, an unknown employee linking to the
 * Employees page — and, when a chip names one kind of problem, ONE button
 * that answers every day still carrying it.
 *
 * Apply stays disabled with the open count either way, and the download
 * appears only once the month is applied.
 */

vi.mock('@/lib/api', () => ({
    api: {
        get: vi.fn(async () => ({ data: { data: [], meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 } } })),
        post: vi.fn(),
        patch: vi.fn(),
    },
}));

// The store is mocked rather than set: under renderToString zustand
// serves its INITIAL state (the server snapshot), so setState is unseen.
let permissions: string[] = ['hrms.manage'];
vi.mock('@/features/auth/store', () => ({
    useAuthStore: (selector: (state: unknown) => unknown) =>
        selector({ user: { id: 1, name: 'Vimal', email: 'v@example.test', is_active: true, permissions }, setUser: () => undefined }),
}));

import AttendanceImportPage from './pages/AttendanceImportPage';

function run(overrides: Partial<AttendanceImport> = {}): AttendanceImport {
    return {
        id: 7,
        source: 'pooja',
        period_from: '2026-07-01',
        period_to: '2026-07-31',
        file_name: 'july.xlsx',
        status: 'review',
        employee_count: 59,
        day_count: 1829,
        issue_count: 594,
        open_count: 3,
        counts: { open: 3, in_no_out: 1, out_no_in: 0, no_punch: 1, unknown_employee: 1, resolved: 2, clean: 1233 },
        uploaded_by: { id: 1, name: 'Vimal' },
        applied_at: null,
        created_at: '2026-09-03T10:00:00+00:00',
        ...overrides,
    };
}

function line(id: number, overrides: Partial<AttendanceImportLine> = {}): AttendanceImportLine {
    return {
        id,
        attendance_import_id: 7,
        employee_id: 11,
        employee_code: 'TST-01',
        employee_name: 'EMPLOYEE 01',
        employee: { id: 11, name: 'EMPLOYEE 01', department: 'Human Resource', designation: 'HR' },
        date: '2026-07-03',
        raw_status: 'FD',
        first_in: '09:58',
        last_out: null,
        ot_minutes: 0,
        late_minutes: 0,
        early_minutes: 0,
        worked_minutes: 0,
        issue: 'in_no_out',
        resolution: null,
        resolved_check_in: null,
        resolved_check_out: null,
        resolved_at: null,
        notes: null,
        applied_at: null,
        ...overrides,
    };
}

function person(overrides: Partial<AttendanceImportEmployee> = {}): AttendanceImportEmployee {
    return {
        employee_code: 'TST-01',
        employee_name: 'EMPLOYEE 01',
        employee_id: 11,
        known: true,
        department: 'Human Resource',
        designation: 'HR',
        day_count: 4,
        open_count: 2,
        resolved_count: 0,
        clean_count: 2,
        days: [
            { date: '2026-07-01', state: 'present' },
            { date: '2026-07-02', state: 'needs_fix' },
            { date: '2026-07-03', state: 'needs_fix' },
            { date: '2026-07-04', state: 'week_off' },
        ],
        ...overrides,
    };
}

function page<T>(rows: T[], total: number): Paginated<T> {
    return { data: rows, meta: { current_page: 1, per_page: 20, total, last_page: Math.max(1, Math.ceil(total / 20)) } };
}

function render(
    path: string,
    params: Partial<AttendanceImportLineListParams>,
    seededRun: AttendanceImport,
    seeded: { lines?: Paginated<AttendanceImportLine>; people?: Paginated<AttendanceImportEmployee> } = {},
    granted: string[] = ['hrms.manage'],
): string {
    permissions = granted;
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } });
    client.setQueryData(['hrms', 'attendance-imports', seededRun.id], seededRun);
    client.setQueryData(['hrms', 'attendance-imports', seededRun.id, 'lines', params], seeded.lines ?? page([], 0));
    client.setQueryData(
        ['hrms', 'attendance-imports', seededRun.id, 'employees', { q: '', page: 1 }],
        seeded.people ?? page([], 0),
    );

    return renderToString(
        <QueryClientProvider client={client}>
            <MemoryRouter initialEntries={[path]}>
                <Routes>
                    <Route path="/hrms/attendance-imports/:id" element={<AttendanceImportPage />} />
                </Routes>
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

describe('AttendanceImportPage — the people view', () => {
    it('opens on the people, with the period, how far the month has got and the strip key', () => {
        const html = render('/hrms/attendance-imports/7', {}, run(), { people: page([person()], 59) });

        expect(html).toContain('Wed 1 Jul – Fri 31 Jul');
        expect(html).toContain('3 of 1,829 days need an answer · 2 answered');
        expect(html).toContain('People (59)');
        expect(html).toContain('Days (1,829)');
        // The strip's key, so the colours never have to be learned.
        expect(html).toContain('Needs an answer');
        expect(html).toContain('Week Off');
        expect(html).toContain('ant-input-search');
        expect(html).toContain('1–20 of 59 people');
    });

    it('gives each person their name, their department and what they still owe', () => {
        const html = render('/hrms/attendance-imports/7', {}, run(), { people: page([person()], 1) });

        expect(html).toContain('TST-01 — EMPLOYEE 01');
        expect(html).toContain('Human Resource');
        expect(html).toContain('2 days');
        expect(html).toContain('Answer');
        // One square per day of the month, the two open ones marked.
        expect(html).toContain('Thu 2 Jul — Needs an answer');
        expect(html).toContain('Wed 1 Jul — Present');
    });

    it('says a person is done rather than leaving the column blank', () => {
        const html = render('/hrms/attendance-imports/7', {}, run(), {
            people: page([person({ open_count: 0, resolved_count: 2 })], 1),
        });

        expect(html).toContain('Done');
        expect(html).toContain('View');
    });

    it('marks somebody the employee master does not have', () => {
        const html = render('/hrms/attendance-imports/7', {}, run(), {
            people: page([person({ employee_code: 'ZZZ-99', employee_name: 'NOBODY', employee_id: null, known: false, department: null })], 1),
        });

        expect(html).toContain('ZZZ-99 — NOBODY');
        expect(html).toContain('Not in the employee master');
    });

    it('names the term when a people search matches nothing', () => {
        const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } });
        const seeded = run();
        client.setQueryData(['hrms', 'attendance-imports', 7], seeded);
        client.setQueryData(['hrms', 'attendance-imports', 7, 'employees', { q: '', page: 1 }], page([], 0));

        const html = renderToString(
            <QueryClientProvider client={client}>
                <MemoryRouter initialEntries={['/hrms/attendance-imports/7']}>
                    <Routes>
                        <Route path="/hrms/attendance-imports/:id" element={<AttendanceImportPage />} />
                    </Routes>
                </MemoryRouter>
            </QueryClientProvider>,
        );

        expect(html).toContain('Nobody in this run.');
    });
});

describe('AttendanceImportPage — the day view', () => {
    it('carries the chips with their numbers and a search box', () => {
        const html = render('/hrms/attendance-imports/7?view=days', {}, run(), { lines: page([line(1)], 1) });

        expect(html).toContain('All issues (3)');
        expect(html).toContain('In without Out (1)');
        expect(html).toContain('Unknown employee (1)');
        expect(html).toContain('Resolved (2)');
        expect(html).toContain('Clean (1233)');
        expect(html).toContain('Employee code or name');
        expect(html).toContain('1 lines');
    });

    it('renders an open line with its punches in words, its issue and a Correct button for a manager', () => {
        const html = render('/hrms/attendance-imports/7?view=days', {}, run(), { lines: page([line(1)], 1) });

        expect(html).toContain('TST-01 — EMPLOYEE 01');
        expect(html).toContain('In 09:58, no out');
        expect(html).toContain('Fri 3 Jul');
        expect(html).toContain('In without Out');
        expect(html).toContain('Correct');
    });

    it('links an unknown employee to the Employees page', () => {
        const html = render('/hrms/attendance-imports/7?view=days', {}, run(), {
            lines: page(
                [line(2, { employee_id: null, employee: undefined, employee_code: 'ZZZ-99', employee_name: 'NOBODY', issue: 'unknown_employee' })],
                1,
            ),
        });

        expect(html).toContain('ZZZ-99 — NOBODY');
        expect(html).toContain('/hrms/employees?q=ZZZ-99');
    });

    it('names the term when a search matches nothing', () => {
        const html = render('/hrms/attendance-imports/7?view=days&q=zzz', { q: 'zzz' }, run(), { lines: page([], 0) });

        expect(html).toContain('No lines match “zzz”.');
        expect(html).toContain('Clear search');
    });

    it('reads the chip off the URL', () => {
        const html = render('/hrms/attendance-imports/7?view=days&issue=no_punch', { issue: 'no_punch' }, run(), { lines: page([], 0) });

        expect(html).toContain('No lines match this filter.');
        expect(html).toContain('ant-radio-button-wrapper-checked');
    });
});

describe('AttendanceImportPage — one answer for one kind of problem', () => {
    it('offers to mark every unpunched day absent, naming the count', () => {
        const html = render(
            '/hrms/attendance-imports/7?view=days&issue=no_punch',
            { issue: 'no_punch' },
            run({ counts: { open: 366, in_no_out: 0, out_no_in: 0, no_punch: 366, unknown_employee: 0, resolved: 0, clean: 1240 } }),
            { lines: page([line(4, { issue: 'no_punch', first_in: null, last_out: null })], 366) },
        );

        expect(html).toContain('Mark all 366 as Absent');
    });

    it('offers a shift end for every missing out-punch', () => {
        const html = render(
            '/hrms/attendance-imports/7?view=days&issue=in_no_out',
            { issue: 'in_no_out' },
            run({ counts: { open: 223, in_no_out: 223, out_no_in: 0, no_punch: 0, unknown_employee: 0, resolved: 0, clean: 1240 } }),
            { lines: page([line(5)], 223) },
        );

        expect(html).toContain('Set the out-time for all 223');
    });

    it('never offers to answer for people the master does not have', () => {
        const html = render(
            '/hrms/attendance-imports/7?view=days&issue=unknown_employee',
            { issue: 'unknown_employee' },
            run({ counts: { open: 12, in_no_out: 0, out_no_in: 0, no_punch: 0, unknown_employee: 12, resolved: 0, clean: 1240 } }),
            { lines: page([], 12) },
        );

        expect(html).not.toContain('Mark all');
        expect(html).not.toContain('Answer them');
    });

    it('offers nothing in bulk to a viewer', () => {
        const html = render(
            '/hrms/attendance-imports/7?view=days&issue=no_punch',
            { issue: 'no_punch' },
            run({ counts: { open: 366, in_no_out: 0, out_no_in: 0, no_punch: 366, unknown_employee: 0, resolved: 0, clean: 1240 } }),
            { lines: page([], 366) },
            ['hrms.view'],
        );

        expect(html).not.toContain('Mark all 366 as Absent');
    });
});

describe('AttendanceImportPage — applying', () => {
    it('offers no Correct and no Apply to a viewer', () => {
        const html = render('/hrms/attendance-imports/7?view=days', {}, run(), { lines: page([line(1)], 1) }, ['hrms.view']);

        expect(html).not.toContain('Correct');
        expect(html).not.toContain('Apply');
    });

    it('disables Apply with the open count, and shows the download only once applied', () => {
        const review = render('/hrms/attendance-imports/7', {}, run());
        expect(review).toContain('Apply (3 open)');
        expect(review).toContain('disabled');
        expect(review).not.toContain('Download month sheet');

        const applied = render(
            '/hrms/attendance-imports/7?view=days',
            {},
            run({
                status: 'applied',
                open_count: 0,
                counts: { open: 0, in_no_out: 0, out_no_in: 0, no_punch: 0, unknown_employee: 0, resolved: 5, clean: 1233 },
                applied_at: '2026-09-03T11:00:00+00:00',
            }),
            {
                lines: page(
                    [line(3, { resolution: 'present', resolved_check_in: '09:58', resolved_check_out: '19:30', applied_at: '2026-09-03T11:00:00+00:00' })],
                    1,
                ),
            },
        );
        expect(applied).toContain('>Apply<');
        expect(applied).toContain('Download month sheet');
        expect(applied).toContain('Present');
        expect(applied).toContain('09:58 / 19:30');
        // An applied month is closed: no bulk answer, no Correct.
        expect(applied).not.toContain('Correct');
    });
});
