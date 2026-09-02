import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderToString } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import type { Paginated } from '@/lib/types';
import type { Attendance, AttendanceListParams } from './types';

/**
 * The attendance list renders as a searchable, paged list with a date
 * range — same shape and reasoning as EmployeesPage.render.test.tsx. The
 * range picker reads `from`/`to` off the URL, so a pasted link to a month
 * shows that month in the picker.
 */

vi.mock('@/lib/api', () => ({
    api: {
        get: vi.fn(async () => ({ data: { data: [], meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 } } })),
        post: vi.fn(),
    },
}));

import AttendancePage from './pages/AttendancePage';

function mark(id: number, employeeName: string, date: string): Attendance {
    return {
        id,
        employee: { id, name: employeeName },
        date,
        status: 'present',
        check_in: null,
        check_out: null,
        notes: null,
    };
}

function page(rows: Attendance[], total: number): Paginated<Attendance> {
    return { data: rows, meta: { current_page: 1, per_page: 20, total, last_page: Math.max(1, Math.ceil(total / 20)) } };
}

function render(path: string, params: Partial<AttendanceListParams>, seeded: Paginated<Attendance>): string {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } });
    client.setQueryData(['hrms', 'attendance', 'list', params], seeded);

    return renderToString(
        <QueryClientProvider client={client}>
            <MemoryRouter initialEntries={[path]}>
                <AttendancePage />
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

describe('AttendancePage', () => {
    it('renders a search box and a date range', () => {
        const html = render('/hrms/attendance', {}, page([mark(1, 'Anand Kumar', '2026-08-10')], 1));

        expect(html).toContain('ant-input-search');
        expect(html).toContain('Employee code, name, department');
        expect(html).toContain('ant-picker-range');
    });

    it('states the range from the server meta', () => {
        const html = render(
            '/hrms/attendance',
            {},
            page([mark(1, 'Anand Kumar', '2026-08-10'), mark(2, 'Bala Murugan', '2026-08-10')], 312),
        );

        expect(html).toContain('2026-08-10');
        expect(html).toContain('1–20 of 312 attendance records');
    });

    it('names the term when a search matches nothing, and offers to clear it', () => {
        const html = render('/hrms/attendance?q=zzz', { q: 'zzz' }, page([], 0));

        expect(html).toContain('No attendance records match “zzz”.');
        expect(html).toContain('Clear search');
        expect(html).not.toContain('No attendance recorded yet.');
    });

    it('shows the URL date range in the picker', () => {
        const html = render(
            '/hrms/attendance?from=2026-08-01&to=2026-08-31',
            { from: '2026-08-01', to: '2026-08-31' },
            page([], 0),
        );

        expect(html).toContain('value="2026-08-01"');
        expect(html).toContain('value="2026-08-31"');
        expect(html).toContain('No attendance records match these filters.');
    });
});
