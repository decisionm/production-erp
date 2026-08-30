import { Children, isValidElement } from 'react';
import type { ReactElement, ReactNode } from 'react';
import { Navigate, Route } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import App, { productionConfigurationTarget } from './App';

/**
 * THE ROUTE TABLE IS A CONTRACT (Phase 7 regression smoke, P7-05).
 *
 * Bookmarks, the sidebar menu, printed wall notes, the Start Batch return
 * trip and the Tally failure deep-links all carry these paths. A route that
 * is renamed or dropped does not fail the build — the SPA still compiles,
 * the `*` catch-all quietly sends the reader to the dashboard, and nothing
 * says a page went missing until somebody's bookmark lands on the wrong
 * screen. This pin turns that into a red test.
 *
 * Adding a route is a deliberate act: add it here too, in the same place
 * it sits in App.tsx. Removing one is a deliberate act with a paper trail:
 * remove it here and say in the PR why the URL may die (or keep a redirect,
 * as /production/work-centers and /production/standards do).
 *
 * The table is read by walking the element tree App() returns — Route
 * elements are plain data until a router renders them, so no DOM, no
 * router context and no rendering are involved. Every page module App.tsx
 * imports is evaluated by that import; the sibling test below goes further
 * and loads every page module on disk as a lazy chunk.
 */
const ROUTE_TABLE = [
    '/login',
    '/*',
    '/',
    '/account/change-password',
    '/crm/leads',
    '/crm/opportunities',
    '/crm/quotations',
    '/inventory/items',
    '/inventory/items/:id',
    '/inventory/warehouses',
    '/inventory/stock',
    '/inventory/stock-movements',
    '/inventory/material-lots',
    '/inventory/barcode-labels',
    '/inventory/batches',
    '/inventory/serial-numbers',
    '/inventory/store-issue-queue',
    '/inventory/production-returns',
    '/inventory/fulfilment',
    '/inventory/planning',
    '/production/work-centers',
    '/production/configuration',
    '/production/boms',
    '/production/routings',
    '/production/work-orders',
    '/production/mrp',
    '/production/capacity',
    '/production/subcontract-orders',
    '/production/scrap-reasons',
    '/production/molds',
    '/production/shifts',
    '/production/shift-production',
    '/production/live-monitor',
    '/production/carton-trace',
    '/production/standards',
    '/production/day-bin',
    '/production/material-requests',
    '/production/queue',
    '/production/shift-summary',
    '/production/approve-production',
    '/production/reports',
    '/production/rework-orders',
    '/procurement/vendors',
    '/procurement/purchase-requisitions',
    '/procurement/purchase-orders',
    '/procurement/goods-receipts',
    '/procurement/supplier-bills',
    '/sales/customers',
    '/sales/sales-orders',
    '/sales/deliveries',
    '/sales/invoices',
    '/sales/fulfilment-control',
    '/finance/chart-of-accounts',
    '/finance/journal-entries',
    '/finance/reports',
    '/quality/production-qc',
    '/quality/incoming-inspections',
    '/quality/ncrs',
    '/quality/capas',
    '/quality/instruments',
    '/quality/spc-characteristics',
    '/quality/spc/:id',
    '/compliance/gst-rates',
    '/compliance/gst-registrations',
    '/compliance/gst-reports',
    '/hrms/employees',
    '/hrms/leave-types',
    '/hrms/leave-balances',
    '/hrms/leave-requests',
    '/hrms/attendance',
    '/payroll/salary-components',
    '/payroll/salary-structures',
    '/payroll/runs',
    '/payroll/payslips',
    '/maintenance/assets',
    '/maintenance/schedules',
    '/maintenance/work-orders',
    '/maintenance/reliability',
    '/tally-sync',
    '/tally-sync/agent-tokens',
    '/tally-sync/settings',
    '/exports',
    '/help',
    '/administration/users',
    '/administration/roles',
    '*',
];

/**
 * The routes whose element is a bare <Navigate>, not a page: the retired
 * Work Centers URL and the catch-all. Named so a redirect quietly becoming
 * a page (or a page quietly becoming a redirect) is a diff someone reads.
 *
 * FOUR retired URLs are deliberately NOT here — /production/standards,
 * /production/scrap-reasons, /production/molds and /production/shifts. Each
 * redirects through a COMPONENT (ProductionConfigurationRedirect) because it
 * must carry the incoming query string, so all four read as a page to this
 * check. Do not "fix" that by adding them: the check below would go red. What
 * they actually redirect to is pinned separately, in "the retired production
 * configuration URLs".
 */
const REDIRECT_ROUTES = ['/production/work-centers', '*'];

/**
 * The retired URLs that now land on a Production Configuration tab, and the
 * tab each one must land on.
 *
 * Wired-up-ness is the thing worth pinning here. `productionConfigurationTarget`
 * being correct proves nothing about App.tsx passing it the right tab — the
 * pure function is just as happy sending /production/molds to the scrap tab.
 */
const CONFIG_REDIRECTS: { path: string; tab: string }[] = [
    { path: '/production/scrap-reasons', tab: 'scrap' },
    { path: '/production/molds', tab: 'molds' },
    { path: '/production/standards', tab: 'products' },
    // 23-Aug-2026. Note the UI route retired here shares its string with the
    // LIVE API path `production/shifts` (features/production/api.ts,
    // components/configuration/entities.ts), which did not move. Only this
    // one — the browser URL — redirects.
    { path: '/production/shifts', tab: 'shifts' },
];

type RouteProps = { path?: string; element?: ReactNode; children?: ReactNode; tab?: string };

type FoundRoute = { path: string; element: ReactNode };

function collectRoutes(node: ReactNode, out: FoundRoute[]): void {
    Children.forEach(node, (child) => {
        if (!isValidElement(child)) return;
        const el = child as ReactElement<RouteProps>;
        if (el.type === Route && typeof el.props.path === 'string') {
            out.push({ path: el.props.path, element: el.props.element });
        }
        if (el.props.element) collectRoutes(el.props.element, out);
        if (el.props.children) collectRoutes(el.props.children, out);
    });
}

function elementKind(element: ReactNode): 'page' | 'redirect' | 'other' {
    if (!isValidElement(element)) return 'other';
    if (element.type === Navigate) return 'redirect';
    return typeof element.type === 'function' ? 'page' : 'other';
}

/**
 * Every page module on disk, as the lazy chunk a code-split build would
 * hand the router. Loading each one proves the module evaluates in
 * isolation and exports a component — a chunk that throws on import, or a
 * page whose default export went missing, is a blank screen in production
 * that no typecheck catches.
 */
const PAGE_MODULES = import.meta.glob('../features/**/pages/*.tsx');

describe('the route table', () => {
    const routes: FoundRoute[] = [];
    collectRoutes(App(), routes);

    it('is exactly the pinned list, in declaration order', () => {
        expect(routes.map((route) => route.path)).toEqual(ROUTE_TABLE);
    });

    it('has no duplicate path', () => {
        const paths = routes.map((route) => route.path);
        expect(new Set(paths).size).toBe(paths.length);
    });

    it('renders a page component on every route that is not a named redirect', () => {
        const wrong = routes
            .filter((route) => route.path !== '/*') // the layout shell, whose element is <ProtectedRoute>
            .filter((route) => {
                const expected = REDIRECT_ROUTES.includes(route.path) ? 'redirect' : 'page';
                return elementKind(route.element) !== expected;
            })
            .map((route) => `${route.path} → ${elementKind(route.element)}`);

        expect(wrong).toEqual([]);
    });
});

/**
 * THE RETIRED PRODUCTION CONFIGURATION URLs.
 *
 * Four menu entries became four tabs. The URLs outlive the menu entries
 * because bookmarks and printed wall notes still point at them, and because
 * /production/standards in particular has two in-app deep links carrying
 * state (the blocked-Start-Batch return trip, the Tally failure links; see
 * App.tsx). No such caller is known for /production/scrap-reasons,
 * /production/molds or /production/shifts — they take the same redirect
 * anyway, because a redirect that drops a query string is worse than a 404:
 * it lands the reader on a plausible-looking screen with their context
 * silently gone.
 *
 * Both halves are pinned: the target URL the redirect computes, and the fact
 * that App.tsx hands each path the right tab.
 */
describe('the retired production configuration URLs', () => {
    const routes: FoundRoute[] = [];
    collectRoutes(App(), routes);

    it.each(CONFIG_REDIRECTS)('routes $path to the $tab tab', ({ path, tab }) => {
        const route = routes.find((r) => r.path === path);
        expect(route, `${path} is not in the route table`).toBeDefined();
        const element = route!.element;
        expect(isValidElement(element)).toBe(true);
        expect((element as ReactElement<RouteProps>).props.tab).toBe(tab);
    });

    it.each(CONFIG_REDIRECTS)('carries an existing query string to $tab', ({ tab }) => {
        const target = productionConfigurationTarget('?view=incomplete&missing_tally=1', tab);
        const params = new URLSearchParams(target.split('?')[1]);

        expect(target.split('?')[0]).toBe('/production/configuration');
        expect(params.get('view')).toBe('incomplete');
        expect(params.get('missing_tally')).toBe('1');
        expect(params.get('tab')).toBe(tab);
    });

    it.each(CONFIG_REDIRECTS)('overwrites an incoming tab rather than duplicating it, for $tab', ({ tab }) => {
        const target = productionConfigurationTarget('?tab=settings&keep=me', tab);
        const params = new URLSearchParams(target.split('?')[1]);

        // getAll, not get: two `tab` values would still read correctly through
        // `get` if the target happened to come first, and that is exactly the
        // bug this pins.
        expect(params.getAll('tab')).toEqual([tab]);
        expect(params.get('keep')).toBe('me');
    });

    it('preserves the blocked Start Batch resume parameters intact', () => {
        const resume = '?phase=configure&resume_item_id=42&resume_work_center_id=7';
        const target = productionConfigurationTarget(resume, 'products');
        const params = new URLSearchParams(target.split('?')[1]);

        expect(params.get('phase')).toBe('configure');
        expect(params.get('resume_item_id')).toBe('42');
        expect(params.get('resume_work_center_id')).toBe('7');
        expect(params.get('tab')).toBe('products');
    });

    it('still produces a tab when nothing came in', () => {
        expect(productionConfigurationTarget('', 'molds')).toBe('/production/configuration?tab=molds');
    });
});

describe('every page module', () => {
    it('is found by the glob (the lint checks something)', () => {
        expect(Object.keys(PAGE_MODULES).length).toBeGreaterThan(60);
    });

    it('imports as a lazy chunk and exports a component', async () => {
        const failures: string[] = [];

        for (const [path, load] of Object.entries(PAGE_MODULES)) {
            try {
                const mod = (await load()) as { default?: unknown };
                if (typeof mod.default !== 'function') {
                    failures.push(`${path}: no default-exported component`);
                }
            } catch (error) {
                failures.push(`${path}: ${(error as Error).message.split('\n')[0]}`);
            }
        }

        expect(failures).toEqual([]);
    }, 120_000);
});
