import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderToString } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { User } from '@/features/auth/types';
import ClientOutstandingPage from '@/features/finance/pages/ClientOutstandingPage';
import type { ClientOutstandingReport } from '@/features/finance/types';

/**
 * IS THE IMPORT CONTROL ACTUALLY REACHABLE IN THE STATE IT EXISTS FOR?
 *
 * `outstandingImport.test.ts` pins the predicate; this pins the WIRING, which
 * is a different question and the one that bites. The control exists for a
 * page with nothing on it, and the page has an early return above the header
 * for a read that never came back — so "the button draws" and "the button
 * draws when the position is empty" are not the same assertion.
 *
 * THE POSITION SEEDED HERE IS THE EMPTY ONE, deliberately: `as_of: null`,
 * no clients, every total zero. That is what the screen looks like when the
 * factory PC's agent has never delivered a position, which is the exact
 * circumstance the upload is for. Seeding a populated report would prove the
 * button renders on a page that does not need it.
 *
 * WHY THE STORE IS MOCKED RATHER THAN SET. zustand 5 hands
 * `() => selector(api.getInitialState())` to `useSyncExternalStore` as its
 * server snapshot (node_modules/zustand/react.js), so under `renderToString`
 * React reads the store's INITIAL state and `useAuthStore.setState(...)` is
 * invisible no matter what it is set to. A gate test written that way cannot
 * pass for a permitted user — not because the gate is wrong, but because the
 * render never observes the write. The repo has no jsdom and no
 * testing-library, so mocking the module is the only mechanism that can
 * distinguish "hidden because unpermitted" from "hidden because nothing
 * rendered". Both are `not.toContain`, which is why the POSITIVE case is the
 * one that carries the weight here.
 *
 * `react-dom/server` only, and beside `pages/` rather than inside it, as
 * ClientOutstandingPage.render.test.tsx explains.
 *
 * Every figure here is synthetic (FC-06).
 */

let user: User | null = null;

vi.mock('@/features/auth/store', () => ({
    // Matches the call shape the page uses: `useAuthStore((state) => state.user)`.
    useAuthStore: (selector: (state: { user: User | null }) => unknown) => selector({ user }),
}));

vi.mock('@/lib/api', () => ({
    api: { get: vi.fn(async () => ({ data: { data: null } })), post: vi.fn() },
}));

/** The screen the upload exists for: a successful read of a position nobody has filled. */
const emptyPosition: ClientOutstandingReport = {
    as_of: null,
    synced_at: null,
    company: null,
    clients: [],
    totals: {
        clients: 0,
        outstanding_amount: '0',
        overdue_amount: '0',
        pending_order_amount: '0',
        bill_count: 0,
        pending_order_count: 0,
        ageing: { current: '0', d1_30: '0', d31_60: '0', d61_90: '0', d90_plus: '0', no_due_date: '0' },
    },
};

function renderPage(): string {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    // Seeded, not fetched: a server render resolves no promise.
    queryClient.setQueryData(['finance', 'client-outstanding'], emptyPosition);

    return renderToString(
        <QueryClientProvider client={queryClient}>
            <MemoryRouter>
                <ClientOutstandingPage />
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

const login = (permissions: string[]): User => ({
    id: 1,
    name: 'A person',
    email: 'a@example.test',
    is_active: true,
    permissions,
});

beforeEach(() => {
    user = null;
});

describe('the Tally-export upload on an empty position', () => {
    it('draws for a finance.manage login, on the empty page it exists for', () => {
        user = login(['finance.manage']);

        const html = renderPage();

        expect(html).toContain('Import Tally export');
        // Proves the assertion above is not passing on a page that failed to
        // render: the header the control sits in is genuinely on screen, and
        // the early return for a read that never came back did not fire.
        expect(html).toContain('Client outstanding');
    });

    it('is not drawn for a read-only finance login', () => {
        // `module:finance` opens this page, so plenty of people READ the
        // debtor book. Replacing it is a write and is not the same right.
        user = login(['finance.view']);

        const html = renderPage();

        expect(html).not.toContain('Import Tally export');
        expect(html).toContain('Client outstanding');
    });

    it('is not drawn for a login that cannot manage finance, or for none at all', () => {
        // Not tally-sync's permission: whoever runs the outward voucher queue
        // is not thereby allowed to overwrite the inward position.
        user = login(['tally-sync.manage', 'finance.view']);
        expect(renderPage()).not.toContain('Import Tally export');

        user = null;
        expect(renderPage()).not.toContain('Import Tally export');
    });
});
