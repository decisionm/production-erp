import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
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
 * page feeds a `useQuery` from a known paged list function. The four pages
 * exempted below render that list as their OWN paginated table, which is what
 * the paged function is for.
 */

/** Paged list functions that must never feed a picker. */
const PAGED_LIST_FUNCTIONS = [
    'listItems',
    'listWarehouses',
    'listVendors',
    'listEmployees',
    'listScrapReasons',
];

/**
 * The pages that legitimately call a paged function: each renders that list as
 * its own table with its own pagination. Anything NOT on this list calling one
 * of the functions above is a picker reading 20 rows of a much longer list.
 */
const TABLE_PAGES = [
    'features/inventory/pages/WarehousesPage.tsx',
    'features/inventory/pages/ItemsPage.tsx',
    'features/procurement/pages/VendorsPage.tsx',
    'features/hrms/pages/EmployeesPage.tsx',
    'features/production/pages/ScrapReasonsPage.tsx',
];

function sourceFiles(dir: string): string[] {
    return readdirSync(dir).flatMap((entry) => {
        const full = join(dir, entry);
        if (statSync(full).isDirectory()) return sourceFiles(full);
        return full.endsWith('.tsx') || full.endsWith('.ts') ? [full] : [];
    });
}

describe('pickers read the full list', () => {
    const files = sourceFiles(join(__dirname, '..')).filter(
        // The api modules DEFINE these functions; they do not consume them.
        (f) => !f.endsWith('/api.ts') && !f.endsWith('.test.ts'),
    );

    it.each(PAGED_LIST_FUNCTIONS)('no page feeds a useQuery from %s', (fn) => {
        const offenders = files.filter((file) => {
            if (TABLE_PAGES.some((allowed) => file.replace(/\\/g, '/').endsWith(allowed))) {
                return false;
            }

            // `queryFn: listItems` — the exact shape that caused the bug. A
            // thunk passing explicit paging (`() => listItems(2)`) is a
            // deliberate act and not what this guards.
            return new RegExp(`queryFn:\\s*${fn}\\b`).test(readFileSync(file, 'utf8'));
        });

        expect(
            offenders.map((f) => f.replace(/\\/g, '/').replace(/.*\/src\//, 'src/')),
            `${fn}() returns the default first page. A picker fed from it silently omits rows — `
                + `use the listAll… variant with its own '…all' query key.`,
        ).toEqual([]);
    });

    it('the full-list variants exist for every paged function a picker might reach for', () => {
        const api = {
            listItems: 'features/inventory/api.ts',
            listWarehouses: 'features/inventory/api.ts',
            listVendors: 'features/procurement/api.ts',
            listEmployees: 'features/hrms/api.ts',
            listScrapReasons: 'features/production/api.ts',
        };

        for (const [fn, module] of Object.entries(api)) {
            const source = readFileSync(join(__dirname, '..', module), 'utf8');
            const full = fn.replace('list', 'listAll');
            expect(source, `${full} must exist so a picker has something correct to call`).toContain(
                `export async function ${full}(`,
            );
        }
    });
});
