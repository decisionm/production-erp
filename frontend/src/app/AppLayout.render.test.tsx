import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderToString } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import AppLayout from './AppLayout';

vi.mock('@/features/auth/api', () => ({
    logout: vi.fn(async () => undefined),
}));

vi.mock('@/features/auth/store', () => ({
    useAuthStore: (selector: (state: unknown) => unknown) =>
        selector({
            user: {
                id: 41,
                name: 'Fixture Supervisor',
                email: 'fixture@example.test',
                is_active: true,
                permissions: ['finance.view'],
            },
            setUser: vi.fn(),
        }),
}));

function renderLayout(pathname = '/finance/client-outstanding'): string {
    const queryClient = new QueryClient({ defaultOptions: { mutations: { retry: false } } });

    return renderToString(
        <QueryClientProvider client={queryClient}>
            <MemoryRouter initialEntries={[pathname]}>
                <AppLayout>
                    <main>Page body</main>
                </AppLayout>
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

describe('AppLayout usability controls', () => {
    it('gives the navigation and account controls keyboard-readable names', () => {
        const html = renderLayout();

        expect(html).toContain('aria-label="Primary navigation"');
        expect(html).toContain('aria-label="Close navigation"');
        expect(html).toContain('aria-label="Account menu for Fixture Supervisor"');
        expect(html).toContain('href="#main-content"');
        expect(html).toContain('id="main-content"');
    });

    it('shows the current module and page in the header', () => {
        const html = renderLayout();

        expect(html).toContain('aria-label="Current page"');
        expect(html).toContain('Finance');
        expect(html).toContain('Client Outstanding');
    });
});
