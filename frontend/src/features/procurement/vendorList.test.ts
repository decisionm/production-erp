import type { AxiosRequestConfig, AxiosResponse } from 'axios';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { listAllVendors, listVendors } from './api';
import { api } from '@/lib/api';

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
        expect(VENDORS_PAGE).toMatch(/queryFn:\s*\(\)\s*=>\s*listVendors\(page,\s*perPage(,\s*search)?\)/);
        expect(VENDORS_PAGE).not.toMatch(/queryFn:\s*listVendors\b/);
    });

    it('keys the query by page so a page change refetches', () => {
        expect(VENDORS_PAGE).toMatch(/queryKey:\s*\['procurement',\s*'vendors',\s*page,\s*perPage(,\s*search)?\]/);
    });

    it('searches the SERVER, and keys the query by the term so a search refetches', () => {
        // Filtering the loaded page in the browser would search 50 rows out of
        // 628 and answer "no such vendor" for one that plainly exists — the
        // defect four pickers in this app were fixed for. The term has to reach
        // the query key too, or typing one would show the previous results.
        expect(VENDORS_PAGE).toMatch(/queryKey:\s*\['procurement',\s*'vendors',\s*page,\s*perPage,\s*search\]/);
        expect(VENDORS_PAGE).toMatch(/listVendors\(page,\s*perPage,\s*search\)/);
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
