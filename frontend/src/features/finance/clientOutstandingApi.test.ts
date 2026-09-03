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
const post = vi.fn(async () => ({
    data: { data: { bills: 0, orders: 0, parties: 0, as_of: '2026-09-30', skipped_empty: false } },
}));

vi.mock('@/lib/api', () => ({ api: { get, post, put: vi.fn() } }));

describe('getClientOutstanding', () => {
    it('requests the finance route, not the retired CRM one', async () => {
        const { getClientOutstanding } = await import('@/features/finance/api');

        await getClientOutstanding();

        expect(get).toHaveBeenCalledWith('/finance/client-outstanding');
    });
});

/**
 * THE UPLOAD SENDS THE FIELD THE BACKEND VALIDATES.
 *
 * Same class of bug as the CRM path above, one layer deeper. The contract is
 * `multipart/form-data` with a SINGLE field named `file`; name it anything
 * else and Laravel answers 422 "The file field is required." on a request that
 * plainly carried a file. A typecheck cannot see a string key inside a
 * FormData, and the render test never runs this function — so the field name
 * is asserted here, by reading it back off the body that was actually sent.
 * `expect.any(FormData)` would pass with the field called `xml`.
 */
describe('importClientOutstanding', () => {
    it('posts the file to the import route as multipart, under the key `file`', async () => {
        const { importClientOutstanding } = await import('@/features/finance/api');

        const file = new File(['<ENVELOPE/>'], 'outstandings.xml', { type: 'text/xml' });

        await importClientOutstanding(file);

        expect(post).toHaveBeenCalledTimes(1);
        const [url, body, config] = post.mock.calls[0] as unknown as [string, FormData, { headers: Record<string, string> }];

        expect(url).toBe('/finance/client-outstanding/import');
        expect(body).toBeInstanceOf(FormData);
        // The key itself, read back off the body — not merely "a FormData".
        expect(body.get('file')).toBe(file);
        expect(config.headers['Content-Type']).toBe('multipart/form-data');
    });

    /**
     * THE BUG THIS PINS. The body carried only `file` while the server required
     * `as_of` as well, so every press of Import returned 422 before it ever
     * reached the parser. The two halves were written against contracts that
     * had drifted apart by exactly one field, and nothing caught it because the
     * test above asserted the body's keys were EXACTLY ['file'].
     */
    it('sends as_of as a plain ISO date, built from the local calendar', async () => {
        const { importClientOutstanding } = await import('@/features/finance/api');

        await importClientOutstanding(new File(['<ENVELOPE/>'], 'o.xml', { type: 'text/xml' }));

        const [, body] = post.mock.calls[0] as unknown as [string, FormData];

        expect([...body.keys()]).toEqual(['file', 'as_of']);

        const asOf = String(body.get('as_of'));

        // date_format:Y-m-d server-side — anything else is a 422.
        expect(asOf).toMatch(/^\d{4}-\d{2}-\d{2}$/);

        // Built from LOCAL calendar parts, never toISOString(): that converts
        // to UTC first, so any evening in IST would file the position under
        // yesterday's date.
        const now = new Date();
        const pad = (n: number) => String(n).padStart(2, '0');
        expect(asOf).toBe(`${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`);
    });

    it('returns the counts the server sent, unwrapped from the Resource envelope', async () => {
        const { importClientOutstanding } = await import('@/features/finance/api');

        const result = await importClientOutstanding(new File(['<ENVELOPE/>'], 'outstandings.xml'));

        expect(result).toEqual({ bills: 0, orders: 0, parties: 0, as_of: '2026-09-30', skipped_empty: false });
    });
});
