<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * roles:assign — adding one existing role to one existing user on live.
 *
 * The contract under test is the refusal set, not the happy path: this
 * command writes an authorization change to a live factory, and every way it
 * can put a role on the WRONG person has to be closed.
 */
class AssignUserRoleCommandTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name, string $email): User
    {
        return User::factory()->create(['name' => $name, 'email' => $email]);
    }

    private function role(string $name, array $permissions = []): Role
    {
        $role = Role::findOrCreate($name, 'web');

        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $role;
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $vasanth = $this->user('Vasanth', 'vasanth@example.com');
        $vasanth->assignRole($this->role('Shift Floor', ['production.manage']));
        $this->role('Store', ['procurement.manage']);

        $this->artisan('roles:assign', ['user' => 'vasanth@example.com', 'role' => 'Store'])
            ->expectsOutputToContain('dry run, nothing will be changed')
            ->expectsOutputToContain('Dry run.')
            ->assertSuccessful();

        $this->assertSame(['Shift Floor'], $vasanth->fresh()->getRoleNames()->all());
    }

    public function test_writing_adds_the_role_and_keeps_the_one_they_had(): void
    {
        $vasanth = $this->user('Vasanth', 'vasanth@example.com');
        $vasanth->assignRole($this->role('Shift Floor', ['production.manage']));
        $this->role('Store', ['procurement.manage', 'procurement.view']);

        $this->artisan('roles:assign', ['user' => 'vasanth@example.com', 'role' => 'Store', '--write' => true])
            ->assertSuccessful();

        $held = $vasanth->fresh()->getRoleNames()->sort()->values()->all();
        $this->assertSame(['Shift Floor', 'Store'], $held, 'assignRole must ADD — a role change must never cost someone access mid-shift');

        // The point of the grant: the permissions stack.
        $this->assertTrue($vasanth->fresh()->hasPermissionTo('production.manage', 'web'));
        $this->assertTrue($vasanth->fresh()->hasPermissionTo('procurement.manage', 'web'));
    }

    /**
     * The incident this command exists to prevent: two people share a display
     * name and the role lands on the wrong login, where nothing later detects
     * it because the app simply works for them.
     */
    public function test_an_ambiguous_name_is_refused_and_the_candidates_are_printed(): void
    {
        $one = $this->user('Vasanth', 'vasanth.floor@example.com');
        $two = $this->user('Vasanth Kumar', 'vasanth.store@example.com');
        $one->assignRole($this->role('Shift Floor'));
        $this->role('Store');

        $this->artisan('roles:assign', ['user' => 'Vasanth', 'role' => 'Store', '--write' => true])
            ->expectsOutputToContain('2 users match')
            ->expectsOutputToContain('vasanth.floor@example.com')
            ->expectsOutputToContain('vasanth.store@example.com')
            ->assertFailed();

        $this->assertSame(['Shift Floor'], $one->fresh()->getRoleNames()->all());
        $this->assertSame([], $two->fresh()->getRoleNames()->all());
    }

    /** An exact email is one user even when it is a substring of another. */
    public function test_an_exact_email_resolves_even_when_it_prefixes_a_longer_one(): void
    {
        $short = $this->user('Sam', 'sam@example.com');
        $this->user('Sam Two', 'sam@example.com.au');
        $this->role('Store');

        $this->artisan('roles:assign', ['user' => 'sam@example.com', 'role' => 'Store', '--write' => true])
            ->assertSuccessful();

        $this->assertSame(['Store'], $short->fresh()->getRoleNames()->all());
    }

    public function test_an_unknown_user_is_refused_and_no_login_is_created(): void
    {
        $this->role('Store');

        $this->artisan('roles:assign', ['user' => 'nobody@example.com', 'role' => 'Store', '--write' => true])
            ->expectsOutputToContain('No user matches')
            ->assertFailed();

        $this->assertSame(0, User::query()->where('email', 'nobody@example.com')->count());
    }

    public function test_an_unknown_role_is_refused_and_no_role_is_created(): void
    {
        $this->user('Vasanth', 'vasanth@example.com');
        $this->role('Store');

        $this->artisan('roles:assign', ['user' => 'vasanth@example.com', 'role' => 'Stores', '--write' => true])
            ->expectsOutputToContain('There is no role named "Stores"')
            ->expectsOutputToContain('Store')
            ->assertFailed();

        $this->assertSame(0, Role::query()->where('name', 'Stores')->count());
    }

    public function test_a_role_they_already_hold_is_a_no_op(): void
    {
        $vasanth = $this->user('Vasanth', 'vasanth@example.com');
        $vasanth->assignRole($this->role('Store'));

        $this->artisan('roles:assign', ['user' => 'vasanth@example.com', 'role' => 'Store', '--write' => true])
            ->expectsOutputToContain('Nothing to do')
            ->assertSuccessful();

        $this->assertSame(['Store'], $vasanth->fresh()->getRoleNames()->all());
    }

    /** A search containing LIKE wildcards must not match everybody. */
    public function test_a_wildcard_in_the_search_is_matched_literally(): void
    {
        $this->user('Vasanth', 'vasanth@example.com');
        $this->role('Store');

        $this->artisan('roles:assign', ['user' => '%', 'role' => 'Store', '--write' => true])
            ->expectsOutputToContain('No user matches')
            ->assertFailed();
    }
}
