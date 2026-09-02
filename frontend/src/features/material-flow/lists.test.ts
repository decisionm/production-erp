import { describe, expect, it } from 'vitest';
import { readListParams, writeListParams } from '@/lib/listParams';
import {
    QUEUE_LIST_SPEC,
    REQUESTS_LIST_SPEC,
    noMatchLine,
    pageRangeLine,
    queueServerFilters,
    queueStatusChoice,
    requestsServerFilters,
} from './lists';

/**
 * HOW THE TWO MATERIAL-REQUEST LISTS' URLS BECOME THE SERVER'S FILTERS.
 *
 * The queue's default view is a status LIST on the wire and an ABSENT key
 * on the URL — get that mapping wrong and either the bare path shows every
 * finished request or `status=all` is unreachable. And the queue lives in
 * a tab: a page turn that dropped `?tab=issues` would close the tab it was
 * turned on.
 */
const read = (query: string, spec: typeof QUEUE_LIST_SPEC) => readListParams(new URLSearchParams(query), spec);

describe('the store queue’s URL', () => {
    it('reads the bare path as the default "still to issue" view', () => {
        const params = read('', QUEUE_LIST_SPEC);

        expect(queueStatusChoice(params)).toBe('open');
        expect(queueServerFilters(params)).toEqual({ status: ['submitted', 'partially_issued'] });
    });

    it('reads status=all as no status filter, and a single status as itself', () => {
        expect(queueServerFilters(read('status=all', QUEUE_LIST_SPEC))).toEqual({});
        expect(queueServerFilters(read('status=issued&q=mr%2012', QUEUE_LIST_SPEC))).toEqual({ status: 'issued', q: 'mr 12' });
    });

    it('carries the page’s own filters through, typed', () => {
        expect(queueServerFilters(read('shift_id=3&item_id=7&from=2026-08-01&to=2026-08-31&page=2&per_page=50', QUEUE_LIST_SPEC))).toEqual({
            status: ['submitted', 'partially_issued'],
            shift_id: 3,
            item_id: 7,
            from: '2026-08-01',
            to: '2026-08-31',
            page: 2,
            per_page: 50,
        });
    });

    it('drops a status the dropdown does not offer rather than sending it', () => {
        expect(queueServerFilters(read('status=approved', QUEUE_LIST_SPEC))).toEqual({ status: ['submitted', 'partially_issued'] });
    });

    it('keeps the workspace tab through a search and a page turn', () => {
        const out = writeListParams({ q: 'mr 12', status: 'all', page: 2 }, QUEUE_LIST_SPEC, new URLSearchParams('tab=issues'));

        expect(out.toString()).toBe('tab=issues&q=mr+12&status=all&page=2');
        expect(out.get('tab')).toBe('issues');
    });
});

describe('the floor’s own URL', () => {
    it('always asks for its drafts, and reads the bare path as every status', () => {
        expect(requestsServerFilters(read('', REQUESTS_LIST_SPEC))).toEqual({ include_unsubmitted: 1 });
        expect(requestsServerFilters(read('status=all', REQUESTS_LIST_SPEC))).toEqual({ include_unsubmitted: 1 });
    });

    it('sends one status, the search and the page', () => {
        expect(requestsServerFilters(read('status=draft&q=12&page=3', REQUESTS_LIST_SPEC))).toEqual({
            status: 'draft',
            q: '12',
            page: 3,
            include_unsubmitted: 1,
        });
    });
});

describe('pageRangeLine', () => {
    it('is nothing until the server has answered', () => {
        expect(pageRangeLine(undefined, 'requests')).toBeNull();
    });

    it('states the range within the total, from the server’s meta', () => {
        expect(pageRangeLine({ current_page: 1, last_page: 3, per_page: 20, total: 43 }, 'requests')).toBe('1–20 of 43 requests');
        expect(pageRangeLine({ current_page: 3, last_page: 3, per_page: 20, total: 43 }, 'requests')).toBe('41–43 of 43 requests');
    });

    it('says 0 plainly, which the pager would not say at all', () => {
        expect(pageRangeLine({ current_page: 1, last_page: 1, per_page: 20, total: 0 }, 'requests')).toBe('0 requests');
    });
});

describe('noMatchLine', () => {
    it('repeats the term so a typo is visible where it was made', () => {
        expect(noMatchLine('requests', 'mr 99')).toBe('No requests match “mr 99”.');
    });
});
