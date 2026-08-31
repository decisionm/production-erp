import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { renderToString } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';

/**
 * DOES THE SCREEN ACTUALLY RENDER — the question every other test in this
 * suite leaves open.
 *
 * There is no jsdom and no testing-library here, and this test does not add
 * either: `react-dom/server` ships with React and needs no DOM. That is
 * enough to answer the failures a typecheck cannot see. A component that
 * throws on its first render, an antd import that resolves to undefined, a
 * `.map` over a field the API does not send — each one typechecks cleanly,
 * builds cleanly, and is a blank screen for the storekeeper.
 *
 * It is a smoke test and is deliberately shallow: no clicks, no state, no
 * network. The API layer is stubbed to an empty page precisely so the EMPTY
 * case is what renders, because that is the state the screen is in on the
 * first morning and the one nobody looks at twice.
 *
 * IT LIVES BESIDE THE `pages` DIRECTORY, NOT INSIDE IT. App.routes.test.tsx
 * globs every .tsx under a feature's `pages` directory and asserts each one
 * default-exports a component; a test file there has no default export and
 * fails that check, which is the glob doing its job rather than a rule to
 * work around.
 *
 * WHAT IT PINS ABOUT THE MERGE, and why each assertion exists rather than
 * just "it did not throw":
 *
 *  · rc-tabs renders the ACTIVE pane, so asserting a sentence that belongs to
 *    the embedded Store Issue Queue proves the tab really mounted its page
 *    and not an empty shell.
 *  · The banner must appear EXACTLY ONCE. The shell owns it now; the embedded
 *    page suppresses its own. Drop the `embedded` guard and a storekeeper
 *    reads the same paragraph twice — a mutation that leaves every other test
 *    in this repository green.
 *  · The old "Store Issue Queue" heading must be GONE from inside the tab,
 *    for the same reason: the shell's title is the screen's identity now.
 */
vi.mock('@/lib/api', () => ({
    api: {
        get: vi.fn(async () => ({ data: { data: [], meta: {} } })),
        post: vi.fn(),
    },
}));

import StoreProductionHistoryTab from './pages/StoreProductionHistoryTab';
import StoreProductionPage from './pages/StoreProductionPage';

function render(node: ReactNode): string {
    // retry off: a smoke test must fail on the render, never time out on a
    // stubbed query deciding to try again.
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return renderToString(
        <MemoryRouter>
            <QueryClientProvider client={client}>{node}</QueryClientProvider>
        </MemoryRouter>,
    );
}

describe('the Store <-> Production screen', () => {
    it('renders the shell, all three tabs, and the embedded queue inside the active one', () => {
        const html = render(<StoreProductionPage />);

        expect(html).toContain('Store ↔ Production');
        expect(html).toContain('Issue to production');
        expect(html).toContain('Returns from production');
        expect(html).toContain('Movement history');

        // The active pane really mounted the page it embeds.
        expect(html).toContain('Every filter is applied by the server');
    });

    it('shows the issue-is-not-consumption banner once, from the shell', () => {
        const html = render(<StoreProductionPage />);

        expect(html.split('A store issue is not a consumption').length - 1).toBe(1);
        expect(html).not.toContain('Store Issue Queue');
    });

    it('renders the history tab, asking for a material before it offers a figure', () => {
        const html = render(<StoreProductionHistoryTab />);

        // With no material chosen there is no single standing quantity to
        // print, and inventing one is the failure this screen is built to
        // avoid — so it asks instead.
        expect(html).toContain('Choose a material');
        expect(html).toContain('does not list what production consumed');
    });
});
