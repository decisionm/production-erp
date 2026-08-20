import { describe, expect, it } from 'vitest';
import type { User } from '@/features/auth/types';
import { ADOPTED_MODULES } from '@/lib/adoptedModules';
import { allNavItems, buildNavItems } from './AppLayout';

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
    'Tally Sync',
    // Utilities, below the divider AppLayout inserts before Downloads.
    'Downloads',
    'Help',
    'Administration',
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
        // Dashboard, Downloads and Help carry no `module`, so they survive —
        // that is deliberate (see the comments on those entries) and is
        // pinned here so a stray gate on one of them is a red test.
        expect(buildNavItems(null).map((item) => item.label)).toEqual(['Dashboard', 'Downloads', 'Help']);
    });
});

describe('the Production menu', () => {
    const production = allNavItems.find((item) => item.key === 'production');

    it('exists with children', () => {
        expect(production?.children?.length).toBeGreaterThan(0);
    });

    /**
     * Scrap Reasons and Molds are TABS of Production Configuration now. They
     * are not children here and must not come back as children: the whole
     * point of the move was that a supervisor scrolls past fewer setup
     * screens to reach the ones a shift is actually entered from.
     */
    it.each([
        ['Scrap Reasons', '/production/scrap-reasons'],
        ['Molds', '/production/molds'],
    ])('does not list %s as a child', (label, key) => {
        expect(production?.children?.map((child) => child.label)).not.toContain(label);
        expect(production?.children?.map((child) => child.key)).not.toContain(key);
    });

    it('still lists Shifts, which did not move', () => {
        expect(production?.children?.map((child) => child.key)).toContain('/production/shifts');
    });

    it('still lists the one configuration destination they moved into', () => {
        expect(production?.children?.map((child) => child.key)).toContain('/production/configuration');
    });

    /**
     * Carton Trace is the only child carrying its own module gate
     * (DEC-20260810-001: Owner / Plant Manager / Accounts, never Supervisor).
     * Pinned because the two entries removed above sat either side of it, and
     * a bad edit there is a permission regression that renders fine.
     */
    it('gates Carton Trace, and only Carton Trace, at the child level', () => {
        const gated = production?.children?.filter((child) => child.module).map((child) => child.key);
        expect(gated).toEqual(['/production/carton-trace']);
    });
});
