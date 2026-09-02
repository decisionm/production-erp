<?php

namespace App\Modules\Core\Services;

use App\Models\User;
use App\Modules\Core\Exceptions\SelfDeactivationException;
use App\Modules\Core\Http\Requests\ListUsersRequest;
use App\Support\Lists\ListSort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class UserService
{
    /** Name order when no sort is asked for — what this list always was. */
    public function paginate(int $perPage = 20, ?string $sort = null): LengthAwarePaginator
    {
        $query = User::query()
            ->where('is_system', false)
            ->with('roles.permissions');
        ListSort::apply($query, $sort, ListUsersRequest::SORTABLE, 'name');

        return $query->paginate($perPage);
    }

    /**
     * @param  array{name: string, email: string, password: string, roles?: array<int, int>}  $data
     */
    public function create(array $data): User
    {
        return DB::transaction(function () use ($data) {
            // Explicit here rather than relying on the DB column default: Eloquent's
            // create() doesn't re-fetch DB-applied defaults into the returned model.
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'is_active' => true,
            ]);

            if (! empty($data['roles'])) {
                $user->syncRoles($data['roles']);
            }

            return $user->load('roles.permissions');
        });
    }

    /**
     * @param  array{name?: string, email?: string, is_active?: bool, roles?: array<int, int>}  $data
     */
    public function update(User $user, array $data, ?int $actingUserId = null): User
    {
        if (($data['is_active'] ?? true) === false && $user->id === $actingUserId) {
            throw SelfDeactivationException::make();
        }

        return DB::transaction(function () use ($user, $data) {
            $user->update(array_intersect_key($data, array_flip(['name', 'email', 'is_active'])));

            if (array_key_exists('roles', $data)) {
                $user->syncRoles($data['roles']);
            }

            return $user->load('roles.permissions');
        });
    }

    /**
     * The `hashed` cast on User::password (see the model) hashes this on
     * assignment — no need to call Hash::make() here, same as create().
     */
    public function resetPassword(User $user, string $password): void
    {
        $user->update(['password' => $password]);
    }
}
