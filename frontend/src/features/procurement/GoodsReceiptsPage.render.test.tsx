import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderToString } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import type { Item, Warehouse } from '@/features/inventory/types';
import { readListParams } from '@/lib/listParams';
import type { Paginated } from '@/lib/types';
import type { GoodsReceiptListParams, GoodsReceiptNote } from './types';

/**
 * THE GOODS RECEIPTS REGISTER AS A LIST — search box, server-paged rows, and
 * the things an empty table may say (the material-flow render tests'
 * pattern; see StoreIssueQueuePage.render.test.tsx for why the URL is the
 * input to every case).
 *
 * The cache is SEEDED under the exact key the page derives from its URL
 * (api.ts: goodsReceiptServerFilters → goodsReceiptsQueryKey), which is
 * also how the `?po=` deep link is pinned: found under the key
 * `{ purchase_order_id: 3 }` derives means the link the movement screens
 * write is the filter the server is asked for.
 */
vi.mock('@/lib/api', () => ({
    api: {
        get: vi.fn(async () => ({ data: { data: [], meta: {} } })),
        post: vi.fn(),
    },
}));

vi.mock('@/features/auth/store', () => ({
    useAuthStore: (selector: (state: unknown) => unknown) =>
        selector({
            user: {
                id: 41,
                name: 'Fixture Storekeeper',
                email: 'fixture@example.test',
                is_active: true,
                permissions: ['procurement.view', 'procurement.manage'],
            },
            setUser: vi.fn(),
        }),
}));

import { GOODS_RECEIPT_LIST_SPEC, goodsReceiptServerFilters, goodsReceiptsQueryKey } from './api';
import GoodsReceiptsPage from './pages/GoodsReceiptsPage';

const rmStore = { id: 1, code: 'RM', name: 'RM Store', is_active: true, tally_guid: null } as Warehouse;
const resin = { id: 5, sku: 'RM-PET', name: 'PET Resin', uom: 'Kgs' } as Item;

const receipt = (id: number, purchaseOrderId: number): GoodsReceiptNote => ({
    id,
    document_number: `GRN-${id}`,
    purchase_order_id: purchaseOrderId,
    warehouse: rmStore,
    reference: `DC-${id}`,
    received_date: '2026-09-01T09:00:00+05:30',
    notes: null,
    lines: [{ id: id * 10, purchase_order_line_id: 1, item: resin, quantity: '1000.0000' }],
    tally: null,
    tally_staging: null,
    created_at: '2026-09-01T09:05:00+05:30',
});

const PAGE_ONE_OF_43: Paginated<GoodsReceiptNote> = {
    data: [receipt(12, 3), receipt(11, 3)],
    meta: { current_page: 1, last_page: 3, per_page: 20, total: 43 },
};

const NOTHING: Paginated<GoodsReceiptNote> = {
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
};

function renderPage(url: string, page: Paginated<GoodsReceiptNote>): string {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } });
    const query = new URLSearchParams(url.split('?')[1] ?? '');
    const params = readListParams(query, GOODS_RECEIPT_LIST_SPEC) as GoodsReceiptListParams;
    client.setQueryData(goodsReceiptsQueryKey(goodsReceiptServerFilters(params)), page);

    return renderToString(
        <MemoryRouter initialEntries={[url]}>
            <QueryClientProvider client={client}>
                <GoodsReceiptsPage />
            </QueryClientProvider>
        </MemoryRouter>,
    );
}

describe('the goods receipts register as a list', () => {
    it('renders the search box, the rows and the range the server reported', () => {
        const html = renderPage('/procurement/goods-receipts', PAGE_ONE_OF_43);

        expect(html).toContain('placeholder="GRN no., PO no., vendor, item"');
        expect(html).toContain('GRN-12');
        expect(html).toContain('GRN-11');
        expect(html).toContain('1–20 of 43 goods receipts');
        // The row's way up the chain and into its own detail stay.
        expect(html).toContain('/procurement/purchase-orders?po=3');
        expect(html).toContain('View');
    });

    it('turns the ?po= deep link into the server filter and says what it is showing', () => {
        const html = renderPage('/procurement/goods-receipts?po=3', PAGE_ONE_OF_43);

        expect(html).toContain('Showing the goods receipts for PO #3 only');
        expect(html).toContain('Show all receipts');
        // Seeded under { purchase_order_id: 3 }: the rows are found only if
        // the page asked the server for exactly that.
        expect(html).toContain('GRN-12');
    });

    it('names the term when nothing matches it, and offers to clear the search', () => {
        const html = renderPage('/procurement/goods-receipts?q=zzz', NOTHING);

        expect(html).toContain('No goods receipts match “zzz”.');
        expect(html).toContain('Clear search');
        expect(html).toContain('0 goods receipts');
        expect(html).not.toContain('No goods receipts yet');
    });

    it('keeps its own wording when the register is genuinely empty', () => {
        const html = renderPage('/procurement/goods-receipts', NOTHING);

        expect(html).toContain('No goods receipts yet');
        expect(html).not.toContain('Clear search');
        expect(html).not.toContain('Clear filters');
    });

    it('offers the link back when the linked receipt is not there', () => {
        const html = renderPage('/procurement/goods-receipts?grn=7', NOTHING);

        expect(html).toContain('Showing goods receipt #7 only');
        expect(html).toContain('No goods receipts match these filters.');
        expect(html).toContain('Clear filters');
    });
});
