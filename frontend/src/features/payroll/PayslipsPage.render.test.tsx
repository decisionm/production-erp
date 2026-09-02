import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderToString } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import { readListParams } from '@/lib/listParams';
import type { Paginated } from '@/lib/types';
import { PAYSLIPS_LIST_SPEC, payslipsQueryKey, payslipsServerFilters } from './lists';
import type { PayrollRun, Payslip, PayslipListFilters } from './types';

/**
 * THE PAYSLIPS PAGE AS A LIST — the same questions PayrollRunsPage.render
 * .test.tsx asks of the runs, plus the one this page has always carried:
 * the runs page's `?payroll_run_id=` link must still narrow the list.
 */
vi.mock('@/lib/api', () => ({
    api: {
        get: vi.fn(async () => ({ data: { data: [], meta: {} } })),
        post: vi.fn(),
    },
}));

import PayslipsPage from './pages/PayslipsPage';

const payslip = (id: number, name: string): Payslip => ({
    id,
    payroll_run_id: 3,
    employee: { id, name },
    gross_earnings: '1.0000',
    total_deductions: '0.0000',
    net_pay: '1.0000',
    lines: [{ id: id * 10, label: 'Regression basic', type: 'earning', amount: '1.0000' }],
});

const RUNS: Paginated<PayrollRun> = {
    data: [{ id: 3, year: 2026, month: 8, status: 'processed', processed_at: null, paid_at: null, created_at: '2026-08-31T09:00:00+05:30' }],
    meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 },
};

const PAGE_ONE_OF_43: Paginated<Payslip> = {
    data: [payslip(12, 'Anitha Kumar'), payslip(11, 'Bala Murugan')],
    meta: { current_page: 1, last_page: 3, per_page: 20, total: 43 },
};

const NOTHING: Paginated<Payslip> = {
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
};

function renderPage(url: string, page: Paginated<Payslip>): string {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } });
    const query = new URLSearchParams(url.split('?')[1] ?? '');
    client.setQueryData(payslipsQueryKey(payslipsServerFilters(readListParams(query, PAYSLIPS_LIST_SPEC) as PayslipListFilters)), page);
    client.setQueryData(['payroll', 'runs', 'all'], RUNS);

    return renderToString(
        <MemoryRouter initialEntries={[url]}>
            <QueryClientProvider client={client}>
                <PayslipsPage />
            </QueryClientProvider>
        </MemoryRouter>,
    );
}

describe('the payslips page as a list', () => {
    it('renders the search box, the rows and the range the server reported', () => {
        const html = renderPage('/payroll/payslips', PAGE_ONE_OF_43);

        expect(html).toContain('placeholder="Employee name or code"');
        expect(html).toContain('Anitha Kumar');
        expect(html).toContain('Bala Murugan');
        expect(html).toContain('1–20 of 43 payslips');
    });

    it('still narrows by the run the runs page linked to', () => {
        // Seeded under the key `?payroll_run_id=3` derives: found means the
        // URL the runs page writes is the filter the server is asked for.
        const html = renderPage('/payroll/payslips?payroll_run_id=3', PAGE_ONE_OF_43);

        expect(html).toContain('Anitha Kumar');
        expect(html).toContain('August 2026');
    });

    it('names the term when nothing matches it, and offers to clear the search', () => {
        const html = renderPage('/payroll/payslips?q=zzz', NOTHING);

        expect(html).toContain('No payslips match “zzz”.');
        expect(html).toContain('Clear search');
        expect(html).toContain('0 payslips');
        expect(html).not.toContain('No payslips yet');
    });

    it('keeps its own wording when the list is genuinely empty', () => {
        const html = renderPage('/payroll/payslips', NOTHING);

        expect(html).toContain('No payslips yet');
        expect(html).not.toContain('Clear search');
    });

    it('offers the filter back when one run holds nothing', () => {
        const html = renderPage('/payroll/payslips?payroll_run_id=3', NOTHING);

        expect(html).toContain('No payslips match these filters.');
        expect(html).toContain('Clear filters');
    });
});
