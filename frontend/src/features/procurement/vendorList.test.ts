import type { AxiosRequestConfig, AxiosResponse } from 'axios';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { listAllVendors, listVendors } from './api';
import { VENDOR_DEFAULT_SORT, VENDOR_LIST_SPEC, vendorListSort } from './vendorList';
import { api } from '@/lib/api';
import { readListParams } from '@/lib/listParams';

/**
 * THE VENDOR MASTER IS PAGED ON THE SERVER, AND THE SCREEN SAYS SO.
 *
 * The Vendors table drew `listVendors()` — the server's default first page —
 * with `pagination={false}`. Twenty rows appeared, the twenty-first vendor did
 * not, and NOTHING on screen said a row was missing: it read as "that vendor
 * was never created" rather than "this table stops at 20". Same class of
 * defect as the pickers (pickerFullList.test.ts) and as the GRN open-order
 * picker, and the same cure the Customers table already carries — ask the
 * server for the page being shown, and draw the server's own total.
 *
 * Two halves are pinned here. The REQUEST contract is proved by running the
 * real api functions through a stub axios adapter — no DOM, no server, no
 * mock of our own module, just the params axios was actually handed. The
 * SCREEN half is a source lint in the pickerFullList.test.ts idiom, because
 * this project has no DOM test infrastructure and adding one to check a
 * pager would be a bigger change than the fix.
 *
 * Every fixture below is synthetic. A real vendor name, and the Tally ledger
 * it maps to, are Owner/Accounts material (FC-06) and never test data.
 */

const SOURCES = import.meta.glob('./**/*.{ts,tsx}', {
    eager: true,
    query: '?raw',
    import: 'default',
}) as Record<string, string>;

const VENDORS_PAGE = SOURCES['./pages/VendorsPage.tsx'];
const API_MODULE = SOURCES['./api.ts'];

/** Records what axios was asked for, and answers with an empty page. */
function captureRequests(): AxiosRequestConfig[] {
    const seen: AxiosRequestConfig[] = [];
    api.defaults.adapter = async (config) => {
        seen.push(config);
        return {
            data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 } },
            status: 200,
            statusText: 'OK',
            headers: {},
            config,
        } as unknown as AxiosResponse;
    };
    return seen;
}

describe('the vendors table asks the server for one page at a time', () => {
    let requests: AxiosRequestConfig[];
    const originalAdapter = api.defaults.adapter;

    beforeEach(() => {
        requests = captureRequests();
    });

    afterEach(() => {
        api.defaults.adapter = originalAdapter;
    });

    it('sends the page and the page size it was given', async () => {
        await listVendors(3, 100);

        expect(requests).toHaveLength(1);
        expect(requests[0].url).toBe('/procurement/vendors');
        expect(requests[0].params).toEqual({ page: 3, per_page: 100 });
    });

    it('an older caller passing nothing still gets a valid first page, not an unpaged request', async () => {
        // Backwards compatibility is the point: listVendors() took no
        // arguments before this change, so the defaults must be a real page
        // rather than `undefined` reaching the query string.
        await listVendors();

        expect(requests[0].params).toEqual({ page: 1, per_page: 50 });
    });

    it('a default page of 50 is wider than the server default of 20 that truncated the table', async () => {
        await listVendors();

        const { per_page: perPage } = requests[0].params as { per_page: number };
        expect(perPage).toBeGreaterThan(20);
        // Still a PAGE, not a disguised "fetch everything" — the pager is
        // what makes the rest reachable.
        expect(perPage).toBeLessThan(1000);
    });

    it('serializes to the page/per_page query the server reads', async () => {
        await listVendors(2, 20);

        const uri = decodeURIComponent(api.getUri(requests[0]));
        expect(uri).toBe('/api/v1/procurement/vendors?page=2&per_page=20');
    });

    // DEC-20260902-026 (Task 6, over Task 5's contract): classification and
    // unclassified are trailing, optional, and OR together on the server.
    // Pinned as a REQUEST — not just the pure vendorPickerOptions helper —
    // because a key typo here (`classifications` for `classification`, or
    // `unclassified: true` for `1`) would still typecheck and still pass
    // every other test, and only fail against the live backend.
    it('sends classification[] and unclassified=1 the way the server reads them', async () => {
        await listVendors(1, 50, undefined, ['resin', 'packaging'], true);

        expect(requests[0].params).toEqual({
            page: 1,
            per_page: 50,
            classification: ['resin', 'packaging'],
            unclassified: 1,
        });
        // The bracket form is what discriminates a correct serializer from
        // one that merely looks right — a plain `classification=resin,packaging`
        // would pass the params.toEqual above and still not be what the
        // server's `classification[]=` reader expects.
        const uri = decodeURIComponent(api.getUri(requests[0]));
        expect(uri).toBe(
            '/api/v1/procurement/vendors?page=1&per_page=50&classification[]=resin&classification[]=packaging&unclassified=1',
        );
    });

    it('sends neither key for an empty classification list and unclassified=false — the "every vendor" case', async () => {
        await listVendors(1, 50, undefined, [], false);

        expect(requests[0].params).toEqual({ page: 1, per_page: 50 });
        expect((requests[0].params as Record<string, unknown>).classification).toBeUndefined();
        expect((requests[0].params as Record<string, unknown>).unclassified).toBeUndefined();
    });

    it('sends unclassified=1 alone, with no classification key, for the Unclassified-only choice — "unclassified alone = none"', async () => {
        // The exact state a person produces by picking only the
        // "Unclassified" chip in the Vendors tab filter — an empty
        // classification list with the pseudo-option on.
        await listVendors(1, 50, undefined, [], true);

        expect(requests[0].params).toEqual({ page: 1, per_page: 50, unclassified: 1 });
    });
});

/**
 * SORTED BY THE SERVER (ListVendorsRequest::SORTABLE, 03-Sep-2026). The URL
 * carries one `sort` beside the classification filter; an unknown column is
 * dropped on read so it never reaches a 422, and name order — the service's
 * own default — is the bare request.
 */
describe('the vendors table sorts on the server', () => {
    let requests: AxiosRequestConfig[];
    const originalAdapter = api.defaults.adapter;

    beforeEach(() => {
        requests = captureRequests();
    });

    afterEach(() => {
        api.defaults.adapter = originalAdapter;
    });

    it('drops a sort nobody defined rather than sending it to a 422', () => {
        const params = readListParams(new URLSearchParams('sort=email&classification=resin'), VENDOR_LIST_SPEC);

        expect(params.sort).toBeUndefined();
        expect(params.classification).toEqual(['resin']);
        expect(vendorListSort('email')).toBeUndefined();
    });

    it('sends a known column, trailing the filters, in the server\'s spelling', async () => {
        const params = readListParams(new URLSearchParams('sort=-state_code'), VENDOR_LIST_SPEC);

        expect(vendorListSort(params.sort as string)).toBe('-state_code');
        await listVendors(1, 50, undefined, ['resin'], false, vendorListSort(params.sort as string));

        expect(requests[0].params).toEqual({ page: 1, per_page: 50, classification: ['resin'], sort: '-state_code' });
    });

    it('leaves the default order — name — off the request, as the service defaults to it', async () => {
        expect(VENDOR_DEFAULT_SORT).toBe('name');
        expect(vendorListSort('name')).toBeUndefined();
        await listVendors(1, 50, undefined, undefined, false, vendorListSort(undefined));

        expect(requests[0].params).toEqual({ page: 1, per_page: 50 });
    });
});

describe('the picker list is untouched by the table\'s paging', () => {
    let requests: AxiosRequestConfig[];
    const originalAdapter = api.defaults.adapter;

    beforeEach(() => {
        requests = captureRequests();
    });

    afterEach(() => {
        api.defaults.adapter = originalAdapter;
    });

    it('listAllVendors still asks at the ceiling, with no page number', async () => {
        // The PO vendor picker, the PO filter bar and the subcontract-order
        // page all read this. Paging it would re-open the defect the
        // 12-Aug picker sweep closed.
        await listAllVendors();

        expect(requests[0].params).toEqual({ per_page: 1000 });
        expect((requests[0].params as Record<string, unknown>).page).toBeUndefined();
    });

    it('is still exported under the name the picker lint looks for', () => {
        expect(API_MODULE).toContain('export async function listAllVendors(');
    });
});

describe('the vendors screen draws a real pager', () => {
    it('found the sources it is linting', () => {
        // A glob that matched nothing would make every assertion below pass
        // while checking exactly zero files.
        expect(VENDORS_PAGE, './pages/VendorsPage.tsx should have been read by the glob').toBeDefined();
        expect(API_MODULE, './api.ts should have been read by the glob').toBeDefined();
    });

    it('no longer suppresses the table pagination', () => {
        expect(
            VENDORS_PAGE,
            'pagination={false} on a server-paged list shows page one and hides that there is a page two',
        ).not.toContain('pagination={false}');
    });

    it('counts with the server total, not with the length of the page on screen', () => {
        expect(VENDORS_PAGE).toMatch(/total:\s*data\?\.meta\?\.total/);
    });

    it('passes its page state to the query instead of calling the paged function bare', () => {
        // The search term joined page and perPage when the Tally ledger import
        // took this table from four rows to 628; a bare call would be the
        // unpaged read this whole file exists to prevent.
        // DEC-20260902-026 appended two trailing, optional classification
        // arguments after `search` (ruling: existing positional params stay
        // first, in order) — the group below tolerates them without
        // loosening the thing this test actually protects: page, perPage
        // and search stay first, in that order, and never a bare call.
        expect(VENDORS_PAGE).toMatch(/queryFn:\s*\(\)\s*=>\s*listVendors\(page,\s*perPage(,\s*search)?(,[^)]*)?\)/);
        expect(VENDORS_PAGE).not.toMatch(/queryFn:\s*listVendors\b/);
    });

    it('keys the query by page so a page change refetches', () => {
        expect(VENDORS_PAGE).toMatch(/queryKey:\s*\['procurement',\s*'vendors',\s*page,\s*perPage(,\s*search)?(,[^\]]*)?\]/);
    });

    it('searches the SERVER, and keys the query by the term so a search refetches', () => {
        // Filtering the loaded page in the browser would search 50 rows out of
        // 628 and answer "no such vendor" for one that plainly exists — the
        // defect four pickers in this app were fixed for. The term has to reach
        // the query key too, or typing one would show the previous results.
        expect(VENDORS_PAGE).toMatch(/queryKey:\s*\['procurement',\s*'vendors',\s*page,\s*perPage,\s*search(,[^\]]*)?\]/);
        expect(VENDORS_PAGE).toMatch(/listVendors\(page,\s*perPage,\s*search(,[^)]*)?\)/);
        expect(VENDORS_PAGE).toContain('Input.Search');
    });

    it('resets to the first page when a search is submitted', () => {
        // Otherwise the term is applied to whatever page number was showing,
        // and a search from page 7 of the old list looks like no matches.
        expect(VENDORS_PAGE).toMatch(/onSearch=\{\(value\)\s*=>\s*\{[\s\S]{0,120}setPage\(1\)/);
    });

    it('still invalidates on the shared prefix, so the PO pickers refresh with the table', () => {
        // ['procurement','vendors'] is a PREFIX of both this table's key and
        // the pickers' ['procurement','vendors','all']. Narrowing it to the
        // exact page key would leave a new vendor missing from the PO screen
        // until a reload.
        expect(VENDORS_PAGE).toContain("invalidateQueries({ queryKey: ['procurement', 'vendors'] })");
    });
});
