<?php

namespace Database\Seeders;

use App\Modules\Core\Services\PermissionService;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Idempotent: findOrCreate/syncPermissions so this is safe to run on every
 * `migrate:fresh --seed` as well as against a live database that already
 * has roles/permissions from prior runs.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = app(PermissionService::class);

        // Pass the created model instances (not name strings) to
        // syncPermissions() below — spatie's permission cache doesn't
        // reliably see permissions just created in the same request when
        // looked up by name, so a name-based syncPermissions() call
        // immediately after this loop can throw PermissionDoesNotExist.
        $permissions = collect($catalog->allPermissionNames())
            ->map(fn (string $name) => Permission::findOrCreate($name, 'web'));

        $administrator = Role::findOrCreate('Administrator', 'web');
        $administrator->syncPermissions($permissions);

        // THE OFFICE TIER — what Plant Manager and Accounts hold beyond their
        // own modules. The owner logs in as Administrator (which the sync
        // above already hands every permission); these two are the named
        // roles the approval chain checks by exactly these strings
        // (ShiftProductionEntryController), so a grant here lands on the same
        // identities that already hold PM and accountant approval.
        //
        //   carton-trace.view          the INTERNAL carton scan tier
        //                              (DEC-20260810-001): completion
        //                              datetime, day-bin lot attribution and
        //                              the batch's costing rate — never
        //                              Supervisor, never public.
        //   consumption-substitute.manage
        //                              recording a consumption line the run
        //                              was NOT planned on. Completing a batch
        //                              is every supervisor's job; booking a
        //                              material that stood in for another one
        //                              is the office's, by the owner's word
        //                              (01-Sep-2026, answering the question
        //                              this seeder's previous state posed by
        //                              granting it to nobody but the owner).
        //
        // givePermissionTo, NOT syncPermissions: these roles carry permissions
        // configured through the Roles UI on the live instance, and this
        // seeder re-runs on every deploy — it must only ever ADD these, never
        // rewrite what an administrator granted.
        //
        // `.manage` and not `.view` for the substitution tier: the `.view`
        // half is the vestigial twin the catalog shape forces (it gates no
        // route), exactly as carton-trace's `.manage` is.
        $officeTier = $permissions->whereIn('name', [
            'carton-trace.view',
            'consumption-substitute.manage',
        ])->values();

        foreach (['Plant Manager', 'Accounts'] as $roleName) {
            Role::findOrCreate($roleName, 'web')->givePermissionTo($officeTier->all());
        }
    }
}
