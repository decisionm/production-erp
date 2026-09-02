import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderToString } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import { readListParams } from '@/lib/listParams';
import type { Paginated } from '@/lib/types';
import { REQUESTS_LIST_SPEC, requestsQueryKey, requestsServerFilters } from './lists';
import type { MaterialRequest } from './types';

/**
 * THE FLOOR'S OWN MATERIAL REQUESTS PAGE AS A LIST — the same questions
 * StoreIssueQueuePage.render.test.tsx asks of the queue, for the same
 * reasons (see that file): the cache is seeded under the key the page
 * derives from its URL, and the four empty-table wordings stay distinct.
 */
vi.mock('@/lib/api', () => ({
    api: {
        get: vi.fn(async () => ({ data: { data: [], meta: {} } })),
        post: vi.fn(),
    },
}));

import MaterialRequestsPage from './pages/MaterialRequestsPage';

const request = (id: number): MaterialRequest => ({
    id,
    request_number: `MR-${id}`,
    status: 'draft',
    requested_by: 1,
    requested_by_name: 'Kumar',
    requested_at: '2026-09-01T07:00:00+05:30',
    shift_id: 1,
    shift_name: 'A',
    work_center_id: null,
    work_center_code: null,
    work_center_name: null,
    notes: null,
    submitted_at: null,
    cancelled_by_name: null,
    cancelled_at: null,
    cancelled_reason: null,
    lines: [
        {
            id: id * 10,
            item_id: 5,
            item: { id: 5, sku: 'RM-PET', name: 'Relpet PET Resin', uom: 'Kgs' },
            quantity: '500.0000',
            required_quantity: '500.0000',
            available_in_production: '0.0000',
            uom: 'Kgs',
            issued_quantity: '0.0000',
            remaining_quantity: '500.0000',
            notes: null,
        },
    ],
    can: { submit: true, cancel: true, issue: false },
});

const PAGE_ONE_OF_43: Paginated<MaterialRequest> = {
    data: [request(12), request(11)],
    meta: { current_page: 1, last_page: 3, per_page: 20, total: 43 },
};

const NOTHING: Paginated<MaterialRequest> = {
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
};

function renderPage(url: string, page: Paginated<MaterialRequest>): string {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } });
    const query = new URLSearchParams(url.split('?')[1] ?? '');
    client.setQueryData(requestsQueryKey(requestsServerFilters(readListParams(query, REQUESTS_LIST_SPEC))), page);

    return renderToString(
        <MemoryRouter initialEntries={[url]}>
            <QueryClientProvider client={client}>
                <MaterialRequestsPage />
            </QueryClientProvider>
        </MemoryRouter>,
    );
}

describe('the material requests page as a list', () => {
    it('renders the search box, the rows and the range the server reported', () => {
        const html = renderPage('/production/material-requests', PAGE_ONE_OF_43);

        expect(html).toContain('placeholder="Request no."');
        expect(html).toContain('MR-12');
        expect(html).toContain('MR-11');
        expect(html).toContain('1–20 of 43 requests');
        // A draft's Submit is a ROW action; the row must still offer it.
        expect(html).toContain('Send to store');
    });

    it('names the term when nothing matches it, and offers to clear the search', () => {
        const html = renderPage('/production/material-requests?q=zzz', NOTHING);

        expect(html).toContain('No material requests match “zzz”.');
        expect(html).toContain('Clear search');
        expect(html).toContain('0 requests');
        expect(html).not.toContain('No material requests yet');
    });

    it('keeps its own wording when the page is genuinely empty', () => {
        const html = renderPage('/production/material-requests', NOTHING);

        expect(html).toContain('No material requests yet');
        expect(html).not.toContain('Clear search');
        expect(html).not.toContain('Clear filters');
    });

    it('offers the filter back when one status holds nothing', () => {
        const html = renderPage('/production/material-requests?status=cancelled', NOTHING);

        expect(html).toContain('No requests match these filters.');
        expect(html).toContain('Clear filters');
    });
});
