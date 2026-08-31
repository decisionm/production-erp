import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { renderToString } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';

/**
 * The lookup page comes up, and says what it is for before anyone types.
 *
 * Same shape and same reasoning as the Store ↔ Production render test: no
 * jsdom and no testing-library in this repo, and `react-dom/server` answers
 * the one question a typecheck cannot — does the screen render at all. A
 * component that throws on first render, or an antd import that resolves to
 * undefined, typechecks and builds cleanly and is a blank page on the floor.
 *
 * WHAT IT PINS BEYOND "did not throw": the empty state has to EXPLAIN the
 * two different behaviours before a storekeeper meets them, because the
 * difference is not guessable. A barcode jumps; a batch number lists. Told
 * neither, the first time a scan navigates away on its own it reads as the
 * page losing their input.
 */
vi.mock('@/lib/api', () => ({
    api: { get: vi.fn(async () => ({ data: { data: { term: '', resolved: null, matches: [], omitted: [] } } })) },
}));

import FactoryLookupPage from './pages/FactoryLookupPage';

function render(node: ReactNode): string {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return renderToString(
        <MemoryRouter>
            <QueryClientProvider client={client}>{node}</QueryClientProvider>
        </MemoryRouter>,
    );
}

describe('the factory lookup page', () => {
    const html = render(<FactoryLookupPage />);

    it('renders with a box that names every identifier space it covers', () => {
        expect(html).toContain('Find anything by its number');

        // The placeholder is the only place a person learns the box takes
        // more than a barcode.
        for (const kind of ['barcode', 'SKU', 'lot', 'batch', 'serial', 'store issue']) {
            expect(html).toContain(kind);
        }
    });

    it('explains that some numbers jump and others list, before anyone types', () => {
        expect(html).toContain('goes straight to its record');
        expect(html).toContain('more than one material');
    });
});
