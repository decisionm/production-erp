<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Core\Services\PermissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * roles:define-store — the Store's own role, DRY BY DEFAULT.
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
 * THE ROLE IS CALLED "Store". It was first defined as "Storekeeper"
 * (Q78, 30-Aug-2026); the owner named it "Store" on 02-Sep-2026 when widening
 * it to procurement. A live instance that already holds a "Storekeeper" role
 * and no "Store" role gets that one RENAMED in place, so whoever holds it
 * keeps it — two roles for one job is exactly the confusion a rename avoids.
 * If both names exist, "Store" is the one defined here and "Storekeeper" is
 * left untouched and reported, because a second role a person created on the
 * Roles screen is theirs to retire, not this command's.
 *
 * WHAT THE STORE ACTUALLY NEEDS, and why it is this short. Every non-GET
 * route under `module:inventory` requires `inventory.manage`
 * (EnsureModulePermission), and that one permission carries the whole Store
 * job: issuing material against a request, recording a return, scanning a
 * bag, holding and releasing stock for a customer line, and sending a short
 * line to the floor. `inventory.view` carries the reading half — stock, the
 * ledger, lots, bags, barcodes.
 *
 * PROCUREMENT IS GRANTED IN FULL, on the owner's instruction of 02-Sep-2026.
 * The procurement module has ONE write permission: `procurement.manage`
 * gates vendors, purchase requisitions and their approval, purchase orders
 * (raise, send, amend, close, cancel) and goods receipts alike, and there is
 * no narrower grant that would let the Store record a goods receipt without
 * the rest. The owner was told this and chose full procurement. FC-06 is
 * still intact: PurchaseOrderLineResource and GoodsReceiptNoteLineResource
 * hide every rate from a reader without `finance.view` / `finance.manage`,
 * so a Store login sees quantities and never a price. Supplier bills and the
 * vendor-item rate sit under `module:finance` and stay out of reach.
 *
 * NO HRMS. The owner said so explicitly on 02-Sep-2026: the Store gets no
 * HRMS permission, and there is no self-service scope in HRMS today that
 * would let a login see only its own record anyway.
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
 * SO THE SEPARATION IS: the Store may run the store, buy and receive, and
 * correct the masters they work with; they may not destroy a master,
 * administer users or roles, or read a purchase rate.
 *
 * ONE CAPABILITY THE OWNER ONCE ASKED FOR IS STILL NOT HERE: "receiving
 * approved finished goods". There is no store-acceptance step in the
 * finished-goods chain today (complete -> quality-check -> pm-approve ->
 * accountant-approve). Adding one is a new stage in how the factory works,
 * which is the owner's to design, not a role's to imply. It stays open in
 * PENDING-OWNER-QUESTIONS (Q78). Dispatch, the other half of that question,
 * was answered by DEC-20260901-001 and rides on `inventory.manage`.
 *
 * ASSIGNMENT IS EXPLICIT OR NOT AT ALL. Which people keep the store is a fact
 * about the factory that this tree cannot know, and a role granted to the
 * wrong login is an authorization incident. So the command assigns nobody
 * unless `--assign` names ONE existing login, by its exact email or exact
 * name; zero matches, several matches and an inactive login are all refused,
 * and a login is never created here — that is the Users screen's job. The
 * dry run prints exactly who would be assigned so the person reads the name
 * before the write.
 */
class DefineStoreRole extends Command
{
    protected $signature = 'roles:define-store
        {--write : Actually write. Without it nothing is changed.}
        {--assign= : Email or name of ONE existing login to give the role to. Refused unless it matches exactly one active user.}';

    protected $description = 'Dry by default: shows (or with --write, applies) the Store role and its permissions, and optionally assigns one named login.';

    public const string ROLE = 'Store';

    /** The name this role carried before 02-Sep-2026; renamed in place if found. */
    public const string FORMER_ROLE = 'Storekeeper';

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
        // to the floor, raise a delivery (DEC-20260901-001). Every non-GET
        // under module:inventory needs this one.
        'inventory.manage',
        // Procurement, read and write: vendors, requisitions, purchase orders
        // and goods receipts. One permission gates all of them; rates stay
        // hidden without finance.* (FC-06).
        'procurement.view',
        'procurement.manage',
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
        $assign = trim((string) $this->option('assign'));

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

        // Resolve the login BEFORE printing anything else, so a bad --assign
        // refuses up front instead of after a wall of permissions.
        $user = null;
        if ($assign !== '') {
            $user = $this->resolveLogin($assign);
            if ($user === null) {
                return self::FAILURE;
            }
        }

        $this->line(sprintf('Store role — %s', $write ? 'WRITING' : 'dry run, nothing will be changed'));

        $role = Role::query()->where('name', self::ROLE)->where('guard_name', 'web')->first();
        $former = Role::query()->where('name', self::FORMER_ROLE)->where('guard_name', 'web')->first();
        $renaming = $role === null && $former !== null;

        // The set the role holds today, whichever name it is under.
        $current = $role ?? ($renaming ? $former : null);
        $existing = $current === null ? [] : $current->permissions->pluck('name')->sort()->values()->all();
        $wanted = collect(self::PERMISSIONS)->sort()->values()->all();

        $this->newLine();
        if ($renaming) {
            $this->line(sprintf(
                '  role "%s": does not exist — "%s" does, held by %d user(s), and would be RENAMED to "%s" keeping its holders',
                self::ROLE, self::FORMER_ROLE, $former->users()->count(), self::ROLE,
            ));
        } elseif ($role === null) {
            $this->line(sprintf('  role "%s": does not exist — it would be created', self::ROLE));
        } else {
            $this->line(sprintf('  role "%s": exists, held by %d user(s)', self::ROLE, $role->users()->count()));
        }
        if ($role !== null && $former !== null) {
            $this->line(sprintf(
                '  note: a separate "%s" role also exists, held by %d user(s). It is left untouched — retire it on the Roles screen once its holders are on "%s".',
                self::FORMER_ROLE, $former->users()->count(), self::ROLE,
            ));
        }

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
            'hrms.view' => 'the owner said no HRMS for the Store (02-Sep-2026)',
        ] as $name => $why) {
            $this->line(sprintf('      %-30s %s', $name, $why));
        }

        $alreadyHolds = $user !== null && $current !== null && $user->hasRole($current);
        if ($user !== null) {
            $this->newLine();
            $this->line(sprintf(
                '  assign: %s%s',
                $this->describe($user),
                $alreadyHolds ? ' — already holds this role' : ($write ? ' — assigning' : ' — would be assigned'),
            ));
        }

        $roleUnchanged = $adding === [] && $removing === [] && $role !== null;
        if ($roleUnchanged && ($user === null || $alreadyHolds)) {
            $this->newLine();
            $this->info('Nothing to do — the role already holds exactly this set'.($user === null ? '.' : ', and the login already holds the role.'));

            return self::SUCCESS;
        }

        if (! $write) {
            $this->newLine();
            $this->warn('Dry run. Re-run with --write once the set above has been read and agreed.');
            $this->line($user === null
                ? '  No user is assigned either way — name one with --assign, or add people on the Roles screen.'
                : '  The login named above is not assigned by a dry run.');

            return self::SUCCESS;
        }

        if ($renaming) {
            $former->name = self::ROLE;
            $former->save();
            $role = $former;
        } else {
            $role = Role::findOrCreate(self::ROLE, 'web');
        }

        $role->syncPermissions(
            collect(self::PERMISSIONS)->map(fn (string $name) => Permission::findOrCreate($name, 'web'))
        );

        if ($user !== null && ! $alreadyHolds) {
            $user->assignRole($role);
        }

        $this->newLine();
        $this->info(sprintf(
            'Written. "%s" now holds %d permission(s)%s; %s.',
            self::ROLE,
            count(self::PERMISSIONS),
            $renaming ? sprintf(' (renamed from "%s")', self::FORMER_ROLE) : '',
            $user === null
                ? 'no user was assigned'
                : ($alreadyHolds ? sprintf('%s already held it', $user->name) : sprintf('%s now holds it', $user->name)),
        ));

        return self::SUCCESS;
    }

    /**
     * Exactly one ACTIVE login by exact email or exact name, or nothing.
     * Case-insensitive on both, because "vasanth@" and "Vasanth@" are the
     * same mailbox and a typed name is not a case test. Substring matching
     * is deliberately absent: "Vasan" must not silently pick "Vasanthi".
     */
    private function resolveLogin(string $needle): ?User
    {
        $lower = mb_strtolower($needle);

        /** @var Collection<int, User> $matches */
        $matches = User::query()
            ->whereRaw('lower(email) = ?', [$lower])
            ->orWhereRaw('lower(name) = ?', [$lower])
            ->orderBy('id')
            ->get();

        if ($matches->isEmpty()) {
            $this->error(sprintf(
                '--assign refused: no login has the exact email or exact name "%s". A login is never created here — add it on the Users screen first.',
                $needle,
            ));

            return null;
        }

        if ($matches->count() > 1) {
            $this->error(sprintf('--assign refused: "%s" matches %d logins, and the role must go to exactly one:', $needle, $matches->count()));
            foreach ($matches as $candidate) {
                $this->line('    '.$this->describe($candidate));
            }
            $this->line('  Re-run with the email of the one you mean.');

            return null;
        }

        $user = $matches->first();
        if (! $user->is_active) {
            $this->error(sprintf(
                '--assign refused: %s is INACTIVE. Granting a role to a disabled login is almost always a mistake — activate it on the Users screen first if it is meant to work.',
                $this->describe($user),
            ));

            return null;
        }

        return $user;
    }

    private function describe(User $user): string
    {
        $roles = $user->getRoleNames();

        return sprintf(
            '#%d %s <%s>%s, roles today: %s',
            $user->id,
            $user->name,
            $user->email,
            $user->is_active ? '' : ' [inactive]',
            $roles->isEmpty() ? '(none)' : $roles->implode(', '),
        );
    }
}
