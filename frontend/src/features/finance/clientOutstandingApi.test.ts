import { describe, expect, it, vi } from 'vitest';

/**
 * THE PAGE ASKS THE URL THE BACKEND ACTUALLY SERVES.
 *
 * WHY THIS EXISTS. When the page moved from CRM to Finance, every other test
 * stayed green while `getClientOutstanding` still called `/crm/client-outstanding`
 * — a route that no longer exists. Nothing caught it: the render test mocks
 * `@/lib/api` wholesale and seeds the query cache, so the real URL is never
 * exercised, and a typecheck cannot read a string literal against a Laravel
 * route file. Live, the screen would have been a 404 behind a spinner.
 *
 * So this asserts the one thing those tests structurally cannot: the exact
 * path sent to the API client. `/api/v1` is the client's own baseURL and is
 * not repeated here.
 */

const get = vi.fn(async () => ({ data: { data: null } }));

vi.mock('@/lib/api', () => ({ api: { get, post: vi.fn(), put: vi.fn() } }));

describe('getClientOutstanding', () => {
    it('requests the finance route, not the retired CRM one', async () => {
        const { getClientOutstanding } = await import('@/features/finance/api');

        await getClientOutstanding();

        expect(get).toHaveBeenCalledWith('/finance/client-outstanding');
    });
});
