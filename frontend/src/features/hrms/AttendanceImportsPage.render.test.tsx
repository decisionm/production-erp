import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderToString } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import type { Paginated } from '@/lib/types';
import type { AttendanceImport, AttendanceImportListParams } from './types';

/**
 * The runs list: a search box, the range line from the server meta, each
 * run linking to its review page, and the Upload button for a manager only.
 */

vi.mock('@/lib/api', () => ({
    api: {
        get: vi.fn(async () => ({ data: { data: [], meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 } } })),
        post: vi.fn(),
    },
}));

// The store is mocked rather than set: under renderToString zustand
// serves its INITIAL state (the server snapshot), so setState is unseen.
let permissions: string[] = ['hrms.manage'];
vi.mock('@/features/auth/store', () => ({
    useAuthStore: (selector: (state: unknown) => unknown) =>
        selector({ user: { id: 1, name: 'Vimal', email: 'v@example.test', is_active: true, permissions }, setUser: () => undefined }),
}));

import AttendanceImportsPage from './pages/AttendanceImportsPage';

function run(id: number, status: AttendanceImport['status']): AttendanceImport {
    return {
        id,
        source: 'pooja',
        period_from: '2026-07-01',
        period_to: '2026-07-31',
        file_name: `run-${id}.xlsx`,
        status,
        employee_count: 59,
        day_count: 1829,
        issue_count: 594,
        open_count: status === 'applied' ? 0 : 3,
        counts: { open: 3, in_no_out: 1, out_no_in: 0, no_punch: 1, unknown_employee: 1, resolved: 0, clean: 1235 },
        uploaded_by: { id: 1, name: 'Vimal' },
        applied_at: null,
        created_at: '2026-09-03T10:00:00+00:00',
    };
}

function page(rows: AttendanceImport[], total: number): Paginated<AttendanceImport> {
    return { data: rows, meta: { current_page: 1, per_page: 20, total, last_page: Math.max(1, Math.ceil(total / 20)) } };
}

function render(path: string, params: Partial<AttendanceImportListParams>, seeded: Paginated<AttendanceImport>, granted = ['hrms.manage']): string {
    permissions = granted;
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } });
    client.setQueryData(['hrms', 'attendance-imports', 'list', params], seeded);

    return renderToString(
        <QueryClientProvider client={client}>
            <MemoryRouter initialEntries={[path]}>
                <AttendanceImportsPage />
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

describe('AttendanceImportsPage', () => {
    it('renders the search box, the upload button and the range line', () => {
        const html = render('/hrms/attendance-imports', {}, page([run(1, 'review'), run(2, 'applied')], 2));

        expect(html).toContain('ant-input-search');
        expect(html).toContain('Period or file name');
        expect(html).toContain('Upload punch report');
        expect(html).toContain('2 imports');
        expect(html).toContain('/hrms/attendance-imports/1');
        expect(html).toContain('run-2.xlsx');
        expect(html).toContain('Vimal');
    });

    it('offers no upload to a viewer', () => {
        const html = render('/hrms/attendance-imports', {}, page([run(1, 'review')], 1), ['hrms.view']);

        expect(html).not.toContain('Upload punch report');
        expect(html).toContain('/hrms/attendance-imports/1');
    });

    it('names the term when a search matches nothing', () => {
        const html = render('/hrms/attendance-imports?q=2026-08', { q: '2026-08' }, page([], 0));

        expect(html).toContain('No imports match “2026-08”.');
        expect(html).toContain('Clear search');
        expect(html).not.toContain('No imports yet.');
    });
});
