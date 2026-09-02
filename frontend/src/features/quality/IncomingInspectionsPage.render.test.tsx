import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderToString } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import type { IncomingInspection } from '@/features/quality/types';
import type { Paginated } from '@/lib/types';

/**
 * DOES THE REGISTER RENDER AS A PAGED, SEARCHABLE LIST — the questions a
 * typecheck cannot answer. `react-dom/server` only, as the other render
 * tests: no jsdom, no testing-library. The query cache is SEEDED under the
 * exact key the page builds (['quality', 'incoming-inspections', <compacted
 * params>]), because a server render resolves no promise.
 *
 * The page used to ask for per_page=1000 and render every row with no pager;
 * what is pinned is the shape that replaced it — a search box, a range line
 * from the server's own meta, and a no-match state that repeats the term
 * instead of the page's "nothing recorded yet".
 *
 * IT LIVES BESIDE `pages`, NOT INSIDE IT: App.routes.test.tsx globs every
 * .tsx under `pages` and asserts a default export, which a test has not.
 */

vi.mock('@/lib/api', () => ({
    api: {
        get: vi.fn(async () => ({ data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } } })),
        post: vi.fn(),
    },
}));

import IncomingInspectionsPage from './pages/IncomingInspectionsPage';

const inspection = (id: number, sku: string, name: string): IncomingInspection => ({
    id,
    goods_receipt_note_line_id: id * 10,
    goods_receipt_note: { id: 12, document_number: 'GRN-12', tracking_number: 'TRK-RELPET-01' },
    item: { id: 5, sku, name } as IncomingInspection['item'],
    inspected_quantity: '100.0000',
    accepted_quantity: '100.0000',
    rejected_quantity: '0.0000',
    result: 'pass',
    inspection_date: '2026-09-01',
    inspected_by: 'QC Desk',
    notes: null,
    created_at: '2026-09-01T09:00:00+05:30',
});

const page = (rows: IncomingInspection[], total: number): Paginated<IncomingInspection> => ({
    data: rows,
    meta: { current_page: 1, last_page: Math.max(1, Math.ceil(total / 20)), per_page: 20, total },
});

function render(path: string, key: Record<string, unknown>, seeded: Paginated<IncomingInspection>): string {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    // Seeded, not fetched, under the key the page derives from the URL.
    client.setQueryData(['quality', 'incoming-inspections', key], seeded);

    return renderToString(
        <MemoryRouter initialEntries={[path]}>
            <QueryClientProvider client={client}>
                <IncomingInspectionsPage />
            </QueryClientProvider>
        </MemoryRouter>,
    );
}

describe('IncomingInspectionsPage', () => {
    it('renders a search box and the range from the server meta', () => {
        const html = render('/quality/incoming-inspections', {}, page([inspection(1, 'RM-PET', 'PET Resin IV-0.8')], 45));

        expect(html).toContain('Search product, GRN, reference');
        // The pager's range line, from meta — not from the rows on screen.
        expect(html).toContain('1–20 of 45 inspections');
        expect(html).toContain('RM-PET');
        // The arrival the search matches on is shown on the row.
        expect(html).toContain('GRN-12');
        expect(html).toContain('TRK-RELPET-01');
    });

    it('names the term when nothing matches, and offers Clear', () => {
        const html = render('/quality/incoming-inspections?q=zebra', { q: 'zebra' }, page([], 0));

        expect(html).toContain('No inspections match “zebra”');
        expect(html).toContain('Clear');
        expect(html).not.toContain('No incoming inspections recorded yet.');
        // The box shows what was searched.
        expect(html).toContain('value="zebra"');
    });

    it('keeps the genuine empty wording when nothing narrows the list', () => {
        const html = render('/quality/incoming-inspections', {}, page([], 0));

        expect(html).toContain('No incoming inspections recorded yet.');
        expect(html).not.toContain('No inspections match');
    });

    it('reads a result filter from the URL into the same key the server is asked with', () => {
        const html = render('/quality/incoming-inspections?result=fail', { result: 'fail' }, page([inspection(2, 'CAP-28', '28mm Cap')], 1));

        expect(html).toContain('CAP-28');
        expect(html).toContain('1 inspections');
    });
});
