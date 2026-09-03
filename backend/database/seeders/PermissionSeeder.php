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

        // THE INTERNAL CARTON TRACE TIER (DEC-20260810-001): visible to
        // Owner, Plant Manager and Accounts logins ONLY — never Supervisor.
        // The owner logs in as Administrator (which the sync above already
        // hands every permission); Plant Manager and Accounts are the two
        // named roles the approval chain already checks by exactly these
        // strings (ShiftProductionEntryController), so the grant lands on
        // the same identities that hold PM/Accountant approval today.
        //
        // givePermissionTo, NOT syncPermissions: these roles carry
        // permissions configured through the Roles UI on the live instance,
        // and this seeder re-runs on every deploy — it must only ever ADD
        // its one permission, never rewrite what an administrator granted.
        $cartonTrace = $permissions->firstWhere('name', 'carton-trace.view');

        foreach (['Plant Manager', 'Accounts'] as $roleName) {
            Role::findOrCreate($roleName, 'web')->givePermissionTo($cartonTrace);
        }

        // THE ADDED-CONSUMPTION-LINE TIER (DEC-20260901-007, resolving Q91).
        // The owner's answer: Administrator AND Plant Manager, and nobody
        // else by default. The Administrator already holds it through the
        // catalog sync above; this grants the Plant Manager, who is on the
        // floor when a material runs out and is the person the completion
        // drawer's own refusal tells the supervisor to fetch. Accounts is
        // deliberately NOT granted — booking what a machine ate is a floor
        // act, not a books one.
        //
        // givePermissionTo, NOT syncPermissions, for the same reason the
        // carton-trace grant above uses it: these roles carry permissions
        // configured through the Roles UI on the live instance and this
        // seeder re-runs on every deploy, so it must only ever ADD its one
        // permission, never rewrite what an administrator granted.
        $addedLine = $permissions->firstWhere('name', 'consumption-substitute.manage');

        foreach (['Plant Manager'] as $roleName) {
            Role::findOrCreate($roleName, 'web')->givePermissionTo($addedLine);
        }

        // PROCUREMENT WRITE FOR ACCOUNTS (DEC-20260903-006): Accounts holds
        // full procurement write so that Accounts may approve a purchase
        // requisition. Approval is gated on the procurement write permission,
        // and the procurement module has ONE write permission covering
        // requisitions and their approval, purchase orders, goods receipts
        // and vendors; there is no narrower approve-only grant. The owner was
        // told this trade-off (Accounts can now also raise requisitions,
        // purchase orders and record receipts, same as Store) and chose full
        // procurement for Accounts.
        //
        // givePermissionTo, NOT syncPermissions: this role carries permissions
        // configured through the Roles UI on the live instance, and this
        // seeder re-runs on every deploy — it must only ever ADD these
        // permissions, never rewrite what an administrator granted.
        $procurementPerms = [
            $permissions->firstWhere('name', 'procurement.view'),
            $permissions->firstWhere('name', 'procurement.manage'),
        ];

        // AND THE SAME GRANT FOR STORE (DEC-20260903-008). Store already held
        // procurement.view on the live instance — it could see procurement
        // but not act in it, so the storekeeper was refused at approval.
        // The whole delta is procurement.manage, ADDED to whatever the Roles
        // screen has given this role.
        //
        // FC-06 IS INTACT, WITH ONE PRACTICAL CONSEQUENCE. The purchase rate
        // is Owner/Accounts only and the gate ships: PurchaseOrderLineResource
        // and GoodsReceiptNoteLineResource OMIT unit_price for a reader
        // without finance.*, and supplier bills sit behind module:finance
        // entirely — so this grant hands Store no rate. But creating and
        // amending a purchase order both REQUIRE unit_price, so a Store user
        // cannot amend an order without typing a rate they cannot read, which
        // would overwrite the real one. Purchase orders stay with Accounts and
        // the owner in practice; what this grant is for is requisition
        // approval and goods receipts.
        foreach (['Accounts', 'Store'] as $roleName) {
            Role::findOrCreate($roleName, 'web')->givePermissionTo($procurementPerms);
        }
    }
}
