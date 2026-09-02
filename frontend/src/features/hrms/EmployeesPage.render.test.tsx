import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderToString } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import type { Paginated } from '@/lib/types';
import type { Employee, EmployeeListParams } from './types';

/**
 * DOES THE EMPLOYEE LIST RENDER AS A SEARCHABLE, PAGED LIST — the questions
 * a typecheck cannot answer. `react-dom/server` only, as the other render
 * tests: no jsdom, no testing-library. The query cache is SEEDED under the
 * exact key the page builds from its URL, so the populated state is what
 * renders. It lives beside `pages`, not inside it (App.routes.test.tsx
 * asserts a default export on every file there).
 *
 * What it pins: the search box exists; the range line comes from the
 * server's META, not the rows on screen (two rows, "of 43"); a search that
 * matched nothing names the term and offers Clear, and is not mistaken for
 * an empty master. Every name here is synthetic.
 */

vi.mock('@/lib/api', () => ({
    api: {
        get: vi.fn(async () => ({ data: { data: [], meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 } } })),
        post: vi.fn(),
        put: vi.fn(),
    },
}));

import EmployeesPage from './pages/EmployeesPage';

function employee(id: number, code: string, name: string): Employee {
    return {
        id,
        employee_code: code,
        name,
        email: null,
        phone: null,
        date_of_birth: null,
        date_of_joining: '2026-01-05',
        designation: 'Operator',
        department: 'Production',
        status: 'active',
        can: { edit: false, activate: false, archive: false, delete: false },
        created_at: '2026-01-05T09:00:00+05:30',
    };
}

function page(rows: Employee[], total: number): Paginated<Employee> {
    return { data: rows, meta: { current_page: 1, per_page: 20, total, last_page: Math.max(1, Math.ceil(total / 20)) } };
}

function render(path: string, params: Partial<EmployeeListParams>, seeded: Paginated<Employee>): string {
    // staleTime Infinity: a seeded, fresh answer is not "fetching" on the
    // server, so the table renders its rows and not a spinner.
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } });
    client.setQueryData(['hrms', 'employees', 'list', params], seeded);

    return renderToString(
        <QueryClientProvider client={client}>
            <MemoryRouter initialEntries={[path]}>
                <EmployeesPage />
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

describe('EmployeesPage', () => {
    it('renders a search box over the whole master', () => {
        const html = render('/hrms/employees', {}, page([employee(1, 'EMP-001', 'Anand Kumar')], 1));

        expect(html).toContain('ant-input-search');
        expect(html).toContain('Code, name, department, designation');
    });

    it('states the range from the server meta, not from the rows on screen', () => {
        const html = render(
            '/hrms/employees',
            {},
            page([employee(1, 'EMP-001', 'Anand Kumar'), employee(2, 'EMP-002', 'Bala Murugan')], 43),
        );

        expect(html).toContain('EMP-001');
        expect(html).toContain('Bala Murugan');
        expect(html).toContain('1–20 of 43 employees');
    });

    it('names the term when a search matches nothing, and offers to clear it', () => {
        const html = render('/hrms/employees?q=zzz', { q: 'zzz' }, page([], 0));

        expect(html).toContain('No employees match “zzz”.');
        expect(html).toContain('Clear search');
        expect(html).toContain('0 employees');
        expect(html).not.toContain('No employees yet.');
    });

    it('says the master is empty only when nothing narrows it', () => {
        const html = render('/hrms/employees', {}, page([], 0));

        expect(html).toContain('No employees yet.');
        expect(html).not.toContain('Clear search');
    });
});
