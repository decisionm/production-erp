import { renderToString } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import SettingsPage from '@/features/settings/pages/SettingsPage';

/**
 * This file sits in `features/settings/`, NOT in `pages/`, on purpose:
 * App.routes.test.tsx globs every .tsx under a feature's `pages/` folder and
 * asserts each one default-exports a component. A test module there would
 * fail that check for having no default export.
 *
 * The store mock is the selector-calling shape AppLayout.render.test.tsx
 * uses, so the page must read it as `useAuthStore((state) => state.user)`.
 */
vi.mock('@/features/auth/store', () => ({
    useAuthStore: (selector: (state: unknown) => unknown) =>
        selector({
            user: {
                id: 1,
                name: 'Fixture Administrator',
                email: 'admin@example.test',
                is_active: true,
                permissions: ['users.view', 'roles.view'],
            },
            setUser: vi.fn(),
        }),
}));

function renderSettings(): string {
    return renderToString(
        <MemoryRouter initialEntries={['/settings']}>
            <SettingsPage />
        </MemoryRouter>,
    );
}

describe('the Settings page', () => {
    it('is headed Settings', () => {
        expect(renderSettings()).toContain('Settings</h3>');
    });

    it('links an administrator to all four destinations', () => {
        const html = renderSettings();

        expect(html).toContain('href="/exports"');
        expect(html).toContain('href="/help"');
        expect(html).toContain('href="/administration/users"');
        expect(html).toContain('href="/administration/roles"');
    });

    it('names each destination and what is there', () => {
        const html = renderSettings();

        for (const text of [
            'Downloads',
            'Exports and reports',
            'Help',
            'What each screen does',
            'Users',
            'Logins and access',
            'Roles',
            'Permissions per role',
        ]) {
            expect(html).toContain(text);
        }
    });
});
