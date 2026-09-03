import { hasModuleAccess } from '@/features/auth/permissions';
import type { User } from '@/features/auth/types';
import { ADOPTED_MODULES } from '@/lib/adoptedModules';

/**
 * What the Settings page offers, and to whom.
 *
 * THE GATE IS THE SIDEBAR'S GATE, mirrored deliberately rather than
 * approximated. AppLayout's `allNavItems` puts Downloads and Help below the
 * utility divider with NO module — every login may open them, and the
 * server's export catalogue is what decides which kinds an accountant is
 * actually offered — and gates Administration's two children on `users` and
 * `roles` individually. `buildNavItems` then applies ADOPTION before
 * permission, so both conjuncts are mirrored here: a card whose module
 * leaves ADOPTED_MODULES must vanish from this page the same day it vanishes
 * from the menu. A card that leads to a 403 is worse than no card.
 *
 * PURE ON PURPOSE — no JSX, no icons. The icon for a section is chosen by
 * SettingsPage from `key`, which keeps this module and its test free of the
 * component library.
 */
export interface SettingsSection {
    key: string;
    label: string;
    hint: string;
    to: string;
}

/** The sidebar's own gate for a child entry: adopted, then permitted. */
function reaches(user: User | null, module: string): boolean {
    return ADOPTED_MODULES.has(module) && hasModuleAccess(user, module);
}

export function settingsSections(user: User | null): SettingsSection[] {
    const sections: SettingsSection[] = [
        // Ungated, exactly as the nav has them.
        { key: 'downloads', label: 'Downloads', hint: 'Exports and reports', to: '/exports' },
        { key: 'help', label: 'Help', hint: 'What each screen does', to: '/help' },
    ];

    if (reaches(user, 'users')) {
        sections.push({ key: 'users', label: 'Users', hint: 'Logins and access', to: '/administration/users' });
    }

    if (reaches(user, 'roles')) {
        sections.push({ key: 'roles', label: 'Roles', hint: 'Permissions per role', to: '/administration/roles' });
    }

    return sections;
}
