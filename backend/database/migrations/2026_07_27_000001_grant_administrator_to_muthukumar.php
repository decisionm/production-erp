<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Data fix: Muthukumar's account was created without the Administrator
 * role, so the approval-stage buttons 403 for him. Administrator can act
 * at every stage (see ShiftProductionEntryController), which is what the
 * deployment owner needs for end-to-end testing and support.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = Role::findOrCreate('Administrator', 'web');

        User::query()
            ->where('email', 'like', '%muthukumar%')
            ->orWhere('name', 'like', '%muthukumar%')
            ->each(fn (User $user) => $user->assignRole($role));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Role grant — nothing safe to reverse automatically.
    }
};
