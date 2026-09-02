import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderToString } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import { readListParams } from '@/lib/listParams';
import type { Paginated } from '@/lib/types';
import { RUNS_LIST_SPEC, runsQueryKey, runsServerFilters } from './lists';
import type { PayrollRun, PayrollRunListFilters, PayrollRunStatus } from './types';

/**
 * THE PAYROLL RUNS PAGE AS A LIST — search box, server-paged rows, and the
 * things an empty table may say (the material-flow render tests' pattern).
 *
 * `react-dom/server` only: no jsdom, no testing-library. The query cache is
 * SEEDED under the exact key the page derives from its URL (lists.ts), so
 * the populated state is what renders — a server render resolves no
 * promise. It lives beside `pages`, not inside it: App.routes.test.tsx
 * globs every .tsx under `pages` and asserts a default export.
 */
vi.mock('@/lib/api', () => ({
    api: {
        get: vi.fn(async () => ({ data: { data: [], meta: {} } })),
        post: vi.fn(),
    },
}));

import PayrollRunsPage from './pages/PayrollRunsPage';

const run = (id: number, year: number, month: number, status: PayrollRunStatus): PayrollRun => ({
    id,
    year,
    month,
    status,
    processed_at: status === 'draft' ? null : '2026-09-01T09:00:00+05:30',
    paid_at: status === 'paid' ? '2026-09-02T09:00:00+05:30' : null,
    created_at: '2026-08-31T09:00:00+05:30',
});

const PAGE_ONE_OF_43: Paginated<PayrollRun> = {
    data: [run(3, 2026, 8, 'draft'), run(2, 2026, 7, 'processed')],
    meta: { current_page: 1, last_page: 3, per_page: 20, total: 43 },
};

const NOTHING: Paginated<PayrollRun> = {
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
};

function renderPage(url: string, page: Paginated<PayrollRun>): string {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } });
    const query = new URLSearchParams(url.split('?')[1] ?? '');
    client.setQueryData(runsQueryKey(runsServerFilters(readListParams(query, RUNS_LIST_SPEC) as PayrollRunListFilters)), page);

    return renderToString(
        <MemoryRouter initialEntries={[url]}>
            <QueryClientProvider client={client}>
                <PayrollRunsPage />
            </QueryClientProvider>
        </MemoryRouter>,
    );
}

describe('the payroll runs page as a list', () => {
    it('renders the search box, the rows and the range the server reported', () => {
        const html = renderPage('/payroll/runs', PAGE_ONE_OF_43);

        expect(html).toContain('placeholder="Period, e.g. Aug 2026"');
        expect(html).toContain('August 2026');
        expect(html).toContain('July 2026');
        expect(html).toContain('1–20 of 43 payroll runs');
        // The row actions stay: a draft's Process, a processed run's Mark Paid.
        expect(html).toContain('Process');
        expect(html).toContain('Mark Paid');
        expect(html).toContain('View Payslips');
    });

    it('names the term when nothing matches it, and offers to clear the search', () => {
        const html = renderPage('/payroll/runs?q=zzz', NOTHING);

        expect(html).toContain('No payroll runs match “zzz”.');
        expect(html).toContain('Clear search');
        expect(html).toContain('0 payroll runs');
        expect(html).not.toContain('No payroll runs yet');
    });

    it('keeps its own wording when the list is genuinely empty', () => {
        const html = renderPage('/payroll/runs', NOTHING);

        expect(html).toContain('No payroll runs yet');
        expect(html).not.toContain('Clear search');
        expect(html).not.toContain('Clear filters');
    });

    it('offers the filter back when one status holds nothing', () => {
        const html = renderPage('/payroll/runs?status=paid', NOTHING);

        expect(html).toContain('No payroll runs match these filters.');
        expect(html).toContain('Clear filters');
    });
});
