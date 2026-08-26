<?php

namespace App\Console\Commands;

use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Services\CustomerService;
use App\Modules\TallySync\Models\Ledger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Create Sales customers from the Tally ledgers already pulled into this
 * database — no Tally contact, no invented party.
 *
 * WHY THIS IS AN EXPLICIT RUN AND NOT A SYNC. The customer list is the
 * factory's own commercial record. A background job that quietly invented
 * hundreds of customers, or silently changed one, is exactly the kind of
 * thing nobody notices until an invoice goes to the wrong party. So this is
 * a command a person runs, reads, and only then re-runs with --write —
 * matching the house rule in AGENTS.md for every live master-data change.
 *
 * SELECTION IS AN ALLOW-LIST OF GROUP NAMES, DELIBERATELY. It would be
 * easy — and wrong — to walk the group tree and take everything under
 * "Sundry Debtors". The owner confirmed the BUSINESS meaning of the regional
 * groups (they are customers); he did not confirm Tally's group TREE, and
 * whether those groups technically nest under Sundry Debtors is unverified.
 * Inferring membership from a parent link would turn an unverified structural
 * assumption into hundreds of customer rows. So the caller names the groups,
 * and a dry run with no --groups prints every group in the data with its
 * count so the naming is an informed act.
 *
 * NOTE ON AUTHORITY: the record that says these regional groups are all
 * customers (DEC-20260809-002) is owner-confirmed and in force on main, since
 * PR #155 merged on 16-Aug. Nothing here hardcodes a group list even so — the
 * caller still names them — so this command reads the record as authority
 * without encoding its list.
 *
 * WHAT IT NEVER DOES: it never updates or deletes an existing customer, and
 * it never invents a field. A Tally ledger carries a name and a group — no
 * GSTIN, no email, no phone, no address — so those are left NULL rather than
 * fabricated (AGENTS.md: never invent a factory value).
 *
 * THE LEDGER LINK, AND WHY IT IS WRITTEN HERE AND NOWHERE ELSE. A created
 * customer now records WHICH ledger it came from — `tally_ledger_guid` and
 * `tally_ledger_name`, mirroring the ledger row. Those two columns are absent
 * from Customer's #[Fillable] on purpose: this command is their only writer,
 * so no request, no customer form and no future mass-assign can quietly point
 * a customer at a different Tally ledger. The code ("TL-{id}") was never good
 * enough for that job — it is free text a person may rename.
 *
 * --link-existing IS THE BACKFILL, and it is a SEPARATE run. Customers created
 * before those columns existed carry the code and nothing else. The backfill
 * reads the ledger id back out of the code, finds that ledger, and writes the
 * link — dry run unless --write, like everything else here, and it NEVER
 * overwrites a link that is already set: a row somebody or something else
 * already linked is reported and left exactly as it is. It creates nothing and
 * deletes nothing.
 */
class ImportCustomersFromLedgers extends Command
{
    /**
     * The customer code minted per ledger. Deterministic and traceable: the
     * same ledger always yields the same code, so a re-run is a no-op rather
     * than a duplicate, and any row can be traced back to the ledger it came
     * from. `customers.code` is unique and max 32 — this is well inside it.
     */
    private const CODE_PREFIX = 'TL-';

    /**
     * The census label for ledgers with no group. It is NOT a group name, and
     * must never be selectable: passing it matched nothing while passing the
     * unknown-group check, so the command printed IMPORTED having imported
     * nothing — the exact silent no-op the check exists to prevent.
     */
    private const NO_GROUP = '(no group)';

    protected $signature = 'sales:import-customers-from-ledgers
        {--groups=* : Tally group name to import. Repeatable, and also accepts a comma-separated list. Required to write. Omit for a census of every group in the data.}
        {--link-existing : Import nothing; backfill the Tally ledger link on TL- customers that have none. Dry run unless --write.}
        {--write : Actually write (default is a dry run)}';

    protected $description = 'Create Sales customers from pulled Tally ledgers, selected by an explicit allow-list of group names';

    public function handle(CustomerService $customers): int
    {
        // THE BACKFILL BRANCHES FIRST. It imports nothing, so the group census
        // below is noise for it and the "no --groups given" return would make
        // the mode unreachable — and the empty-ledgers refusal is an IMPORT
        // rule, not a backfill one: with no ledgers pulled, every TL- customer
        // simply lands in the backfill's own "ledger not in this database"
        // list, named one by one, which is the more useful answer.
        //
        // Refused together with --groups rather than silently doing one of the
        // two: an operator who asked for both must not be left guessing which
        // one happened.
        if ($this->option('link-existing')) {
            if (! empty(array_filter((array) $this->option('groups')))) {
                $this->error('--link-existing imports nothing, so --groups has no meaning with it.');
                $this->line('  Run the import first (--groups=... --write), then the backfill (--link-existing --write).');

                return self::FAILURE;
            }

            return $this->linkExisting();
        }

        // The census first, always — a person choosing groups must be able to
        // see every group that exists and how big it is, including the ones
        // they are about to leave out.
        $census = Ledger::query()
            // Single-quoted: a double-quoted literal is only a string while
            // MySQL is not in ANSI_QUOTES mode, where it would instead be read
            // as an identifier and the query would fail.
            ->selectRaw('COALESCE(tally_group_name, ?) as grp, COUNT(*) as n', [self::NO_GROUP])
            ->groupBy('grp')
            ->orderByDesc('n')
            ->pluck('n', 'grp');

        if ($census->isEmpty()) {
            $this->error('No ledgers in this database. Nothing can be imported.');
            $this->line('  `ledgers` is populated only by the Tally agent posting to /api/v1/tally-sync/masters —');
            $this->line('  no seeder or fixture writes it. Run a masters sync from the factory desktop first.');

            return self::FAILURE;
        }

        $this->info('Tally ledger groups in this database:');
        $this->table(
            ['group', 'ledgers'],
            $census->map(fn (int $n, string $g) => [$g, $n])->values()->all(),
        );
        $this->line('  total ledgers: '.$census->sum());
        $this->newLine();

        // Repeatable option, and each value may itself be a comma-separated
        // list. A value that matches a census group EXACTLY is taken whole —
        // otherwise a Tally group whose own name contains a comma
        // ("Debtors, Chennai") could never be selected by any input, and the
        // refusal below would name two groups the operator can see in the
        // table above.
        $groups = collect((array) $this->option('groups'))
            ->flatMap(function (string $value) use ($census) {
                $value = trim($value);

                return $census->has($value) ? [$value] : explode(',', $value);
            })
            ->map(fn (string $g) => trim($g))
            ->filter()
            ->unique()
            ->values();

        if ($groups->isEmpty()) {
            $this->warn('No --groups given, so nothing was selected and nothing was written.');
            $this->line('  Choose from the groups above and re-run, e.g.:');
            $this->line('    --groups="Sundry Debtors,Chennai,Kerala"');
            $this->line('  Groups are matched by name exactly. Membership is NEVER inferred from a');
            $this->line('  parent group — see this command\'s docblock for why.');

            return self::SUCCESS;
        }

        // The census sentinel is a label, not a group. Selecting it would pass
        // the unknown-group check and then match nothing, reporting IMPORTED
        // over a silent no-op.
        if ($groups->contains(self::NO_GROUP)) {
            $this->error(sprintf('"%s" is a label for ledgers that have no group, not a group you can import.', self::NO_GROUP));
            $this->line('  Those ledgers cannot be attributed to anything, so importing them would create');
            $this->line('  customers on no authority at all. Give them a group in Tally first.');

            return self::FAILURE;
        }

        $unknown = $groups->reject(fn (string $g) => $census->has($g));
        if ($unknown->isNotEmpty()) {
            $this->error('These groups are not in the data, so they would silently import nothing:');
            foreach ($unknown as $g) {
                $this->line('  · '.$g);
            }
            $this->line('  Fix the spelling against the census above and re-run. Refusing rather than');
            $this->line('  importing a subset, because a typo that quietly halves the customer list is');
            $this->line('  worse than a stopped command.');

            return self::FAILURE;
        }

        $ledgers = Ledger::query()
            ->whereIn('tally_group_name', $groups)
            ->orderBy('name')
            // tally_guid rides along: it is half the link written on create,
            // and leaving it out of the select would silently store NULL.
            ->get(['id', 'tally_guid', 'name', 'tally_group_name']);

        $created = 0;
        $existing = 0;
        $nameClashes = [];
        $codeClashes = [];
        $blankNames = [];

        $apply = function () use ($ledgers, $customers, &$created, &$existing, &$nameClashes, &$codeClashes, &$blankNames) {
            foreach ($ledgers as $ledger) {
                $name = trim((string) $ledger->name);

                // A ledger with no usable name cannot become a customer, and
                // inventing one is not an option. Report and move on.
                if ($name === '') {
                    $blankNames[] = 'ledger #'.$ledger->id;

                    continue;
                }

                $code = self::CODE_PREFIX.$ledger->id;

                // withTrashed: a customer someone soft-deleted stays deleted.
                // Re-creating it here would silently undo a person's decision.
                $sameCode = Customer::withTrashed()->where('code', $code)->first();

                if ($sameCode !== null) {
                    // Same code AND same name is this import's own earlier run.
                    // Same code but a DIFFERENT name is a collision with a row
                    // somebody else made, and counting it as "already imported"
                    // asserted something false while dropping the ledger
                    // without ever naming it.
                    if (trim((string) $sameCode->name) === $name) {
                        $existing++;
                    } else {
                        $codeClashes[] = sprintf('%s (ledger #%d) — code %s already belongs to "%s"', $name, $ledger->id, $code, $sameCode->name);
                    }

                    continue;
                }

                // A customer of the same name from another source is NOT the
                // same row and is NOT merged — reported so a person decides.
                $clash = Customer::withTrashed()->where('name', $name)->first();
                if ($clash !== null) {
                    $nameClashes[] = sprintf('%s (ledger #%d) — existing customer %s', $name, $ledger->id, $clash->code);

                    continue;
                }

                $customer = $customers->create([
                    'code' => $code,
                    'name' => $name,
                    // Everything else a customer can carry is absent from a
                    // Tally ledger. Left NULL, never fabricated.
                    'email' => null,
                    'phone' => null,
                    'address' => null,
                    'gstin' => null,
                    'state_code' => null,
                    'is_active' => true,
                ]);

                $this->writeLink($customer, $ledger);

                $created++;
            }
        };

        if ($this->option('write')) {
            DB::transaction($apply);
        } else {
            // Dry run inside a transaction that is always thrown away, so the
            // counts reported are exactly what a real run would do.
            DB::beginTransaction();

            try {
                $apply();
            } finally {
                DB::rollBack();
            }
        }

        $this->newLine();
        $this->info($this->option('write') ? 'IMPORTED' : 'DRY RUN — nothing written');
        $this->newLine();

        $this->table(['count', 'value'], [
            ['groups selected', $groups->implode(', ')],
            ['ledgers in those groups', $ledgers->count()],
            [$this->option('write') ? 'customers created' : 'customers that would be created', $created],
            ['already imported (same code) — untouched', $existing],
            ['name already used by another customer — SKIPPED for review', count($nameClashes)],
            ['code already used by another customer — SKIPPED for review', count($codeClashes)],
            ['ledgers with a blank name — SKIPPED', count($blankNames)],
        ]);

        // The skips are the point of the report: each line is a decision a
        // person still owes, named specifically enough to act on.
        foreach ($nameClashes as $line) {
            $this->warn('  name clash, not imported: '.$line);
        }
        foreach ($codeClashes as $line) {
            $this->warn('  code clash, not imported: '.$line);
        }
        foreach ($blankNames as $line) {
            $this->warn('  blank name, not imported: '.$line);
        }

        if (! $this->option('write')) {
            $this->newLine();
            $this->line('Re-run with --write after reading the plan above.');
        }

        return self::SUCCESS;
    }

    /**
     * --link-existing: give the customers this command minted BEFORE the link
     * columns existed the identity they should always have had.
     *
     * It creates nothing, deletes nothing and renames nothing. The only rows
     * it considers are the ones this command itself made — code "TL-{ledger
     * id}" — and the ledger id is read back out of that code, because it is
     * the only trace of the ledger those rows carry.
     *
     * ARCHIVED CUSTOMERS ARE LEFT ALONE (the default scope, stated so it reads
     * as a decision rather than an accident). Somebody took that row out of
     * service; writing a Tally identity onto it would be this command touching
     * a record a person retired, for no benefit — nothing posts against it. The
     * report counts them so their absence is visible.
     */
    private function linkExisting(): int
    {
        $write = (bool) $this->option('write');

        $linked = 0;
        $alreadyLinked = 0;
        $nameOnly = 0;
        $unparseable = [];
        $missingLedger = [];

        $apply = function () use (&$linked, &$alreadyLinked, &$nameOnly, &$unparseable, &$missingLedger): void {
            Customer::query()
                ->where('code', 'like', self::CODE_PREFIX.'%')
                ->orderBy('code')
                ->each(function (Customer $customer) use (&$linked, &$alreadyLinked, &$nameOnly, &$unparseable, &$missingLedger): void {
                    // A LINK IS A PAIR, AND HALF OF ONE IS STILL A LINK. If
                    // either column is set this row is left exactly as it is
                    // and reported: completing somebody else's half-link would
                    // be this command writing over a value it did not put
                    // there, which is the one thing --link-existing must never
                    // do. A visible half-link is a person's decision to make.
                    if ($customer->tally_ledger_guid !== null || $customer->tally_ledger_name !== null) {
                        $alreadyLinked++;

                        return;
                    }

                    $suffix = substr((string) $customer->code, strlen(self::CODE_PREFIX));

                    // `code` is free text — "TL-" is only this command's own
                    // convention. A hand-made "TL-ACME" casts to ledger 0,
                    // which matches nothing, so it is refused by shape and
                    // named in the report rather than skipped in silence.
                    if ($suffix === '' || ! ctype_digit($suffix)) {
                        $unparseable[] = sprintf('%s (%s) — "%s" is not a ledger id', $customer->name, $customer->code, $suffix);

                        return;
                    }

                    // Plain find(), so a soft-deleted ledger does NOT match:
                    // the mirror is re-pulled from Tally, and a ledger that is
                    // no longer in it is a fact for a person to look at, not
                    // an identity to attach quietly.
                    $ledger = Ledger::find((int) $suffix);

                    if ($ledger === null) {
                        $missingLedger[] = sprintf('%s (%s) — no ledger #%s in this database', $customer->name, $customer->code, $suffix);

                        return;
                    }

                    $this->writeLink($customer, $ledger);
                    $linked++;

                    // `ledgers.tally_guid` is nullable. Half an identity is
                    // still worth recording — and counted, so the report never
                    // implies a GUID that is not there.
                    if ($ledger->tally_guid === null) {
                        $nameOnly++;
                    }
                });
        };

        if ($write) {
            DB::transaction($apply);
        } else {
            // Same mechanic as the import: a dry run does the real work inside
            // a transaction that is always thrown away, so the counts printed
            // are exactly what --write would do.
            DB::beginTransaction();

            try {
                $apply();
            } finally {
                DB::rollBack();
            }
        }

        $archived = Customer::onlyTrashed()->where('code', 'like', self::CODE_PREFIX.'%')->count();

        $this->info($write ? 'LINKED' : 'DRY RUN — nothing written');
        $this->newLine();

        $this->table(['count', 'value'], [
            ['TL- customers examined', $linked + $alreadyLinked + count($unparseable) + count($missingLedger)],
            [$write ? 'ledger links written' : 'ledger links that would be written', $linked],
            ['of those, the ledger has no Tally GUID — name only', $nameOnly],
            ['already linked — untouched', $alreadyLinked],
            ['code is not TL-{ledger id} — SKIPPED for review', count($unparseable)],
            ['ledger not in this database — SKIPPED for review', count($missingLedger)],
            ['archived customers — not examined', $archived],
        ]);

        foreach ($unparseable as $line) {
            $this->warn('  unreadable code, not linked: '.$line);
        }
        foreach ($missingLedger as $line) {
            $this->warn('  ledger missing, not linked: '.$line);
        }

        if (! $write) {
            $this->newLine();
            $this->line('Re-run with --link-existing --write after reading the plan above.');
        }

        return self::SUCCESS;
    }

    /**
     * Record WHICH Tally ledger a customer is — the ONLY writer of those two
     * columns anywhere in the app (design brief §Schema 3).
     *
     * forceFill, not a mass assign: the columns are deliberately absent from
     * Customer's #[Fillable] so that no request, form or future
     * `Customer::create([...$input])` can ever point a customer at a different
     * ledger. That protection applies here too, hence the explicit fill.
     *
     * The extra save() on a freshly created row costs one UPDATE per imported
     * customer and writes no audit noise: RecordsConfigurationAudit logs the
     * FILLABLE attributes only, so a change confined to these two is an empty
     * change it does not record — and a console run has no authenticated user
     * to stamp `updated_by` with in the first place.
     *
     * The ledger name is stored exactly as the mirror carries it, untrimmed.
     * The customer's own name is the tidied, human one; this column is Tally's
     * string, and trimming it would record a ledger name that is not the
     * ledger's.
     */
    private function writeLink(Customer $customer, Ledger $ledger): void
    {
        $customer->forceFill([
            'tally_ledger_guid' => $ledger->tally_guid,
            'tally_ledger_name' => $ledger->name,
        ])->save();
    }
}
