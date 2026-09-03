import { describe, expect, it } from 'vitest';
import type { User } from '@/features/auth/types';
import { settingsSections } from '@/features/settings/settingsSections';

/**
 * THE PIN IS "no card leads to a 403".
 *
 * Settings gathers four destinations that were four sidebar entries, and two
 * of them (Users, Roles) are permission-gated in AppLayout. A card is a
 * promise that the screen opens; showing one to a login the server will
 * refuse is the whole defect this file exists to keep out.
 */
function login(permissions: string[]): User {
    return {
        id: 7,
        name: 'Fixture Login',
        email: 'fixture@example.test',
        is_active: true,
        permissions,
    };
}

const ADMINISTRATOR = login(['users.view', 'users.manage', 'roles.view', 'roles.manage', 'production.view']);

describe('settingsSections', () => {
    it('offers an administrator every section, Downloads and Help first', () => {
        expect(settingsSections(ADMINISTRATOR)).toEqual([
            { key: 'downloads', label: 'Downloads', hint: 'Exports and reports', to: '/exports' },
            { key: 'help', label: 'Help', hint: 'What each screen does', to: '/help' },
            { key: 'users', label: 'Users', hint: 'Logins and access', to: '/administration/users' },
            { key: 'roles', label: 'Roles', hint: 'Permissions per role', to: '/administration/roles' },
        ]);
    });

    it('hides Users and Roles from a login holding neither module', () => {
        const keys = settingsSections(login(['production.view', 'inventory.view'])).map((section) => section.key);

        expect(keys).toEqual(['downloads', 'help']);
    });

    it('shows only the administration screen the login actually holds', () => {
        expect(settingsSections(login(['users.view'])).map((section) => section.key)).toEqual([
            'downloads',
            'help',
            'users',
        ]);
        expect(settingsSections(login(['roles.manage'])).map((section) => section.key)).toEqual([
            'downloads',
            'help',
            'roles',
        ]);
    });

    it('still offers Downloads and Help with no user at all', () => {
        expect(settingsSections(null).map((section) => section.to)).toEqual(['/exports', '/help']);
    });

    it('gives every section a distinct route', () => {
        const routes = settingsSections(ADMINISTRATOR).map((section) => section.to);

        expect(new Set(routes).size).toBe(routes.length);
    });
});
