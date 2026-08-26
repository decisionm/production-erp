import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * What the Tally Sync page's calls actually put on the wire — and, the
 * point of this file, what they do NOT.
 *
 * REFRESH MUST NEVER SYNC. Reloading a page is something people do
 * absent-mindedly, several times, while waiting; if a reload could ask the
 * queue to go, a voucher would reach the accountant's live books because
 * someone was impatient with a table. Sync Now is a deliberate, confirmed,
 * Owner/Accounts press (DEC-20260825-002) and it has exactly one caller.
 *
 * The axios instance is mocked, so nothing here touches a network.
 */

const get = vi.fn();
const post = vi.fn();

vi.mock('@/lib/api', () => ({
    api: {
        get: (...args: unknown[]) => get(...args),
        post: (...args: unknown[]) => post(...args),
        delete: vi.fn(),
        put: vi.fn(),
    },
    ensureCsrfCookie: vi.fn(),
}));

const { getTallySyncSummary, listAllTallySyncEntries, listTallySyncEntries, requestTallySyncNow } = await import('./api');

/** One page of entries, as the server shapes it. */
const page = (total: number) => ({
    data: { data: [], meta: { total, last_page: 1, current_page: 1, per_page: 200 } },
});

beforeEach(() => {
    get.mockReset();
    post.mockReset();
    get.mockImplementation((url: string) => {
        if (url === '/tally-sync/summary') return Promise.resolve({ data: { data: { today: { date: '2026-08-25' } } } });

        return Promise.resolve(page(0));
    });
});

describe('the Refresh path', () => {
    it('makes no write of any kind — least of all a sync request', () => {
        // Exactly what the page's Refresh button does: refetch the list and
        // refetch the summary. Nothing else.
        return Promise.all([listAllTallySyncEntries({}), getTallySyncSummary()]).then(() => {
            expect(post).not.toHaveBeenCalled();

            const urls = get.mock.calls.map((call) => call[0]);
            expect(urls).toEqual(['/tally-sync/entries', '/tally-sync/summary']);
            expect(urls).not.toContain('/tally-sync/sync-now');
        });
    });

    it('does not sync when the list is fetched with filters either', async () => {
        await listTallySyncEntries({ status: ['failed'], voucher_type: ['Sales'] });

        expect(post).not.toHaveBeenCalled();
        expect(get).toHaveBeenCalledWith('/tally-sync/entries', {
            params: { status: ['failed'], voucher_type: ['Sales'] },
        });
    });
});

describe('requestTallySyncNow', () => {
    it('is the only thing that posts to the sync-now endpoint, and posts no body', async () => {
        post.mockResolvedValue({ data: { data: { outcome: 'released', released: 1 } } });

        const result = await requestTallySyncNow();

        expect(post).toHaveBeenCalledTimes(1);
        // No body: the request carries no choice about WHICH vouchers go —
        // the server's release gate decides that, and a client-supplied
        // list would be a second opinion about what is eligible.
        expect(post).toHaveBeenCalledWith('/tally-sync/sync-now');
        expect(result).toEqual({ outcome: 'released', released: 1 });
    });

    it('unwraps the server envelope rather than handing back the raw response', async () => {
        post.mockResolvedValue({ data: { data: { outcome: 'nothing_queued', queued_total: 0 } } });

        expect(await requestTallySyncNow()).toEqual({ outcome: 'nothing_queued', queued_total: 0 });
    });

    it('lets a refusal through to the caller instead of swallowing it', async () => {
        // The 403 for a login that may not press it has to reach the page,
        // which shows the server's own words.
        post.mockRejectedValue({ response: { status: 403, data: { message: 'Sync Now is limited to Owner/Accounts logins.' } } });

        await expect(requestTallySyncNow()).rejects.toMatchObject({ response: { status: 403 } });
    });
});
