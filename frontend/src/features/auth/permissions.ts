import type { User } from './types';

export function hasModuleAccess(user: User | null, module: string): boolean {
    if (!user?.permissions) return false;
    return user.permissions.includes(`${module}.view`) || user.permissions.includes(`${module}.manage`);
}

export function hasManageAccess(user: User | null, module: string): boolean {
    if (!user?.permissions) return false;
    return user.permissions.includes(`${module}.manage`);
}
