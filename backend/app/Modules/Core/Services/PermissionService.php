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
        // The machine master (Machines & Capabilities) — a CATALOG module of
        // its own, not a corner of Production, because the two audiences
        // differ: every supervisor must READ the machine list (every product
        // picker, every Start Batch screen and every configuration form
        // depends on it), but only the office changes what a machine IS.
        // Splitting the write side into its own module is what makes
        // "supervisors view, office edits" expressible as a role instead of
        // a convention. It has to be a catalog entry rather than a
        // hand-created permission: RoleService intersects every grant with
        // this list, so a permission missing from here is stripped from every
        // role on the next save and the guard then 403s everyone.
        'machine-master' => 'Machine Master',
        // The INTERNAL carton trace tier (DEC-20260810-001): a scanned
        // carton's completion datetime, day-bin lot attribution and batch
        // costing rate — Owner, Plant Manager and Accounts ONLY, an
        // owner-worded widening of FC-06's rate scope for this one surface.
        // Its own catalog entry (the machine-master precedent) because the
        // audience differs from Production's: every supervisor scans cartons
        // (the public lookup, under module:production), but the rates behind
        // a batch are not the floor's to read — and only a catalog entry
        // survives RoleService's grant intersection.
        'carton-trace' => 'Carton Trace (Internal)',
        // THE CONFIGURATION HARD-DELETE TIER (DEC-20260817-002 §3): deleting a
        // configuration master OUTRIGHT is Super Admin / Owner level, while
        // create / edit / activate / deactivate stay on each module's own
        // `manage` permission. A catalog module of its own for the same
        // mechanical reason as the two above: RoleService intersects every
        // grant with this list, so a permission that is not here is stripped
        // from every role on the next save through the Roles screen and the
        // tier then fails silently for everyone who was granted it.
        //
        // The half that is REAL is `configuration-delete.manage` — a delete is
        // a write; `.view` is the vestigial twin, exactly as carton-trace's
        // `.manage` is. It gates NO route group: it is read by
        // App\Support\Configuration\HardDeleteAuthority as the lifecycle's
        // canHardDelete seam, so a user who may manage a module still gets
        // Archive while being refused Delete. PermissionSeeder hands the
        // Administrator role every catalog permission, which is how the owner
        // receives this one; no other role does unless a human grants it.
        'configuration-delete' => 'Configuration Hard Delete',
        // RECORDING AN OFF-PLAN CONSUMPTION LINE (DEC-20260901-001): when a
        // packing or consumption material runs short mid-run, saying what was
        // ACTUALLY used instead is an authorised act, not a floor
        // convenience — the owner's rule is that the ERP must never silently
        // substitute one item for another, and an unrestricted floor is how
        // "silently" happens.
        //
        // Its own catalog entry for the same mechanical reason as the two
        // above, and it is the reason this is not `production.substitute-
        // material`: RoleService intersects every grant with this list, so a
        // permission that is not here is stripped from every role the next
        // time somebody saves through the Roles screen — the grant would work
        // until an unrelated role edit quietly removed it. (production.
        // override-fifo sits outside the catalog today and carries that
        // latent problem; it is not copied here.)
        //
        // The half that is REAL is `material-substitution.manage` — recording
        // one is a write. `.view` is the vestigial twin, as carton-trace's
        // `.manage` is: it gates nothing, because the dropdown of what may be
        // substituted is shown only to somebody who may actually record one.
        'material-substitution' => 'Material Substitution',
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
