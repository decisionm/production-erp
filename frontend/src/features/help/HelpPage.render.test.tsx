import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderToString } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import { outlineNavItems } from '@/app/AppLayout';
import { HELP_BY_ROUTE } from '@/features/help/helpContent';
import HelpPage from '@/features/help/pages/HelpPage';
import type { User } from '@/features/auth/types';

/**
 * HELP DESCRIBES THE MENU, AND ONLY THE MENU.
 *
 * Both directions are pinned. A screen the sidebar shows must have words
 * here, or a reader finds a heading and nothing under it; words for a screen
 * the sidebar does not show would describe a route the reader cannot reach.
 * The render assertions then check that the filtering is the user's own:
 * a production login is not told about Finance.
 */

let permissions: string[] = [];

vi.mock('@/features/auth/store', () => ({
    useAuthStore: (selector: (state: unknown) => unknown) =>
        selector({
            user: { id: 3, name: 'Fixture Reader', email: 'fixture@example.test', is_active: true, permissions },
            setUser: vi.fn(),
        }),
}));

const MODULES = [
    'inventory', 'production', 'procurement', 'quality', 'tally-sync', 'carton-trace', 'users', 'roles',
    'sales', 'maintenance', 'compliance', 'hrms', 'payroll', 'finance', 'crm',
];

const everything: User = {
    id: 1,
    name: 'Everything',
    email: 'all@example.test',
    is_active: true,
    permissions: MODULES.flatMap((module) => [`${module}.view`, `${module}.manage`]),
} as User;

const visibleRoutes = (user: User): string[] =>
    outlineNavItems(user).flatMap((group) => (group.children ? group.children.map((leaf) => leaf.key) : [group.key]));

function render(): string {
    const queryClient = new QueryClient();

    return renderToString(
        <QueryClientProvider client={queryClient}>
            <MemoryRouter initialEntries={['/help']}>
                <HelpPage />
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

describe('help content against the menu', () => {
    it('has words for every screen the fullest menu shows', () => {
        const missing = visibleRoutes(everything).filter((route) => route !== '/help' && !HELP_BY_ROUTE[route]);

        expect(missing).toEqual([]);
    });

    it('describes no screen the fullest menu does not show', () => {
        const routes = new Set(visibleRoutes(everything));
        const orphans = Object.keys(HELP_BY_ROUTE).filter((route) => !routes.has(route));

        expect(orphans).toEqual([]);
    });
});

describe('HelpPage', () => {
    it('tells a production login about its own screens and not about Finance', () => {
        permissions = ['production.view'];
        const html = render();

        expect(html).toContain('Shift Floor');
        expect(html).toContain('href="/production/shift-production"');
        expect(html).not.toContain('Client Outstanding');
        expect(html).not.toContain('Purchase Orders');
    });

    it('shows a full menu every group, each screen linked to its route', () => {
        permissions = everything.permissions ?? [];
        const html = render();

        for (const route of visibleRoutes(everything).filter((r) => r !== '/help')) {
            expect(html, route).toContain(`href="${route}"`);
        }
    });
});
