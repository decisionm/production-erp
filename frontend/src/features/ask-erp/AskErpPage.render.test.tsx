import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderToString } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import type { AskErpConversation, AskErpConversationSummary, CatalogueEntry } from './types';

/**
 * The Ask ERP page renders the tables this login may ask about, this
 * login's conversations, and the open thread — from seeded query data, no
 * network. Same shape as the HRMS render tests.
 */

vi.mock('@/lib/api', () => ({
    api: { get: vi.fn(), post: vi.fn() },
}));

import AskErpPage from './pages/AskErpPage';

const catalogue: { data: CatalogueEntry[]; examples: string[]; configured: boolean } = {
    data: [{ table: 'employees', label: 'Employees', module: 'hrms' }],
    examples: ['Who is absent today?', 'How much stock do we have?'],
    configured: true,
};

const summaries: AskErpConversationSummary[] = [{ id: 1, title: 'Staff headcount', message_count: 2, updated_at: '2026-09-03T10:00:00Z' }];

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
    it('leads with questions to click, not with the table list', () => {
        const html = render('/ask-erp?conversation=1');

        // The page used to open with one chip per table — 122 of them for an
        // Administrator. Questions are what a person can act on; the table
        // list stays, behind its count.
        expect(html).toContain('Who is absent today?');
        expect(html).toContain('How much stock do we have?');
        expect(html).toContain('Tables you can query (1)');
    });

    it('renders the conversation list and the open thread', () => {
        const html = render('/ask-erp?conversation=1');

        expect(html).toContain('Staff headcount');
        expect(html).toContain('2 messages');
        expect(html).toContain('employees by status');
        expect(html).toContain('2 statuses; active first.');
        expect(html).toContain('Show SQL');
        expect(html).toContain('2 rows');
    });

    it('invites a question when no conversation is open', () => {
        const html = render('/ask-erp');

        expect(html).toContain('Ask a question below.');
        expect(html).not.toContain('Ask ERP is not configured');
    });

    it('says so when the server has no API key', () => {
        expect(render('/ask-erp', false)).toContain('Ask ERP is not configured on this server.');
    });
});
