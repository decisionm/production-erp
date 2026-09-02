import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderToString } from 'react-dom/server';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import type { Paginated } from '@/lib/types';
import type { AttendanceImport, AttendanceImportLine, AttendanceImportLineListParams } from './types';

/**
 * One run's review page: the chips carry the server's counts, open issues
 * render with their issue and a Correct button for a manager, an unknown
 * employee links to the Employees page, Apply is disabled with the open
 * count, and the download appears only once applied.
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

function page(rows: AttendanceImportLine[], total: number): Paginated<AttendanceImportLine> {
    return { data: rows, meta: { current_page: 1, per_page: 20, total, last_page: Math.max(1, Math.ceil(total / 20)) } };
}

function render(
    path: string,
    params: Partial<AttendanceImportLineListParams>,
    seededRun: AttendanceImport,
    seededLines: Paginated<AttendanceImportLine>,
    granted: string[] = ['hrms.manage'],
): string {
    permissions = granted;
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } });
    client.setQueryData(['hrms', 'attendance-imports', seededRun.id], seededRun);
    client.setQueryData(['hrms', 'attendance-imports', seededRun.id, 'lines', params], seededLines);

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

describe('AttendanceImportPage', () => {
    it('shows the period, the counts, the chips with their numbers and a search box', () => {
        const html = render('/hrms/attendance-imports/7', {}, run(), page([line(1)], 1));

        expect(html).toContain('2026-07-01 – 2026-07-31');
        expect(html).toContain('Employees 59 · Days 1829 · Issues 594 · Open 3');
        expect(html).toContain('All issues (3)');
        expect(html).toContain('In without Out (1)');
        expect(html).toContain('Unknown employee (1)');
        expect(html).toContain('Resolved (2)');
        expect(html).toContain('Clean (1233)');
        expect(html).toContain('ant-input-search');
        expect(html).toContain('Employee code or name');
        expect(html).toContain('1 lines');
    });

    it('renders an open line with its punches, its issue and a Correct button for a manager', () => {
        const html = render('/hrms/attendance-imports/7', {}, run(), page([line(1)], 1));

        expect(html).toContain('TST-01 — EMPLOYEE 01');
        expect(html).toContain('09:58 / —');
        expect(html).toContain('In without Out');
        expect(html).toContain('Correct');
    });

    it('offers no Correct and no Apply to a viewer', () => {
        const html = render('/hrms/attendance-imports/7', {}, run(), page([line(1)], 1), ['hrms.view']);

        expect(html).not.toContain('Correct');
        expect(html).not.toContain('Apply');
    });

    it('links an unknown employee to the Employees page', () => {
        const html = render(
            '/hrms/attendance-imports/7',
            {},
            run(),
            page([line(2, { employee_id: null, employee: undefined, employee_code: 'ZZZ-99', employee_name: 'NOBODY', issue: 'unknown_employee' })], 1),
        );

        expect(html).toContain('ZZZ-99 — NOBODY');
        expect(html).toContain('/hrms/employees?q=ZZZ-99');
    });

    it('disables Apply with the open count, and shows the download only once applied', () => {
        const review = render('/hrms/attendance-imports/7', {}, run(), page([], 0));
        expect(review).toContain('Apply (3 open)');
        expect(review).toContain('disabled');
        expect(review).not.toContain('Download month sheet');

        const applied = render(
            '/hrms/attendance-imports/7',
            {},
            run({ status: 'applied', open_count: 0, counts: { open: 0, in_no_out: 0, out_no_in: 0, no_punch: 0, unknown_employee: 0, resolved: 5, clean: 1233 }, applied_at: '2026-09-03T11:00:00+00:00' }),
            page([line(3, { resolution: 'present', resolved_check_in: '09:58', resolved_check_out: '19:30', applied_at: '2026-09-03T11:00:00+00:00' })], 1),
        );
        expect(applied).toContain('>Apply<');
        expect(applied).toContain('Download month sheet');
        expect(applied).toContain('Present');
        expect(applied).toContain('09:58 / 19:30');
    });

    it('names the term when a search matches nothing', () => {
        const html = render('/hrms/attendance-imports/7?q=zzz', { q: 'zzz' }, run(), page([], 0));

        expect(html).toContain('No lines match “zzz”.');
        expect(html).toContain('Clear search');
    });

    it('reads the chip off the URL', () => {
        const html = render('/hrms/attendance-imports/7?issue=no_punch', { issue: 'no_punch' }, run(), page([], 0));

        expect(html).toContain('No lines match this filter.');
        expect(html).toContain('ant-radio-button-wrapper-checked');
    });
});
