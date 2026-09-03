import { describe, expect, it } from 'vitest';
import type { User } from '@/features/auth/types';
import { ADOPTED_MODULES } from '@/lib/adoptedModules';
import { allNavItems, buildNavItems, navTrailForPath } from './AppLayout';

/**
 * THE SIDEBAR ORDER IS A CONTRACT (21-Aug-2026 owner request).
 *
 * The opening run was asked for by name, module by module, and it is the one
 * thing on this screen every user of the factory sees on every route. Nothing
 * in the type system notices a group sliding one place up: the menu still
 * renders, every link still works, and the only symptom is a supervisor
 * hunting for a screen that used to be where their thumb expects it.
 *
 * TWO ORDERS, PINNED SEPARATELY, because they can disagree:
 *
 *  - the CONFIGURED order (`allNavItems`) — the full table, hidden modules
 *    included. Its prefix through Payroll, and Tally Sync last of the
 *    modules, are what the owner specified; the rest is prior order kept as
 *    it was. A module that is hidden today because the factory has not
 *    adopted it must still be sitting in its right place for the day it is
 *    adopted, and only this pin sees that.
 *  - the RENDERED order (`buildNavItems`) — what a login with every
 *    permission actually gets after ADOPTED_MODULES and the permission
 *    filter. Pinning only this one would let a hidden module be reordered or
 *    dropped entirely with no test going red.
 */
const CONFIGURED_ORDER = [
    'Dashboard',
    // No module of its own: a person's own attendance is theirs whether or
    // not they may open HRMS.
    'My Attendance',
    'Ask ERP',
    'Procurement',
    'Inventory',
    'Production',
    'Sales',
    'Quality',
    'Compliance',
    'HRMS',
    'Payroll',
    // Everything above was specified module by module; Tally Sync last was
    // too. These three fill the unspecified "etc." between them and hold the
    // relative order they had before that request, which is why they are
    // CRM, Finance, Maintenance and not something an agent chose.
    'CRM',
    'Finance',
    'Maintenance',
    // TALLY SYNC STAYS LAST OF THE MODULES. The 26-Aug Phase 3 build spec
    // asked for it directly after Payroll; the position it would leave is
    // the 21-Aug owner request this file exists to pin, so that ask is a
    // REVERSAL of an owner pin and a build spec is not owner authority
    // (AGENTS.md). It is parked in docs/factory/PENDING-OWNER-QUESTIONS.md
    // as "Where should Tally Sync sit in the sidebar?" — named, not
    // numbered, because that file re-mints question numbers at merge — and
    // NOT applied. If the owner confirms the new position, move this line to
    // sit directly after 'Payroll' and move the entry in AppLayout.tsx with
    // it — do not resequence either one without that answer.
    'Tally Sync',
    // One utility entry, below the divider AppLayout inserts before it:
    // Downloads, Help, Users and Roles are cards on the Settings page now
    // (owner, 03-Sep-2026), and their routes are unchanged.
    'Settings',
];

/**
 * A login holding everything — the Administrator the owner actually uses.
 * Permissions cannot hide a module from this user (that is the whole reason
 * ADOPTED_MODULES exists), so what this user sees is exactly the adoption
 * list, in configured order.
 */
function administrator(): User {
    const modules = [
        ...allNavItems.flatMap((item) => [
            ...(item.module ? [item.module] : []),
            ...(item.children ?? []).flatMap((child) => (child.module ? [child.module] : [])),
        ]),
    ];

    return {
        id: 1,
        name: 'Administrator',
        email: 'admin@example.test',
        is_active: true,
        permissions: modules.flatMap((module) => [`${module}.view`, `${module}.manage`]),
    };
}

describe('the sidebar', () => {
    it('is configured in exactly the pinned order, hidden modules included', () => {
        expect(allNavItems.map((item) => item.label)).toEqual(CONFIGURED_ORDER);
    });

    it('renders that same order, minus what the factory has not adopted', () => {
        const rendered = buildNavItems(administrator()).map((item) => item.label);
        const expected = CONFIGURED_ORDER.filter((label) => {
            const item = allNavItems.find((nav) => nav.label === label);
            return !item?.module || ADOPTED_MODULES.has(item.module);
        });

        expect(rendered).toEqual(expected);
    });

    it('shows nothing at all to a login with no permissions', () => {
        // Dashboard, My Attendance and Settings carry no `module`, so they survive —
        // that is deliberate (see the comments on those entries) and is
        // pinned here so a stray gate on one of them is a red test.
        expect(buildNavItems(null).map((item) => item.label)).toEqual(['Dashboard', 'My Attendance', 'Settings']);
    });
});

describe('the current-page trail', () => {
    it('names a top-level page without inventing a parent', () => {
        expect(navTrailForPath(allNavItems, '/')).toEqual(['Dashboard']);
    });

    it('names both the module and page for a nested route', () => {
        expect(navTrailForPath(allNavItems, '/finance/client-outstanding')).toEqual([
            'Finance',
            'Client Outstanding',
        ]);
        expect(navTrailForPath(allNavItems, '/inventory/store-production')).toEqual([
            'Inventory',
            'Store ↔ Production',
        ]);
    });

    it('leaves unknown direct-only routes unlabeled', () => {
        expect(navTrailForPath(allNavItems, '/not-a-configured-menu-route')).toEqual([]);
    });
});

describe('the retired CRM menu', () => {
    /**
     * CRM IS RETIRED (owner instruction, 31-Aug-2026) — hidden, not deleted.
     *
     * Both halves are asserted, because only the pair says what "retired"
     * means here. The group must still be CONFIGURED, in its pinned place, so
     * the day the factory records a real enquiry one line in adoptedModules
     * brings it back; and it must NOT RENDER, or it has not been retired at
     * all. Asserting either alone passes for a deletion, or for a no-op.
     */
    it('is still configured, so one line can bring it back', () => {
        expect(allNavItems.find((item) => item.key === 'crm')).toBeDefined();
        expect(CONFIGURED_ORDER).toContain('CRM');
    });

    it('renders for nobody, not even a login holding every permission', () => {
        expect(buildNavItems(administrator()).map((item) => item?.label)).not.toContain('CRM');
    });

    it('is off the adoption list', () => {
        expect(ADOPTED_MODULES.has('crm')).toBe(false);
    });
});

describe('the Finance menu', () => {
    /**
     * THE REGRESSION THIS PINS, which cost a round trip to spot.
     *
     * Client Outstanding was moved out of CRM and into Finance so it would sit
     * behind `module:finance` with the rest of the debtor book. Every suite
     * stayed green and the entry vanished from the sidebar: `crm` is on the
     * adoption list and `finance` was not, and buildNavItems drops a whole
     * group whose module is unadopted. The page was reachable only by typing
     * its URL.
     *
     * Configuration alone therefore proves nothing here — what matters is what
     * a real login RENDERS, so that is what is asserted.
     */
    it('shows Client Outstanding to a login that holds finance', () => {
        const finance = buildNavItems(administrator()).find((item) => item?.label === 'Finance');

        expect(finance).toBeDefined();
        expect(
            (finance as { children?: { label?: string }[] } | undefined)?.children?.map((c) => c.label),
        ).toContain('Client Outstanding');
    });

    it('is adopted, or the group it lives in cannot render at all', () => {
        expect(ADOPTED_MODULES.has('finance')).toBe(true);
    });
});

describe('the Inventory menu', () => {
    const inventory = allNavItems.find((item) => item.key === 'inventory');

    /**
     * Batches and Serial Numbers left this menu on 26-Aug-2026 and must not
     * drift back: they are per-item identity registers opened from a stock
     * line, and the Stock page's toolbar is what links to them now. Pinned the
     * way the Production group pins its own removals — a test that goes red
     * when the arrangement changes is the point.
     */
    it.each([
        ['Batches', '/inventory/batches'],
        ['Serial Numbers', '/inventory/serial-numbers'],
    ])('does not list %s as a child', (label, key) => {
        expect(inventory?.children?.map((child) => child.label)).not.toContain(label);
        expect(inventory?.children?.map((child) => child.key)).not.toContain(key);
    });

    it('still lists the Stock page those links live on', () => {
        expect(inventory?.children?.map((child) => child.key)).toContain('/inventory/stock');
    });

    /**
     * THE LEDGER IS A PAGE NOW, and this assertion is the inverse of the one
     * it replaces.
     *
     * The old line pinned the opposite — "lists no entry for a Stock Movements
     * page that does not exist" — and its reasoning was correct while it
     * stood: /inventory/stock-movements was an API path with nothing mounted
     * on it, and a menu line pointing at a route App.tsx does not mount is a
     * dead link that renders fine and 404s on click. A page exists as of
     * 27-Aug-2026, so the premise is gone; the line is inverted rather than
     * deleted, the way Shifts was in the Production group below, so the
     * arrangement stays pinned in whichever direction it currently holds.
     *
     * What has NOT changed is the rule underneath: every child of this group
     * must point at a route App.tsx actually mounts. App.routes.test.tsx pins
     * the other half of that pair.
     */
    it('lists the Stock Movements ledger the app now mounts', () => {
        expect(inventory?.children?.map((child) => child.key)).toContain('/inventory/stock-movements');
    });

    /**
     * TWO LABEL REGISTERS, ONE ENTRY — and the register did NOT lose its way
     * in. The earlier version of this test pinned them as two sidebar entries,
     * and the failure it guarded against was "the receipts register loses its
     * only link while its page stays mounted". That guard still matters and
     * still holds: the register is the second TAB of Barcode & Labels, and
     * /inventory/material-lots stays mounted (App.routes.test.tsx pins the
     * route table), so bookmarks and existing links open it directly.
     *
     * What changed is deliberate: the store asked for the Inventory group to
     * be the six locations it actually works in, and nine entries put the
     * least-used register beside the most-used bench.
     */
    it('carries one label entry, with the receipts register no longer a sidebar row', () => {
        const byLabel = new Map(inventory?.children?.map((child) => [child.label, child.key]));

        expect(byLabel.get('Barcode & Labels')).toBe('/inventory/barcode-labels');
        expect(byLabel.has('Material Receipts & Bag Labels')).toBe(false);
    });

    /**
     * THE GROUP, in the order a storekeeper works: what a product IS, what is
     * on hand, the label bench, the material moving between the store and the
     * floor, where the stock lives, the ledger behind it all, and the two
     * customer-fulfilment screens the store owns.
     *
     * NINE since 31-Aug-2026, when Find was added at the head of the group:
     * the entry point for a person holding a number who does not know which
     * of the eight screens below owns it.
     *
     * EIGHT before that. It had been eight since the two label registers became tabs of
     * one entry, and it is eight again after 31-Aug-2026, when "Store Issue
     * Queue" and "Returns" became the two directions of one Store ↔
     * Production screen. (The count in this docblock read "eight, down from
     * nine" while the array below listed nine — the comment was written for
     * the label-register merge and never updated when Returns was added on
     * 30-Aug. Stated properly now: the ARRAY is the pin, this sentence is
     * only its summary, and a ninth entry appearing here is the drift.)
     */
    it('is the inventory destinations, in working order', () => {
        expect(inventory?.children?.map((child) => child.key)).toEqual([
            '/inventory/find',
            '/inventory/items',
            '/inventory/stock',
            '/inventory/barcode-labels',
            '/inventory/store-production',
            '/inventory/stock-movements',
            '/inventory/fulfilment',
            '/inventory/planning',
            // WAREHOUSES LAST — it is setup, and the group's own header states
            // the rule ("daily-use first, masters after"). It used to sit
            // between Store ↔ Production and Stock Movements, splitting the
            // storekeeper's daily run in half.
            '/inventory/warehouses',
        ]);
    });

    /**
     * THE FULFILMENT SCREENS STAY IN THIS GROUP, and the reason is the
     * permission model rather than taste. `buildNavItems` gates a whole group
     * on its parent's `module`, so under Sales a login holding inventory
     * permissions alone would lose both entries while the routes still mount
     * and their API still gates on `module:inventory` — permitted, existing,
     * and unreachable from the menu. They were moved to Sales on 27-Aug for a
     * six-entry group and moved back when review found that; this pins the
     * result so the tidier arrangement cannot quietly return.
     */
    it('keeps the fulfilment screens under the module that permits them', () => {
        const inventoryKeys = inventory?.children?.map((child) => child.key) ?? [];
        expect(inventoryKeys).toContain('/inventory/fulfilment');
        expect(inventoryKeys).toContain('/inventory/planning');
        expect(inventory?.module).toBe('inventory');

        const sales = allNavItems.find((item) => item.key === 'sales');
        const salesKeys = sales?.children?.map((child) => child.key) ?? [];
        expect(salesKeys).not.toContain('/inventory/fulfilment');
        expect(salesKeys).not.toContain('/inventory/planning');
    });

    /**
     * The screen is called PRODUCTION Planning, and this is where that is
     * pinned. It used to read "Fulfilment Planning", which named the wrong
     * half of what it does: the service behind it walks production requests
     * and quotes completion dates from shift clocks and capacity.
     *
     * The route key stays `/inventory/planning` deliberately — renaming a
     * live route would break every bookmark the floor has, and the screen's
     * address is not what anyone reads.
     */
    it('calls the planning screen Production Planning', () => {
        const planning = inventory?.children?.find((child) => child.key === '/inventory/planning');

        expect(planning?.label).toBe('Production Planning');
    });
});

describe('the Production menu', () => {
    const production = allNavItems.find((item) => item.key === 'production');

    it('exists with children', () => {
        expect(production?.children?.length).toBeGreaterThan(0);
    });

    /**
     * PRODUCTION QUEUE OPENS THE GROUP (27-Aug-2026). It is what the floor has
     * been asked to make, so it is the question a shift starts with; Shift
     * Floor, where the answer is entered, follows it. Pinned by POSITION and
     * not merely by presence, because "first" is the whole change — the entry
     * was already in this menu, third, and nothing in the type system notices
     * it sliding back down.
     */
    it('opens with the Production Queue, ahead of the Shift Floor', () => {
        expect(production?.children?.[0]).toMatchObject({
            key: '/production/queue',
            label: 'Production Queue',
        });
        expect(production?.children?.[1]?.key).toBe('/production/shift-production');
    });

    /**
     * Scrap Reasons, Molds and Shifts are TABS of Production Configuration
     * now. They are not children here and must not come back as children: the
     * whole point of the move was that a supervisor scrolls past fewer setup
     * screens to reach the ones a shift is actually entered from.
     *
     * Shifts joined them on 23-Aug-2026. The line this replaced asserted the
     * opposite — "still lists Shifts, which did not move" — so it is inverted
     * here rather than deleted: a test that pinned the old arrangement is the
     * exact thing that should go red when the arrangement changes, and
     * silently dropping it would have let Shifts drift back into the menu
     * with nothing objecting.
     */
    it.each([
        ['Scrap Reasons', '/production/scrap-reasons'],
        ['Molds', '/production/molds'],
        ['Shifts', '/production/shifts'],
    ])('does not list %s as a child', (label, key) => {
        expect(production?.children?.map((child) => child.label)).not.toContain(label);
        expect(production?.children?.map((child) => child.key)).not.toContain(key);
    });

    it('still lists the one configuration destination they moved into', () => {
        expect(production?.children?.map((child) => child.key)).toContain('/production/configuration');
    });

    /**
     * Carton Trace is the only child carrying its own module gate
     * (DEC-20260810-001: Owner / Plant Manager / Accounts, never Supervisor).
     * Pinned because the Scrap Reasons and Molds entries sat either side of
     * it, and a bad edit there is a permission regression that renders fine.
     * (Shifts, removed later, sat last in the children array — not beside
     * Carton Trace — so it is not part of that geography.)
     */
    it('gates Carton Trace, and only Carton Trace, at the child level', () => {
        const gated = production?.children?.filter((child) => child.module).map((child) => child.key);
        expect(gated).toEqual(['/production/carton-trace']);
    });
});

/**
 * SUPPLIER BILLS is procurement work done by Accounts: the API gates it on
 * module:finance (FC-06 — every figure on a bill is a purchase rate), the
 * Finance MODULE stays unadopted, and the sidebar therefore carries the one
 * permissionModule child. Two logins pin the geometry from both sides:
 * finance-only must REACH the page (the group surfaces with just that
 * child, even though the group's own module rejects the login — Codex on
 * 073a8c2), and procurement-only must NOT see it.
 *
 * TALLY VENDOR REVIEW JOINED IT on the same footing. Supplier IDENTITY is the
 * other half of FC-06 — "purchase rates and supplier details are
 * Owner/Accounts only" — so the review that confirms a party into the vendor
 * master is finance-gated exactly as the bills are. The list below is
 * therefore the full set of permissionModule children, and it is spelled out
 * rather than counted: an entry silently joining or leaving this set is a
 * change of who can see supplier data.
 */
describe('the finance-gated Procurement entries (permissionModule)', () => {
    const user = (permissions: string[]): User => ({
        id: 9,
        name: 'Gate probe',
        email: 'probe@example.test',
        is_active: true,
        permissions,
    });

    it('surfaces the Procurement group, with only the finance-gated entries, for a finance-only login', () => {
        const items = buildNavItems(user(['finance.view', 'finance.manage']));
        const procurement = items.find((item) => item.key === 'procurement');

        // Both halves of FC-06: the rates (Supplier Bills) and the supplier
        // identity (the Tally review, now a tab of Vendors). Nothing else in
        // the group. Vendors appears for this login because it carries the
        // finance half; the page itself opens on the review tab, since the
        // master list this login cannot read is not offered to it.
        expect(procurement?.children?.map((child) => child.key)).toEqual([
            '/procurement/vendors',
            '/procurement/supplier-bills',
        ]);
    });

    // The vendor entry is now an OR of two modules, so the login holding
    // NEITHER is a case worth pinning: an OR is only safe while it still
    // excludes everyone outside both halves.
    it('shows no Procurement group at all to a login holding neither module', () => {
        const items = buildNavItems(user(['production.view', 'production.manage']));

        expect(items.find((item) => item.key === 'procurement')).toBeUndefined();
    });

    it('hides the finance-gated entries from a procurement-only login, whose other entries are untouched', () => {
        const items = buildNavItems(user(['procurement.view', 'procurement.manage']));
        const procurement = items.find((item) => item.key === 'procurement');

        expect(procurement?.children?.map((child) => child.key)).toEqual([
            '/procurement/vendors',
            '/procurement/purchase-requisitions',
            '/procurement/purchase-orders',
            '/procurement/goods-receipts',
        ]);
    });
});

/**
 * THE FULFILMENT CONTROL BOARD — one screen, four teams, and a `permissionModule`
 * that is a LIST rather than a single string.
 *
 * The board's route accepts sales OR inventory OR production OR quality, because
 * it is the one place those four agree on what is true about an order line. But
 * it lives under Sales in the menu, and a GROUP is rejected before its children
 * are looked at — so a storekeeper, a supervisor or a quality user who was
 * perfectly entitled to open it was shown no path to it at all.
 *
 * That went from untidy to load-bearing with DEC-20260831-006: Quality now has
 * to sign a line off before anything can be dispatched, and a gate nobody can
 * navigate to is a gate that gets worked around.
 *
 * This is the Supplier Bills mechanism above, widened from one module to an OR —
 * and the OR has to be the same one the ROUTE grants, or the menu and the server
 * disagree about who may look.
 */
describe('the Fulfilment Control entry (permissionModule as a list)', () => {
    const BOARD = '/sales/fulfilment-control';

    function userWith(permissions: string[]): User {
        return { id: 9, name: 'Test', email: 't@example.test', is_active: true, permissions };
    }

    function sees(user: User | null, path: string): boolean {
        return buildNavItems(user).some((group) => group.children?.some((child) => child.key === path));
    }

    it.each([
        ['a sales desk', 'sales.view'],
        ['a storekeeper', 'inventory.view'],
        ['a supervisor', 'production.view'],
        ['a quality user', 'quality.view'],
    ])('is reachable by %s holding only %s', (_who, permission) => {
        expect(sees(userWith([permission]), BOARD)).toBe(true);
    });

    it('is reachable on a manage permission too, not only view', () => {
        expect(sees(userWith(['quality.manage']), BOARD)).toBe(true);
    });

    it('is hidden from a login holding none of the four', () => {
        expect(sees(userWith(['hrms.view', 'payroll.view']), BOARD)).toBe(false);
        expect(sees(userWith([]), BOARD)).toBe(false);
        expect(sees(null, BOARD)).toBe(false);
    });

    /**
     * Widening a child must not smuggle the rest of the Sales menu in with it:
     * a storekeeper still has no business on the customer, order or invoice
     * screens, and the server would refuse them anyway.
     *
     * TWO children are now deliberately shared, not one. Deliveries joined the
     * board when the STORE became the team that performs the final dispatch
     * action (DEC-20260901-005, resolving Q78) — the store must reach the
     * screen it dispatches from, and Sales keeps it to trace what left.
     */
    it('gains a store login exactly the two shared Sales children and no more', () => {
        const sales = buildNavItems(userWith(['inventory.view'])).find((group) => group.key === 'sales');
        const keys = sales?.children?.map((child) => child.key) ?? [];

        expect(keys).toEqual(['/sales/deliveries', BOARD]);
        expect(keys).not.toContain('/sales/customers');
        expect(keys).not.toContain('/sales/sales-orders');
        expect(keys).not.toContain('/sales/invoices');
    });
});
