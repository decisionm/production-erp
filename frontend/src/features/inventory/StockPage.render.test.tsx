import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderToString } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import { readListParams } from '@/lib/listParams';
import { TABLE_STICKY } from '@/lib/tableProps';
import type { Paginated } from '@/lib/types';
import { STOCK_LIST_SPEC, stockListRequest } from '@/features/inventory/stockList';
import type { Item, StockBalance, Warehouse } from '@/features/inventory/types';

/**
 * DOES THE STOCK LIST RENDER ITS SEARCH, ITS RANGE AND ITS NO-MATCH STATE —
 * the questions a typecheck cannot answer.
 *
 * `react-dom/server` only, as the other render tests here: no jsdom, no
 * testing-library. The query cache is SEEDED under the exact key the page
 * reads — built by the same `stockListRequest` the page uses, from the same
 * URL — so the populated state is what renders. A server render never
 * resolves a promise, and the empty state would hide every bug below.
 *
 * IT LIVES BESIDE THE `pages` DIRECTORY, NOT INSIDE IT: App.routes.test.tsx
 * globs every .tsx under `pages` and asserts a default export.
 *
 * Every figure here is synthetic.
 */

vi.mock('@/lib/api', () => ({
    api: { get: vi.fn(async () => ({ data: { data: [] } })), post: vi.fn() },
}));

import StockPage from './pages/StockPage';

const rm = { id: 1, code: 'RM', name: 'Raw Material Store', is_active: true, tally_guid: null } as Warehouse;
const fg = { id: 2, code: 'FG', name: 'Finished Goods Store', is_active: true, tally_guid: null } as Warehouse;

function item(id: number, sku: string, name: string, displayName: string | null = null): Item {
    return {
        id,
        sku,
        name,
        display_name: displayName,
        uom: 'Nos.',
        tracking_type: 'none',
        is_active: true,
    } as unknown as Item;
}

function balance(id: number, row: Item, warehouse: Warehouse, quantity: string): StockBalance {
    return { id, item: row, warehouse, quantity };
}

const meta = (total: number, current_page = 1, per_page = 50): Paginated<unknown>['meta'] => ({
    current_page,
    per_page,
    total,
    last_page: Math.max(1, Math.ceil(total / per_page)),
});

const twoRows: Paginated<StockBalance> = {
    data: [
        balance(11, item(1, 'BTL-1000', 'Pet Bottle 1000ml Tally', '1 Litre Bottle'), rm, '120.0000'),
        balance(12, item(2, 'CAP-28', 'Cap 28mm'), fg, '-5.0000'),
    ],
    meta: meta(2),
};

function render(path: string, seeded: Paginated<StockBalance>): string {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const search = new URLSearchParams(path.split('?')[1] ?? '');
    // Seeded, not fetched: the page's own key, from the page's own URL.
    client.setQueryData(['inventory', 'stock-balances', 'list', stockListRequest(readListParams(search, STOCK_LIST_SPEC))], seeded);
    client.setQueryData(['inventory', 'warehouses', 'all'], { data: [rm, fg], meta: meta(2) });

    return renderToString(
        <MemoryRouter initialEntries={[path]}>
            <QueryClientProvider client={client}>
                <StockPage />
            </QueryClientProvider>
        </MemoryRouter>,
    );
}

describe('the stock page', () => {
    it('renders the search box and the warehouse filter', () => {
        const html = render('/inventory/stock', twoRows);

        expect(html).toContain('SKU, name or Tally name');
        expect(html).toContain('Any warehouse');
    });

    it('states the range from the server’s meta, not the rows in hand', () => {
        expect(render('/inventory/stock', twoRows)).toContain('2 balances');

        // Page 2 of 143 with two rows seeded: the range is the server's.
        const pageTwo: Paginated<StockBalance> = { ...twoRows, meta: meta(143, 2) };
        expect(render('/inventory/stock?page=2', pageTwo)).toContain('51–100 of 143 balances');
    });

    it('labels each row and flags a negative balance', () => {
        const html = render('/inventory/stock', twoRows);

        // itemLabel prefers display_name; where sku and name differ both show.
        expect(html).toContain('BTL-1000 — 1 Litre Bottle');
        expect(html).toContain('CAP-28 — Cap 28mm');
        expect(html).toContain('ant-typography-danger');
    });

    it('names the term when nothing matches, and offers Clear', () => {
        const html = render('/inventory/stock?q=zzz', { data: [], meta: meta(0) });

        expect(html).toContain('No balances match “zzz”.');
        expect(html).toContain('0 matching balances');
        expect(html).toMatch(/>Clear</);
    });

    it('names the warehouse too when one is chosen', () => {
        const html = render('/inventory/stock?q=zzz&warehouse_id=2', { data: [], meta: meta(0) });

        expect(html).toContain('No balances match “zzz” in FG.');
    });

    it('freezes the header below the 64px app bar, never under it', () => {
        expect(TABLE_STICKY).toEqual({ offsetHeader: 64 });
    });
});
