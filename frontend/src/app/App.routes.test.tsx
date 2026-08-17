import { Children, isValidElement } from 'react';
import type { ReactElement, ReactNode } from 'react';
import { Navigate, Route } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import App from './App';

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
    '/inventory/material-lots',
    '/inventory/batches',
    '/inventory/serial-numbers',
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
    '/production/shift-summary',
    '/production/approve-production',
    '/production/reports',
    '/production/rework-orders',
    '/procurement/vendors',
    '/procurement/purchase-requisitions',
    '/procurement/purchase-orders',
    '/procurement/goods-receipts',
    '/sales/customers',
    '/sales/sales-orders',
    '/sales/deliveries',
    '/sales/invoices',
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
 * /production/standards is NOT here on purpose — its redirect is a
 * component (ProductStandardsRedirect) because it must carry the incoming
 * query string, so it reads as a page to this check.
 */
const REDIRECT_ROUTES = ['/production/work-centers', '*'];

type RouteProps = { path?: string; element?: ReactNode; children?: ReactNode };

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
