import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderToString } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import { readListParams } from '@/lib/listParams';
import type { Paginated } from '@/lib/types';
import { QUEUE_LIST_SPEC, queueQueryKey, queueServerFilters } from './lists';
import type { MaterialRequest } from './types';

/**
 * THE STORE'S QUEUE AS A LIST — search box, server-paged rows, and the four
 * things an empty table may say.
 *
 * `react-dom/server` only, as StoreProductionPage.render.test.tsx does: no
 * jsdom, no testing-library. The query cache is SEEDED under the exact key
 * the page derives from its URL (lists.ts), so the populated state is what
 * renders — a server render resolves no promise. It lives beside `pages`,
 * not inside it, for the reason that file gives.
 *
 * WHY THE URL IS THE INPUT to every case here: the page keeps no filter in
 * component state any more. If the key it derives from `?q=zzz` ever
 * drifts from what lists.ts says, the seeded page is not found, the table
 * renders empty, and the populated assertions fail — which is the drift
 * being caught, not a brittle test.
 */
vi.mock('@/lib/api', () => ({
    api: {
        get: vi.fn(async () => ({ data: { data: [], meta: {} } })),
        post: vi.fn(),
    },
}));

import StoreIssueQueuePage from './pages/StoreIssueQueuePage';

const request = (id: number): MaterialRequest => ({
    id,
    request_number: `MR-${id}`,
    status: 'submitted',
    requested_by: 1,
    requested_by_name: 'Kumar',
    requested_at: '2026-09-01T07:00:00+05:30',
    shift_id: 1,
    shift_name: 'A',
    work_center_id: null,
    work_center_code: null,
    work_center_name: null,
    notes: null,
    submitted_at: '2026-09-01T07:05:00+05:30',
    cancelled_by_name: null,
    cancelled_at: null,
    cancelled_reason: null,
    lines: [
        {
            id: id * 10,
            item_id: 5,
            item: { id: 5, sku: 'RM-PET', name: 'Relpet PET Resin', uom: 'Kgs' },
            quantity: '500.0000',
            required_quantity: null,
            available_in_production: null,
            uom: 'Kgs',
            issued_quantity: '0.0000',
            remaining_quantity: '500.0000',
            notes: null,
        },
    ],
    can: { submit: false, cancel: true, issue: true },
});

const PAGE_ONE_OF_43: Paginated<MaterialRequest> = {
    data: [request(12), request(11)],
    meta: { current_page: 1, last_page: 3, per_page: 20, total: 43 },
};

const NOTHING: Paginated<MaterialRequest> = {
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
};

function renderQueue(url: string, page: Paginated<MaterialRequest>): string {
    // staleTime Infinity: seeded data must not read as "fetching" on the
    // server render, or the table draws its spinner over the rows.
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } });
    const query = new URLSearchParams(url.split('?')[1] ?? '');
    client.setQueryData(queueQueryKey(queueServerFilters(readListParams(query, QUEUE_LIST_SPEC))), page);

    return renderToString(
        <MemoryRouter initialEntries={[url]}>
            <QueryClientProvider client={client}>
                <StoreIssueQueuePage embedded />
            </QueryClientProvider>
        </MemoryRouter>,
    );
}

describe('the store issue queue as a list', () => {
    it('renders the search box, the rows and the range the server reported', () => {
        const html = renderQueue('/inventory/store-production?tab=issues', PAGE_ONE_OF_43);

        expect(html).toContain('placeholder="Request no."');
        expect(html).toContain('MR-12');
        expect(html).toContain('MR-11');
        // From meta, not from the two rows on screen.
        expect(html).toContain('1–20 of 43 requests');
        // The default choice is on the dropdown, though it is absent from the URL.
        expect(html).toContain('Still to issue');
    });

    it('names the term when nothing matches it, and offers to clear the search', () => {
        const html = renderQueue('/inventory/store-production?tab=issues&q=zzz', NOTHING);

        expect(html).toContain('No requests match “zzz”.');
        expect(html).toContain('Clear search');
        expect(html).toContain('0 requests');
        expect(html).not.toContain('Nothing is still to issue');
    });

    it('keeps the queue’s own wording when the default view is genuinely empty', () => {
        const html = renderQueue('/inventory/store-production?tab=issues', NOTHING);

        expect(html).toContain('Nothing is still to issue');
        expect(html).not.toContain('Clear search');
        expect(html).not.toContain('Clear filters');
    });

    it('offers the filters back when a narrowed view is empty', () => {
        const html = renderQueue('/inventory/store-production?tab=issues&status=cancelled', NOTHING);

        expect(html).toContain('No requests match these filters.');
        expect(html).toContain('Clear filters');
    });
});
