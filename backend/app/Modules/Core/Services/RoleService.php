<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Exceptions\RoleInUseException;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

class RoleService
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function list(): Collection
    {
        return Role::query()
            ->withCount('users')
            ->with('permissions')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array{name: string, permissions?: array<int, string>}  $data
     */
    public function create(array $data): Role
    {
        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
        $role->syncPermissions($this->validPermissions($data['permissions'] ?? []));

        return $role->load('permissions');
    }

    /**
     * @param  array{name?: string, permissions?: array<int, string>}  $data
     */
    public function update(Role $role, array $data): Role
    {
        if (isset($data['name'])) {
            $role->update(['name' => $data['name']]);
        }

        if (isset($data['permissions'])) {
            $role->syncPermissions($this->validPermissions($data['permissions']));
        }

        return $role->load('permissions');
    }

    /**
     * A role still assigned to users can't be deleted outright — that
     * would silently strip their access. They must be moved to a
     * different role first.
     */
    public function delete(Role $role): void
    {
        $userCount = $role->users()->count();

        if ($userCount > 0) {
            throw RoleInUseException::forRole($role->name, $userCount);
        }

        $role->delete();
    }

    /**
     * Drops any permission name not in the current catalog rather than
     * erroring — guards against a stale client payload (e.g. a module
     * removed from the catalog after the browser tab was opened) silently
     * granting a permission that no longer exists.
     *
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    private function validPermissions(array $names): array
    {
        return array_values(array_intersect($names, $this->permissions->allPermissionNames()));
    }
}
