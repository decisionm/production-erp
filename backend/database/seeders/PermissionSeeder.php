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
    }
}
