import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderToString } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import type { Paginated } from '@/lib/types';
import type { LeaveRequest, LeaveRequestListParams } from './types';

/**
 * The leave request list renders as a searchable, paged list — same shape
 * and reasoning as EmployeesPage.render.test.tsx. The employee and
 * leave-type picker queries are left unseeded: the toolbar and the form
 * must render with empty pickers rather than wait on them.
 */

vi.mock('@/lib/api', () => ({
    api: {
        get: vi.fn(async () => ({ data: { data: [], meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 } } })),
        post: vi.fn(),
    },
}));

import LeaveRequestsPage from './pages/LeaveRequestsPage';

function request(id: number, employeeName: string): LeaveRequest {
    return {
        id,
        employee: { id, name: employeeName },
        leave_type: { id: 1, code: 'CL', name: 'Casual leave', default_annual_days: '12.00', is_active: true, created_at: '2026-01-01T00:00:00+05:30' },
        start_date: '2026-08-10',
        end_date: '2026-08-10',
        days: '1.00',
        reason: null,
        status: 'pending',
        approved_by: null,
        decided_at: null,
        created_at: '2026-08-01T09:00:00+05:30',
    };
}

function page(rows: LeaveRequest[], total: number): Paginated<LeaveRequest> {
    return { data: rows, meta: { current_page: 1, per_page: 20, total, last_page: Math.max(1, Math.ceil(total / 20)) } };
}

function render(path: string, params: Partial<LeaveRequestListParams>, seeded: Paginated<LeaveRequest>): string {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } });
    client.setQueryData(['hrms', 'leave-requests', 'list', params], seeded);

    return renderToString(
        <QueryClientProvider client={client}>
            <MemoryRouter initialEntries={[path]}>
                <LeaveRequestsPage />
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

describe('LeaveRequestsPage', () => {
    it('renders a search box that goes through the employee', () => {
        const html = render('/hrms/leave-requests', {}, page([request(1, 'Anand Kumar')], 1));

        expect(html).toContain('ant-input-search');
        expect(html).toContain('Employee code, name, department');
    });

    it('states the range from the server meta', () => {
        const html = render('/hrms/leave-requests', {}, page([request(1, 'Anand Kumar'), request(2, 'Bala Murugan')], 27));

        expect(html).toContain('Anand Kumar');
        expect(html).toContain('Casual leave');
        expect(html).toContain('1–20 of 27 leave requests');
    });

    it('names the term when a search matches nothing, and offers to clear it', () => {
        const html = render('/hrms/leave-requests?q=zzz', { q: 'zzz' }, page([], 0));

        expect(html).toContain('No leave requests match “zzz”.');
        expect(html).toContain('Clear search');
        expect(html).not.toContain('No leave requests yet.');
    });

    it('keeps a status filter apart from a search in the empty state', () => {
        const html = render('/hrms/leave-requests?status=rejected', { status: 'rejected' }, page([], 0));

        expect(html).toContain('No leave requests match these filters.');
        expect(html).toContain('Clear filters');
    });
});
