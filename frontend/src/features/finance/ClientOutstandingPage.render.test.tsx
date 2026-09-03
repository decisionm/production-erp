import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderToString } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import ClientOutstandingPage from '@/features/finance/pages/ClientOutstandingPage';
import type { ClientOutstandingReport } from '@/features/finance/types';

/**
 * DOES THE SCREEN ACTUALLY RENDER, AND DO THE TOTALS SIT UNDER THEIR OWN
 * COLUMNS — the questions a typecheck cannot answer.
 *
 * `react-dom/server` only, as StoreProductionPage.render.test.tsx does: no
 * jsdom, no testing-library. The query cache is SEEDED rather than fetched, so
 * the populated state is what renders — a server render never resolves a
 * promise, and the empty state would hide every alignment bug below.
 *
 * IT LIVES BESIDE THE `pages` DIRECTORY, NOT INSIDE IT: App.routes.test.tsx
 * globs every .tsx under `pages` and asserts a default export, which a test
 * file has not.
 *
 * WHY THE COLUMN-COUNT ASSERTION EARNS ITS PLACE. The table is `expandable`,
 * and an expandable antd table renders an EXTRA leading cell in every body and
 * header row for the expand control — but the summary row is written by hand,
 * cell by cell. Get that wrong and every total renders one column to the left
 * of the heading it belongs to: outstanding money printed under "Overdue",
 * with nothing throwing, nothing failing to typecheck, and the page looking
 * entirely normal. Counting header against footer is the only cheap way to see
 * it.
 *
 * Every figure here is synthetic (FC-06).
 */

vi.mock('@/lib/api', () => ({
    api: { get: vi.fn(async () => ({ data: { data: null } })), post: vi.fn() },
}));

const report: ClientOutstandingReport = {
    as_of: '2026-09-30',
    synced_at: '2026-09-30T09:00:00+05:30',
    company: 'SYNTHETIC POLYMERS PVT LTD',
    clients: [
        {
            customer_id: null,
            customer_code: null,
            customer_name: null,
            customer_email: null,
            party_ledger_name: 'Northwind Traders',
            party_ledger_guid: 'ledger-guid-northwind',
            is_linked: false,
            balance_only: false,
            outstanding_amount: '10000.0000',
            overdue_amount: '10000.0000',
            pending_order_amount: '26960.0000',
            pending_order_count: 1,
            pending_orders_without_value: 0,
            bill_count: 1,
            oldest_overdue_days: 29,
            ageing: {
                current: '0.0000',
                d1_30: '10000.0000',
                d31_60: '0.0000',
                d61_90: '0.0000',
                d90_plus: '0.0000',
                no_due_date: '0.0000',
            },
            bills: [],
            pending_orders: [],
        },
    ],
    totals: {
        clients: 1,
        outstanding_amount: '10000.0000',
        overdue_amount: '10000.0000',
        pending_order_amount: '26960.0000',
        bill_count: 1,
        pending_order_count: 1,
        ageing: {
            current: '0.0000',
            d1_30: '10000.0000',
            d31_60: '0.0000',
            d61_90: '0.0000',
            d90_plus: '0.0000',
            no_due_date: '0.0000',
        },
    },
};

function renderPage(data: ClientOutstandingReport = report): string {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    // Seeded, not fetched: a server render resolves no promise.
    queryClient.setQueryData(['finance', 'client-outstanding'], data);

    return renderToString(
        <QueryClientProvider client={queryClient}>
            <MemoryRouter>
                <ClientOutstandingPage />
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

/** Cells in the first row of a section, by tag. */
function cellCount(html: string, section: 'thead' | 'tfoot'): number {
    const block = new RegExp(`<${section}[\\s\\S]*?</${section}>`).exec(html)?.[0] ?? '';
    const firstRow = /<tr[\s\S]*?<\/tr>/.exec(block)?.[0] ?? '';

    return (firstRow.match(/<(th|td)[\s>]/g) ?? []).length;
}

describe('ClientOutstandingPage', () => {
    it('renders the populated position', () => {
        const html = renderPage();

        expect(html).toContain('Northwind Traders');
        // The client-wise pending-purchase figure and the outstanding one.
        expect(html).toContain('26,960.00');
        expect(html).toContain('10,000.00');
        // The as-at banner: the page must never imply live figures.
        expect(html).toContain('2026-09-30');
    });

    it('says the client is not linked to an ERP customer rather than dropping it', () => {
        // An unlinked Tally ledger that owes money is the case most likely to
        // be quietly filtered out, and the one that must not be.
        expect(renderPage()).toContain('Not linked to an ERP customer');
    });

    it('puts every total under its own column', () => {
        const html = renderPage();

        // The expand control adds a leading cell to the header; the summary
        // row is hand-written and must match it or every figure shifts left.
        expect(cellCount(html, 'tfoot')).toBe(cellCount(html, 'thead'));
    });

    it('shows an outstanding-days figure rather than a bare zero', () => {
        // React separates adjacent interpolated text nodes with an HTML
        // comment on a server render, so the tag reads `29<!-- --> days`.
        expect(renderPage()).toMatch(/29(<!-- -->)?\s*days/);
    });

    it('names a balance-only pull instead of inventing bill detail', () => {
        const balanceOnly: ClientOutstandingReport = {
            ...report,
            clients: [{ ...report.clients[0], balance_only: true, bill_count: 0, bills: [] }],
            totals: { ...report.totals, bill_count: 0 },
        };

        const html = renderPage(balanceOnly);

        expect(html).toContain('Tally supplied client balances without invoice detail');
        expect(html).toContain('Balance only');
        expect(html).toContain('Not available');
        expect(html).toContain('No bill detail');
    });
});
