import { describe, expect, it } from 'vitest';

/**
 * EVERY PICKER READS THE FULL LIST, NEVER THE DEFAULT FIRST PAGE.
 *
 * Found in front of the owner during a live demo on 12-Aug-2026: the Purchase
 * Order item picker offered only products and no raw materials, so a resin PO
 * could not be raised at all. The cause was not a filter — the picker was fed
 * from `listItems()`, which fetches the default first page of 20 out of 642
 * items. The raw materials were simply not in the first 20, and nothing on
 * screen said a row was missing.
 *
 * A picker that silently omits rows is invisible until somebody cannot find
 * the thing they need, and then it looks like the data is wrong rather than
 * the query. The same defect had already been found and fixed once that
 * morning on the customer list, and a sweep found it in EVERY item picker in
 * the app plus the warehouse, vendor, employee and scrap-reason pickers.
 *
 * So this is a lint, not a unit test: it reads the source and fails if any
 * page feeds a `useQuery` from a known paged list function. The pages
 * exempted below render that list as their OWN paginated table, which is what
 * the paged function is for.
 *
 * Sources are read through Vite's `import.meta.glob` rather than node:fs on
 * purpose — this file is type-checked by the app's tsconfig, which has no
 * Node types, and a lint that breaks the build it is meant to protect is
 * worse than no lint.
 */

const SOURCES = import.meta.glob('../features/**/*.{ts,tsx}', {
    eager: true,
    query: '?raw',
    import: 'default',
}) as Record<string, string>;

/** Paged list functions that must never feed a picker. */
const PAGED_LIST_FUNCTIONS = [
    'listItems',
    'listWarehouses',
    'listVendors',
    'listEmployees',
    'listScrapReasons',
    // Added 25-Aug-2026. The Stock page's Batch and Serial Number pickers were
    // the same defect one layer down: both were fed from the default first
    // page and then FILTERED CLIENT-SIDE by the chosen item, so a batch that
    // was not in the newest twenty rows could not be selected at all — and the
    // barcode scanner, matching against the same twenty, answered "no batch
    // matches" for a batch that plainly exists.
    'listBatches',
    'listSerialNumbers',
];

/**
 * The pages that legitimately call a paged function: each renders that list as
 * its own table with its own pagination. Anything NOT here calling one of the
 * functions above is a picker reading 20 rows of a much longer list.
 */
const TABLE_PAGES = [
    'inventory/pages/WarehousesPage.tsx',
    'inventory/pages/ItemsPage.tsx',
    'inventory/pages/BatchesPage.tsx',
    'inventory/pages/SerialNumbersPage.tsx',
    'procurement/pages/VendorsPage.tsx',
    'hrms/pages/EmployeesPage.tsx',
    'production/pages/ScrapReasonsPage.tsx',
];

/** The api modules DEFINE these functions; they do not consume them. */
const isConsumer = (path: string) => !path.endsWith('/api.ts');

describe('pickers read the full list', () => {
    it('finds the source files it is meant to be linting', () => {
        // Guards the lint itself: a glob that silently matches nothing would
        // make every assertion below pass while checking exactly zero files.
        expect(Object.keys(SOURCES).length).toBeGreaterThan(50);
    });

    it.each(PAGED_LIST_FUNCTIONS)('no page feeds a useQuery from %s', (fn) => {
        // `queryFn: listItems` — the exact shape that caused the bug — AND
        // `queryFn: () => listItems()`, the argument-less thunk, which is the
        // same read wearing an arrow function and is how the Stock page's
        // batch and serial pickers hid. A thunk that PASSES something
        // (`() => listBatches(itemId)`) is a deliberate act and not guarded.
        const pattern = new RegExp(`queryFn:\\s*(${fn}\\b|\\(\\)\\s*=>\\s*${fn}\\(\\s*\\))`);

        const offenders = Object.entries(SOURCES)
            .filter(([path]) => isConsumer(path))
            .filter(([path]) => !TABLE_PAGES.some((allowed) => path.endsWith(allowed)))
            .filter(([, source]) => pattern.test(source))
            .map(([path]) => path.replace('../', 'src/'));

        expect(
            offenders,
            `${fn}() returns the default first page. A picker fed from it silently omits rows — `
                + `use the listAll… variant with its own '…all' query key.`,
        ).toEqual([]);
    });

    it('a full-list variant exists for every paged function a picker might reach for', () => {
        const modules: Record<string, string> = {
            listItems: '../features/inventory/api.ts',
            listWarehouses: '../features/inventory/api.ts',
            listVendors: '../features/procurement/api.ts',
            listEmployees: '../features/hrms/api.ts',
            listScrapReasons: '../features/production/api.ts',
            listBatches: '../features/inventory/api.ts',
            listSerialNumbers: '../features/inventory/api.ts',
        };

        for (const [fn, module] of Object.entries(modules)) {
            const full = fn.replace('list', 'listAll');
            expect(
                SOURCES[module],
                `${module} should have been read by the glob`,
            ).toBeDefined();
            expect(
                SOURCES[module],
                `${full} must exist so a picker has something correct to call`,
            ).toContain(`export async function ${full}(`);
        }
    });
});
