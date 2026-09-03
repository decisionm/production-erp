import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderToString } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import type { User } from '@/features/auth/types';
import type { BatchQualityQueue, BatchQualityQueueRow } from '@/features/quality/api';

/**
 * DOES THE QUEUE RENDER AS A PAGED, SEARCHABLE LIST — the questions a
 * typecheck cannot answer. `react-dom/server` only, as the other render
 * tests. The query cache is SEEDED under the exact key the page builds
 * (['quality', 'batch-quality-queue', <compacted params>]) and the auth
 * store is MOCKED with a user who may both see the screen (quality) and read
 * the queue (production) — the page gates on both. Mocked rather than
 * seeded with setState: zustand's hook goes through useSyncExternalStore,
 * and a server render is handed the store's INITIAL snapshot (user: null),
 * so a seeded user never reaches the page.
 *
 * What is pinned is the shape that replaced walking every page of the
 * production list: a search box, a range line from the server's own meta,
 * a no-match state that repeats the term, and the stood-down banner driven
 * by meta rather than derived from rows.
 */

vi.mock('@/lib/api', () => ({
    api: {
        get: vi.fn(async () => ({ data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0, stage_enabled: true, pending_count: null } } })),
        post: vi.fn(),
    },
}));

const qcDesk: User = {
    id: 1,
    name: 'QC Desk',
    email: 'qc@example.test',
    is_active: true,
    permissions: ['quality.view', 'quality.manage', 'production.view'],
};

vi.mock('@/features/auth/store', () => ({
    useAuthStore: (selector: (state: { user: User | null; setUser: () => void }) => unknown) =>
        selector({ user: qcDesk, setUser: () => undefined }),
}));

import ProductionQcPage from './pages/ProductionQcPage';

/** Only the fields the queue's columns read; the resource is far wider. */
const batch = (id: number, batchNumber: string): BatchQualityQueueRow =>
    ({
        id,
        batch_number: batchNumber,
        production_date: '2026-09-02',
        quantity_produced: '10000',
        gross_quantity_produced: null,
        work_center: { id: 1, code: 'MC-01', name: 'Machine 1' },
        item: { id: 5, sku: 'BTL-500-AMB', name: '500 ml Round Amber' },
        shift: { id: 1, name: 'Morning' },
        quality: { checked: false, stage_enabled: true },
        correction: { awaiting_correction: false },
    }) as unknown as BatchQualityQueueRow;

const queue = (rows: BatchQualityQueueRow[], total: number, meta: Partial<BatchQualityQueue['meta']> = {}): BatchQualityQueue => ({
    data: rows,
    meta: {
        current_page: 1,
        last_page: Math.max(1, Math.ceil(total / 20)),
        per_page: 20,
        total,
        stage_enabled: true,
        pending_count: null,
        ...meta,
    },
});

function render(path: string, key: Record<string, unknown>, seeded: BatchQualityQueue): string {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    client.setQueryData(['quality', 'batch-quality-queue', key], seeded);

    return renderToString(
        <MemoryRouter initialEntries={[path]}>
            <QueryClientProvider client={client}>
                <ProductionQcPage />
            </QueryClientProvider>
        </MemoryRouter>,
    );
}

describe('ProductionQcPage', () => {
    it('renders a search box, the rows and the range from the server meta', () => {
        const html = render('/quality/production-qc', {}, queue([batch(41, 'MC01-0902-01')], 30));

        expect(html).toContain('Search batch, product, machine');
        expect(html).toContain('1–20 of 30 batches');
        expect(html).toContain('MC01-0902-01');
        expect(html).toContain('MC-01');
        // Both doors out of the queue are still on the row.
        expect(html).toContain('Check');
        expect(html).toContain('Return to production');
    });

    it('names the term when nothing matches, and offers Clear', () => {
        const html = render('/quality/production-qc?q=zzz', { q: 'zzz' }, queue([], 0));

        expect(html).toContain('No batches match “zzz”');
        expect(html).toContain('Clear');
        expect(html).not.toContain('No batches waiting for a quality check.');
    });

    it('keeps the genuine empty wording when nothing narrows the queue', () => {
        const html = render('/quality/production-qc', {}, queue([], 0));

        expect(html).toContain('No batches waiting for a quality check.');
    });

    it('says the stage is switched off from meta, and shows no table then', () => {
        const html = render('/quality/production-qc', {}, queue([], 0, { stage_enabled: false, pending_count: 3 }));

        expect(html).toContain('The quality stage is switched off');
        expect(html).toContain('3 are waiting for approval right now');
        expect(html).not.toContain('Search batch, product, machine');
    });

    it('offers the Returned switch and tags a batch quality has sent back', () => {
        const sentBack: BatchQualityQueueRow = {
            ...batch(41, 'MC01-0902-01'),
            quality_return: {
                returned_by_name: 'Priya',
                returned_at: '2026-09-01T10:00:00+00:00',
                reason: 'Recount the boxes on this pallet.',
                times: 2,
            },
        };
        const offHtml = render('/quality/production-qc', {}, queue([batch(1, 'MC01-0902-00'), sentBack], 2));
        // 'Returned' alone would also match the row action "Return to
        // production" — the switch itself is what must be OFF here, since
        // the URL carries no returned=1.
        expect(offHtml).not.toContain('ant-switch-checked');
        expect(offHtml).toContain('Returned by Quality x2');
        // The never-returned row on the same page carries exactly no tag —
        // one match on the whole page, not one per row.
        expect(offHtml.match(/Returned by Quality/g)).toHaveLength(1);

        const onHtml = render(
            '/quality/production-qc?returned=1',
            { returned: 1 },
            queue([sentBack], 1),
        );
        expect(onHtml).toContain('ant-switch-checked');
    });
});
