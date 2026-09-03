<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * ADD one existing role to one existing user on the live instance.
 *
 * WHY THIS EXISTS. Roles are runtime data — created and granted through the
 * Roles screen, never seeded — so until now the only way to put a person in a
 * role was a person clicking it. That is still the right default and this
 * command does not replace it. What it adds is a route for the times the
 * owner asks for the change to be made from the repository, with the same
 * discipline every other live write here carries: dry by default, the whole
 * picture printed, written only after somebody has read it.
 *
 * WHY A ROLE GRANTED TO THE WRONG LOGIN IS THE DANGER, not a mistyped role.
 * A role is a boundary; a login is a person. "Vasanth" is a display name, and
 * display names are not unique — granting procurement write to a second
 * person of the same name is an authorization incident that nothing later
 * detects, because the app will simply work for them. So the match is
 * deliberately UNFORGIVING:
 *
 *   - zero matches            refuse, and say what was searched for
 *   - more than one match     refuse, PRINT every candidate with its email
 *                             and current roles, and ask for the email
 *   - exactly one match       proceed to the dry run
 *
 * The search is over name AND email so a person can be found the way the
 * owner refers to them, but the disambiguation is always an email, because
 * that is the only field the app itself treats as unique.
 *
 * ADDITIVE, ALWAYS. assignRole(), never syncRoles(): a floor supervisor who
 * also keeps the store holds BOTH roles and their permissions stack. Removing
 * a role is a different act with a different blast radius — someone loses
 * access mid-shift — and it is deliberately not available here.
 *
 * IT CREATES NOTHING. Not the user, not the role. A role that does not exist
 * is refused with the list of the ones that do; defining a role is
 * DefineStorekeeperRole's job, and inventing a login is nobody's.
 */
class AssignUserRole extends Command
{
    protected $signature = 'roles:assign
        {user : Email, or part of a name/email, identifying exactly one user}
        {role : The EXISTING role to add, e.g. "Store"}
        {--write : Actually write. Without it nothing is changed.}';

    protected $description = 'Add an existing role to one user (dry run unless --write)';

    public function handle(): int
    {
        $write = (bool) $this->option('write');
        $search = trim((string) $this->argument('user'));
        $roleName = trim((string) $this->argument('role'));

        if ($search === '' || $roleName === '') {
            $this->error('Both a user and a role are required.');

            return self::FAILURE;
        }

        $this->line(sprintf('Assign role — %s', $write ? 'WRITING' : 'dry run, nothing will be changed'));

        // The role must already exist. Guard name 'web' is the only guard this
        // app uses; a role under any other guard would never apply to a
        // session login and finding one here would be the bug, not the fix.
        $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();

        if ($role === null) {
            $this->newLine();
            $this->error(sprintf('There is no role named "%s". Refusing — this command never creates a role.', $roleName));
            $this->line('  Roles that exist:');
            foreach (Role::query()->where('guard_name', 'web')->orderBy('name')->pluck('name') as $name) {
                $this->line(sprintf('    %s', $name));
            }

            return self::FAILURE;
        }

        $matches = $this->findUsers($search);

        if ($matches->isEmpty()) {
            $this->newLine();
            $this->error(sprintf('No user matches "%s". Refusing — this command never creates a login.', $search));

            return self::FAILURE;
        }

        if ($matches->count() > 1) {
            $this->newLine();
            $this->error(sprintf('%d users match "%s". Refusing rather than guessing which person this is.', $matches->count(), $search));
            $this->line('  Re-run with the exact email of the one you mean:');
            foreach ($matches as $candidate) {
                $this->line(sprintf(
                    '    %-38s %s',
                    $candidate->email,
                    $this->describeRoles($candidate),
                ));
            }

            return self::FAILURE;
        }

        $user = $matches->first();
        $before = $user->getRoleNames()->sort()->values()->all();
        $alreadyHeld = in_array($role->name, $before, true);

        $this->newLine();
        $this->line(sprintf('  user:   %s <%s>  (id %d)', $user->name, $user->email, $user->id));
        $this->line(sprintf('  roles:  %s', $before === [] ? '(none)' : implode(', ', $before)));
        $this->newLine();

        if ($alreadyHeld) {
            $this->info(sprintf('Nothing to do — %s already holds "%s".', $user->name, $role->name));

            return self::SUCCESS;
        }

        $this->line(sprintf('  adding: + %s', $role->name));
        $this->line(sprintf('  which grants, on top of what they already hold: %s', $this->describePermissions($role)));
        $this->newLine();
        // Print what is KEPT as well as what is added. The one outcome nobody
        // wants from a role change is a person who quietly lost access
        // mid-shift, so the command says out loud that it takes nothing away.
        $this->line(sprintf(
            '  keeping: %s — this ADDS a role, it never replaces one',
            $before === [] ? '(they held no role)' : implode(', ', $before),
        ));

        if (! $write) {
            $this->newLine();
            $this->warn('Dry run. Re-run with --write once the user above has been confirmed as the right person.');

            return self::SUCCESS;
        }

        $user->assignRole($role);

        $this->newLine();
        $this->info(sprintf(
            'Written. %s <%s> now holds: %s',
            $user->name,
            $user->email,
            $user->fresh()->getRoleNames()->sort()->values()->implode(', '),
        ));
        $this->line('  They must sign out and back in before the new permissions apply to their session.');

        return self::SUCCESS;
    }

    /**
     * Users matching the search, by exact email first.
     *
     * AN EXACT EMAIL IS ALWAYS ONE USER, so it short-circuits the LIKE below.
     * Without that, an email that happens to be a substring of a longer one
     * ("sam@x.com" inside "sam@x.com.au") would come back ambiguous and the
     * disambiguation this command asks for would be impossible to satisfy.
     *
     * @return Collection<int, User>
     */
    private function findUsers(string $search): Collection
    {
        $exact = User::query()->where('email', $search)->get();

        if ($exact->isNotEmpty()) {
            return $exact;
        }

        // LIKE with the wildcards escaped: a search containing % or _ must
        // match those characters literally rather than becoming a pattern that
        // quietly matches everybody.
        $term = '%'.addcslashes($search, '%_\\').'%';

        return User::query()
            ->where(fn ($query) => $query->where('name', 'like', $term)->orWhere('email', 'like', $term))
            ->orderBy('name')
            ->get();
    }

    private function describeRoles(User $user): string
    {
        $roles = $user->getRoleNames()->sort()->values();

        return sprintf('%-28s roles: %s', $user->name, $roles->isEmpty() ? '(none)' : $roles->implode(', '));
    }

    private function describePermissions(Role $role): string
    {
        $permissions = $role->permissions->pluck('name')->sort()->values();

        return $permissions->isEmpty() ? '(no permissions)' : $permissions->implode(', ');
    }
}
