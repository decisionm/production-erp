<?php

namespace App\Modules\Core\Services;

/**
 * The single source of truth for "which features exist" — both the Roles
 * UI's checkbox picker and PermissionSeeder read from here, so adding a
 * new module to the catalog is a one-line change that propagates to both
 * the seeded permission rows and the frontend picker without duplication.
 *
 * Deliberately coarse-grained: one "view" and one "manage" permission per
 * feature module, not per action. A real per-action model (separate
 * permissions for approve/release/complete/etc. on top of create/update)
 * would multiply this catalog many times over for marginal benefit here —
 * "manage" covers all of a module's write actions, "view" covers all of
 * its read endpoints. Dashboard has no entry: it's a read-only aggregate
 * landing page available to every authenticated user regardless of role.
 */
class PermissionService
{
    public const array MODULES = [
        'users' => 'User Management',
        'roles' => 'Roles & Permissions',
        'crm' => 'CRM',
        'inventory' => 'Inventory',
        'procurement' => 'Procurement',
        'production' => 'Production',
        'sales' => 'Sales',
        'finance' => 'Finance',
        'quality' => 'Quality',
        'compliance' => 'Compliance',
        'hrms' => 'HRMS',
        'payroll' => 'Payroll',
        'maintenance' => 'Maintenance',
        'tally-sync' => 'Tally Sync',
    ];

    /**
     * @return array<int, array{module: string, label: string, permissions: array<int, array{name: string, label: string}>}>
     */
    public function catalog(): array
    {
        return collect(self::MODULES)
            ->map(fn (string $label, string $module) => [
                'module' => $module,
                'label' => $label,
                'permissions' => [
                    ['name' => "{$module}.view", 'label' => 'View'],
                    ['name' => "{$module}.manage", 'label' => 'Manage'],
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function allPermissionNames(): array
    {
        return collect(self::MODULES)
            ->keys()
            ->flatMap(fn (string $module) => ["{$module}.view", "{$module}.manage"])
            ->all();
    }
}
