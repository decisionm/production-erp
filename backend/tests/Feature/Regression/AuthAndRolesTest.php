<?php

namespace Tests\Feature\Regression;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Feature\Regression\Support\RegressionFixtures;
use Tests\TestCase;

/**
 * Phase 7 regression smoke (P7-05, WS-E) — the front door and the keys.
 *
 * Nothing in the suite exercised login before this file (only
 * ChangePasswordTest touched auth): the SPA's session login, the wrong
 * password, the deactivated account, `me`, logout — and the Core module's
 * users / roles / permissions CRUD behind their `module:users` and
 * `module:roles` gates. Every request here goes through the API the SPA
 * calls, as the SPA calls it (a stateful same-origin request carries a
 * Referer on the stateful domain list, which is what makes Sanctum treat
 * it as a session request rather than a token one).
 */
class AuthAndRolesTest extends TestCase
{
    use RefreshDatabase, RegressionFixtures;

    private const PASSWORD = 'Regression@2026';

    /**
     * The SPA's own request: same-origin (a Referer on the stateful domain
     * list), so Sanctum keeps a session for it. Each call also drops the
     * guard instances the previous request resolved — in production every
     * request is a fresh process and resolves its user from the session
     * again; inside one PHPUnit process the resolved guards (and the user
     * they cached) would otherwise leak from request to request and hide a
     * logout or a deactivation. The session store itself is kept, as a
     * browser would keep its cookie.
     */
    private function spa(): static
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        return $this->withHeader('Referer', 'http://localhost/login');
    }

    private function account(bool $active = true): User
    {
        return User::factory()->create([
            'name' => 'Regression Login',
            'password' => Hash::make(self::PASSWORD),
            'is_active' => $active,
        ]);
    }

    private function login(User $user, string $password = self::PASSWORD): TestResponse
    {
        return $this->spa()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $password]);
    }

    // ---- login · me · logout ---------------------------------------------

    public function test_a_session_login_answers_the_user_and_me_then_reads_the_same_session(): void
    {
        $user = $this->account();

        $this->login($user)
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonStructure(['data' => ['id', 'name', 'email', 'is_active', 'roles', 'permissions']]);

        $this->spa()->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_a_wrong_password_is_a_401_and_leaves_no_session_behind(): void
    {
        $user = $this->account();

        $this->login($user, 'not-the-password')->assertStatus(401);

        $this->spa()->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_login_validates_its_two_fields(): void
    {
        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_a_deactivated_account_cannot_log_in_even_with_the_right_password(): void
    {
        $user = $this->account(active: false);

        $this->login($user)->assertStatus(401);

        $this->spa()->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_deactivating_a_logged_in_user_ends_their_access_on_the_next_request(): void
    {
        $user = $this->account();
        $this->login($user)->assertOk();
        $this->spa()->getJson('/api/v1/auth/me')->assertOk();

        $user->update(['is_active' => false]);

        $this->spa()->getJson('/api/v1/auth/me')->assertStatus(403);
    }

    public function test_logout_ends_the_session_and_me_is_unauthenticated_afterwards(): void
    {
        $user = $this->account();
        $this->login($user)->assertOk();

        $this->spa()->postJson('/api/v1/auth/logout')->assertOk()->assertJsonPath('message', 'Logged out.');

        $this->spa()->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_me_and_logout_are_refused_without_a_session(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->postJson('/api/v1/auth/logout')->assertStatus(401);
    }

    // ---- roles + permissions CRUD (module:roles) --------------------------

    public function test_an_administrator_manages_roles_and_the_permission_catalogue_lists_every_module(): void
    {
        $this->actAsAdministrator();

        $catalogue = $this->getJson('/api/v1/permissions')->assertOk()->json('data');
        $modules = array_column($catalogue, 'module');
        foreach (['users', 'roles', 'inventory', 'procurement', 'production', 'sales', 'finance', 'quality', 'compliance', 'hrms', 'payroll', 'maintenance', 'tally-sync', 'crm', 'machine-master', 'carton-trace'] as $module) {
            $this->assertContains($module, $modules, "the permission catalogue no longer lists {$module}");
        }

        $roleId = $this->postJson('/api/v1/roles', [
            'name' => 'Regression Store Keeper',
            'permissions' => ['inventory.view', 'inventory.manage', 'not-a-real.permission'],
        ])->assertStatus(422)->json();
        // An unknown permission name is refused by validation, not silently dropped.
        $this->assertArrayHasKey('errors', $roleId);

        $role = $this->postJson('/api/v1/roles', [
            'name' => 'Regression Store Keeper',
            'permissions' => ['inventory.view', 'inventory.manage'],
        ])->assertCreated()->json('data');
        $this->assertSame(['inventory.view', 'inventory.manage'], $role['permissions']);
        $this->assertSame(0, $role['users_count']);

        $this->getJson('/api/v1/roles')->assertOk()
            ->assertJsonFragment(['name' => 'Regression Store Keeper'])
            ->assertJsonFragment(['name' => 'Administrator']);

        $updated = $this->putJson("/api/v1/roles/{$role['id']}", ['permissions' => ['inventory.view']])
            ->assertOk()->json('data');
        $this->assertSame(['inventory.view'], $updated['permissions']);

        $this->deleteJson("/api/v1/roles/{$role['id']}")->assertNoContent();
        $this->assertNull(Role::find($role['id']));
    }

    public function test_a_role_still_assigned_to_a_user_cannot_be_deleted(): void
    {
        $this->actAsAdministrator();
        $role = Role::findOrCreate('Regression Held Role', 'web');
        $this->userHolding([])->assignRole($role);

        $this->deleteJson("/api/v1/roles/{$role->id}")->assertStatus(422);
        $this->assertNotNull(Role::find($role->id));
    }

    // ---- users CRUD (module:users) ----------------------------------------

    public function test_an_administrator_creates_updates_and_resets_a_user_and_the_new_user_can_log_in(): void
    {
        $this->actAsAdministrator();
        $role = Role::create(['name' => 'Regression Viewer', 'guard_name' => 'web']);
        $role->givePermissionTo('sales.view');

        $created = $this->postJson('/api/v1/users', [
            'name' => 'Regression New User',
            'email' => 'regression.new@example.test',
            'password' => self::PASSWORD,
            'roles' => [$role->id],
        ])->assertCreated()->json('data');

        $this->assertTrue($created['is_active']);
        $this->assertSame(['Regression Viewer'], array_column($created['roles'], 'name'));
        $this->assertSame(['sales.view'], $created['permissions']);

        $this->getJson('/api/v1/users')->assertOk()
            ->assertJsonStructure(['data', 'meta'])
            ->assertJsonFragment(['email' => 'regression.new@example.test']);

        $this->putJson("/api/v1/users/{$created['id']}", ['name' => 'Regression Renamed', 'roles' => []])
            ->assertOk()
            ->assertJsonPath('data.name', 'Regression Renamed')
            ->assertJsonPath('data.roles', [])
            ->assertJsonPath('data.permissions', []);

        $this->postJson("/api/v1/users/{$created['id']}/reset-password", ['password' => 'Regression@2027'])
            ->assertOk();

        // The new account logs in with the reset password — spa() drops the
        // administrator's acting-as guard first, so this is a fresh login.
        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'regression.new@example.test', 'password' => 'Regression@2027'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Regression Renamed');
    }

    public function test_an_administrator_cannot_deactivate_their_own_account(): void
    {
        $admin = $this->actAsAdministrator();

        $this->putJson("/api/v1/users/{$admin->id}", ['is_active' => false])->assertStatus(422);
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_user_store_validates_email_uniqueness_and_password_length(): void
    {
        $this->actAsAdministrator();
        $existing = $this->userHolding([]);

        $this->postJson('/api/v1/users', ['name' => 'Dup', 'email' => $existing->email, 'password' => 'short'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    // ---- the gates ---------------------------------------------------------

    public function test_users_view_reads_the_list_but_cannot_write_and_no_permission_cannot_read(): void
    {
        $viewer = $this->userHolding(['users.view'], 'Regression Users Viewer');
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/users')->assertOk();
        $this->postJson('/api/v1/users', ['name' => 'X', 'email' => 'x@example.test', 'password' => self::PASSWORD])->assertStatus(403);
        $this->putJson("/api/v1/users/{$viewer->id}", ['name' => 'Renamed'])->assertStatus(403);
        $this->postJson("/api/v1/users/{$viewer->id}/reset-password", ['password' => self::PASSWORD])->assertStatus(403);
        // users.view says nothing about roles.
        $this->getJson('/api/v1/roles')->assertStatus(403);
        $this->getJson('/api/v1/permissions')->assertStatus(403);

        Sanctum::actingAs($this->userHolding([], 'Regression Nobody'));
        $this->getJson('/api/v1/users')->assertStatus(403);
        $this->getJson('/api/v1/roles')->assertStatus(403);
        $this->getJson('/api/v1/permissions')->assertStatus(403);
    }

    public function test_roles_view_reads_roles_and_permissions_but_cannot_write(): void
    {
        Sanctum::actingAs($this->userHolding(['roles.view'], 'Regression Roles Viewer'));

        $this->getJson('/api/v1/roles')->assertOk();
        $this->getJson('/api/v1/permissions')->assertOk();
        $this->postJson('/api/v1/roles', ['name' => 'Regression Forbidden Role'])->assertStatus(403);
        $this->assertNull(Role::where('name', 'Regression Forbidden Role')->first());
        // roles.view says nothing about users.
        $this->getJson('/api/v1/users')->assertStatus(403);
    }

    public function test_every_other_permission_still_does_not_open_users_or_roles(): void
    {
        Sanctum::actingAs($this->userHoldingEverythingExcept(['users.view', 'users.manage', 'roles.view', 'roles.manage']));

        $this->getJson('/api/v1/users')->assertStatus(403);
        $this->getJson('/api/v1/roles')->assertStatus(403);
        $this->getJson('/api/v1/permissions')->assertStatus(403);
    }
}
