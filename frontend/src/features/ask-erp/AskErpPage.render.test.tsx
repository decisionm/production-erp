import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderToString } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import type { AskErpConversation, AskErpConversationSummary, CatalogueEntry } from './types';

/**
 * The Ask ERP page: a question, its answer, and the evidence under it —
 * rendered from seeded query data, no network.
 *
 * What these pin is the redesign's intent. The page opens with a few
 * QUESTIONS rather than the schema; a turn is a result slip headed by its own
 * question rather than a chat bubble; past conversations live behind a button
 * instead of taking a quarter of the width.
 */

vi.mock('@/lib/api', () => ({
    api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

import AskErpPage from './pages/AskErpPage';

const catalogue: { data: CatalogueEntry[]; examples: string[]; configured: boolean } = {
    data: [{ table: 'employees', label: 'Employees', module: 'hrms' }],
    examples: [
        'How much stock do we have?',
        'Output by machine',
        'Which batches are awaiting quality?',
        'Who is absent today?',
        'Deliveries in the last 30 days',
    ],
    configured: true,
};

const summaries: AskErpConversationSummary[] = [
    { id: 1, title: 'Staff headcount', message_count: 2, updated_at: '2026-09-03T10:00:00Z' },
];

const thread: AskErpConversation = {
    ...summaries[0],
    messages: [
        { id: 1, role: 'user', question: 'employees by status', sql: null, answer: null, tables_used: [], row_count: null, error: null, created_at: null },
        {
            id: 2,
            role: 'assistant',
            question: 'employees by status',
            sql: 'SELECT e.status, COUNT(*) AS n FROM employees e GROUP BY e.status LIMIT 200',
            answer: '2 statuses; active first.',
            tables_used: ['employees'],
            row_count: 2,
            error: null,
            created_at: null,
        },
    ],
};

function render(path: string, configured = true): string {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } });
    client.setQueryData(['ask-erp', 'catalogue'], { ...catalogue, configured });
    client.setQueryData(['ask-erp', 'conversations', { q: '', page: 1 }], {
        data: summaries,
        meta: { current_page: 1, per_page: 20, total: 1, last_page: 1 },
    });
    client.setQueryData(['ask-erp', 'conversation', 1], thread);

    return renderToString(
        <QueryClientProvider client={client}>
            <MemoryRouter initialEntries={[path]}>
                <AskErpPage />
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

describe('AskErpPage', () => {
    it('opens with a few questions to click, not with the schema', () => {
        const html = render('/ask-erp');

        expect(html).toContain('Ask about stock, orders, production or attendance.');
        expect(html).toContain('How much stock do we have?');
        expect(html).toContain('Output by machine');
    });

    it('offers four suggestions, not the whole rule book', () => {
        const html = render('/ask-erp');

        // Five are served; the fifth must not be on screen. A prompt, not a menu.
        expect(html).not.toContain('Deliveries in the last 30 days');
    });

    it('never lists the tables — 122 chips told a supervisor nothing', () => {
        const html = render('/ask-erp');

        expect(html).not.toContain('Tables you can query');
        expect(html).not.toContain('Employees');
    });

    it('heads each answer with its own question and shows what it read', () => {
        const html = render('/ask-erp?conversation=1');

        expect(html).toContain('employees by status');
        expect(html).toContain('2 statuses; active first.');
        expect(html).toContain('employees');
        expect(html).toContain('2 rows');
        expect(html).toContain('Show SQL');
    });

    it('offers to run a history turn again, since its rows were never stored', () => {
        const html = render('/ask-erp?conversation=1');

        expect(html).toContain('Run again');
    });

    it('hides the suggestions once a thread has answers', () => {
        const html = render('/ask-erp?conversation=1');

        expect(html).not.toContain('Ask about stock, orders, production or attendance.');
    });

    it('keeps past conversations behind a button rather than in a column', () => {
        const html = render('/ask-erp');

        expect(html).toContain('Past questions');
        expect(html).toContain('New question');
    });

    it('says so when the server has no model configured', () => {
        expect(render('/ask-erp', false)).toContain('Ask ERP is not configured');
        expect(render('/ask-erp', true)).not.toContain('Ask ERP is not configured');
    });
});
