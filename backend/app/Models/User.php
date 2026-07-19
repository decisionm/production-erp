<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Pins spatie/laravel-permission's guard resolution to 'web' always.
     * Without this, mid-request calls like hasAnyPermission() infer the
     * guard from config('auth.defaults.guard') — which the auth:sanctum
     * middleware mutates to 'sanctum' for the request's duration — and
     * since config/auth.php's 'sanctum' guard now also targets this model
     * (needed so Role::users() can resolve it, see the comment there),
     * that inference becomes ambiguous between 'web' and 'sanctum'.
     * Permissions/roles are always seeded under 'web' (see PermissionSeeder),
     * so every check must consistently resolve to 'web', not whichever
     * guard Sanctum happens to be using internally for this request.
     */
    public function guardName(): string
    {
        return 'web';
    }
}
