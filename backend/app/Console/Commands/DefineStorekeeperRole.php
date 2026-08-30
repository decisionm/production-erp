<?php

namespace App\Console\Commands;

use App\Modules\Core\Services\PermissionService;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * roles:define-storekeeper — the Store's own role, DRY BY DEFAULT.
 *
 * WHY A COMMAND AND NOT A SEEDER. PermissionSeeder runs on every deploy, and
 * its own note says it must only ever ADD its one permission and never
 * rewrite what an administrator granted through the Roles screen. Creating a
 * role and fixing its permission set is a live master-data change, and
 * AGENTS.md is explicit that those go through a manual workflow, dry-run
 * first, written only after a person has read the dry run. So this is dry
 * unless asked, prints the exact difference it would write, and is invoked by
 * hand — never as a side effect of shipping code.
 *
 * WHAT THE STORE ACTUALLY NEEDS, and why it is this short. Every non-GET
 * route under `module:inventory` requires `inventory.manage`
 * (EnsureModulePermission), and that one permission is what carries the whole
 * Store job: issuing material against a request, recording a return, scanning
 * a bag, holding and releasing stock for a customer line, and sending a short
 * line to the floor. `inventory.view` carries the reading half — stock, the
 * ledger, lots, bags, barcodes.
 *
 * ADMINISTRATOR STAYS ADMINISTRATIVE BY WHAT THIS ROLE DOES NOT GET, not by a
 * new gate. An earlier draft of this work proposed splitting item and
 * warehouse master writes out behind their own module, on the machine-master
 * precedent. That was WRONG here, and the recorded decision says so:
 * DEC-20260817-002 §3 settles that hard delete is Super Admin / Owner level
 * only while "other permitted configuration users may create, edit, activate
 * and deactivate according to RBAC" — and HardDeleteTierTest pins it with a
 * fixture role named, in as many words, "Store Keeper" holding nothing but
 * `inventory.manage`, asserting that its archive succeeds while its delete is
 * refused. The boundary the factory already chose is the DELETE tier, and it
 * is enforced by `configuration-delete.manage`, which this role is
 * deliberately not given. HardDeleteAuthority's own docblock makes the same
 * argument against a second middleware guard.
 *
 * SO THE SEPARATION IS: the Storekeeper may run the store and correct the
 * masters they work with; they may not destroy a master, administer users or
 * roles, or read a purchase rate. That last one is FC-06 and is why no
 * `finance.*` permission appears below — a rate is Owner/Accounts only, and a
 * storekeeper who could read one would be a widening of FC-06 by accident.
 *
 * TWO CAPABILITIES THE OWNER ASKED FOR ARE NOT HERE, and their absence is the
 * honest answer rather than an oversight:
 *
 *   · "Receiving approved finished goods" — there is no store-acceptance step
 *     in the finished-goods chain today (complete -> quality-check ->
 *     pm-approve -> accountant-approve). Adding one is a new stage in how the
 *     factory works, which is the owner's to design, not a role's to imply.
 *   · "Final dispatch approval" — deliveries sit under the sales module, so
 *     granting it means `sales.manage`, which also unlocks sales orders,
 *     customers and invoices. That is a wider grant than the words ask for.
 *
 * Both are recorded in PENDING-OWNER-QUESTIONS rather than guessed at here.
 *
 * IT ASSIGNS NOBODY. Which people are storekeepers is a fact about the
 * factory that this tree cannot know, and a role granted to the wrong login
 * is an authorization incident. The command creates the role and its
 * permissions; a person adds the users on the Roles screen afterwards.
 */
class DefineStorekeeperRole extends Command
{
    protected $signature = 'roles:define-storekeeper {--write : Actually write. Without it nothing is changed.}';

    protected $description = 'Dry by default: shows (or with --write, applies) the Storekeeper role and its permissions. Assigns no users.';

    public const string ROLE = 'Storekeeper';

    /**
     * Exactly what the Store job needs, and nothing that administers the
     * system or reads a rate.
     *
     * @var list<string>
     */
    public const PERMISSIONS = [
        // The reading half: stock, the ledger, lots, bags, barcodes, batches,
        // serials, the store issue queue, fulfilment and its planning.
        'inventory.view',
        // The doing half: issue against a request, record a return, scan a
        // bag, hold and release stock for a customer line, send a short line
        // to the floor. Every non-GET under module:inventory needs this one.
        'inventory.manage',
        // Read-only sight of what the floor is making and has asked for. The
        // material-request routes are OR-gated (production OR inventory) so
        // this is not needed to work the queue — it is here so the queue can
        // be read in context rather than as a list of bare numbers.
        'production.view',
        // Read-only sight of an incoming inspection's outcome, so the store
        // can see whether arrived material has been released. QA still owns
        // quality.manage; the store never inspects.
        'quality.view',
    ];

    public function handle(PermissionService $catalog): int
    {
        $write = (bool) $this->option('write');

        // The catalogue is the authority: RoleService intersects every grant
        // against it, so a permission missing from PermissionService is
        // stripped from the role on the next save through the Roles screen
        // and the guard then 403s everyone holding it. A name this command
        // cannot find in the catalogue is a bug in this command, and it
        // refuses rather than creating a role that will quietly decay.
        $known = collect($catalog->allPermissionNames());
        $unknown = array_values(array_diff(self::PERMISSIONS, $known->all()));

        if ($unknown !== []) {
            $this->error(sprintf(
                'These permissions are not in PermissionService: %s. Refusing — a grant outside the catalogue is'
                .' stripped on the next save through the Roles screen and then 403s everyone holding it.',
                implode(', ', $unknown),
            ));

            return self::FAILURE;
        }

        $this->line(sprintf('Storekeeper role — %s', $write ? 'WRITING' : 'dry run, nothing will be changed'));

        $role = Role::query()->where('name', self::ROLE)->where('guard_name', 'web')->first();
        $existing = $role === null ? [] : $role->permissions->pluck('name')->sort()->values()->all();
        $wanted = collect(self::PERMISSIONS)->sort()->values()->all();

        $this->newLine();
        $this->line($role === null
            ? sprintf('  role "%s": does not exist — it would be created', self::ROLE)
            : sprintf('  role "%s": exists, held by %d user(s)', self::ROLE, $role->users()->count()));

        $adding = array_values(array_diff($wanted, $existing));
        $removing = array_values(array_diff($existing, $wanted));

        $this->newLine();
        foreach ($wanted as $name) {
            $this->line(sprintf('    %s %s', in_array($name, $adding, true) ? '+' : ' ', $name));
        }
        // line(), not warn(): the additions above are plain lines, and a
        // styled warning here both breaks the visual list and wraps at the
        // terminal width, which chops the explanation in half on a narrow one.
        foreach ($removing as $name) {
            $this->line(sprintf('    - %s (currently held, would be removed)', $name));
        }

        // Say what it is NOT giving, out loud. The whole separation between
        // this role and Administrator is the absence of these, and an absence
        // is invisible unless it is printed.
        $this->newLine();
        $this->line('  deliberately NOT granted, and why:');
        foreach ([
            'configuration-delete.manage' => 'hard delete is Super Admin / Owner only (DEC-20260817-002 §3)',
            'users.manage' => 'administering logins is not a Store act',
            'roles.manage' => 'a role that can widen itself is not a boundary',
            'finance.view' => 'purchase rates are Owner/Accounts only (FC-06)',
        ] as $name => $why) {
            $this->line(sprintf('      %-30s %s', $name, $why));
        }

        if ($adding === [] && $removing === [] && $role !== null) {
            $this->newLine();
            $this->info('Nothing to do — the role already holds exactly this set.');

            return self::SUCCESS;
        }

        if (! $write) {
            $this->newLine();
            $this->warn('Dry run. Re-run with --write once the set above has been read and agreed.');
            $this->line('  No user is assigned either way — who keeps the store is added on the Roles screen by a person.');

            return self::SUCCESS;
        }

        $role = Role::findOrCreate(self::ROLE, 'web');
        $role->syncPermissions(
            collect(self::PERMISSIONS)->map(fn (string $name) => Permission::findOrCreate($name, 'web'))
        );

        $this->newLine();
        $this->info(sprintf('Written. "%s" now holds %d permission(s), and no user was assigned to it.', self::ROLE, count(self::PERMISSIONS)));

        return self::SUCCESS;
    }
}
