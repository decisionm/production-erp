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
 * customers (DEC-20260809-002) is owner-confirmed but sits on an UNMERGED
 * branch (PR #155). Nothing here hardcodes a group list, precisely so this
 * command does not depend on, or pre-empt, a record that is not yet in force.
 *
 * WHAT IT NEVER DOES: it never updates or deletes an existing customer, and
 * it never invents a field. A Tally ledger carries a name and a group — no
 * GSTIN, no email, no phone, no address — so those are left NULL rather than
 * fabricated (AGENTS.md: never invent a factory value).
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

    protected $signature = 'sales:import-customers-from-ledgers
        {--groups= : Comma-separated Tally group names to import, e.g. "Sundry Debtors,Chennai". Required to write. Omit for a census of every group in the data.}
        {--write : Actually write (default is a dry run)}';

    protected $description = 'Create Sales customers from pulled Tally ledgers, selected by an explicit allow-list of group names';

    public function handle(CustomerService $customers): int
    {
        // The census first, always — a person choosing groups must be able to
        // see every group that exists and how big it is, including the ones
        // they are about to leave out.
        $census = Ledger::query()
            ->selectRaw('COALESCE(tally_group_name, "(no group)") as grp, COUNT(*) as n')
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

        $groups = collect(explode(',', (string) $this->option('groups')))
            ->map(fn (string $g) => trim($g))
            ->filter()
            ->values();

        if ($groups->isEmpty()) {
            $this->warn('No --groups given, so nothing was selected and nothing was written.');
            $this->line('  Choose from the groups above and re-run, e.g.:');
            $this->line('    --groups="Sundry Debtors,Chennai,Kerala"');
            $this->line('  Groups are matched by name exactly. Membership is NEVER inferred from a');
            $this->line('  parent group — see this command\'s docblock for why.');

            return self::SUCCESS;
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
            ->get(['id', 'name', 'tally_group_name']);

        $created = 0;
        $existing = 0;
        $nameClashes = [];
        $blankNames = [];

        $apply = function () use ($ledgers, $customers, &$created, &$existing, &$nameClashes, &$blankNames) {
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
                if (Customer::withTrashed()->where('code', $code)->exists()) {
                    $existing++;

                    continue;
                }

                // A customer of the same name from another source is NOT the
                // same row and is NOT merged — reported so a person decides.
                $clash = Customer::withTrashed()->where('name', $name)->first();
                if ($clash !== null) {
                    $nameClashes[] = sprintf('%s (ledger #%d) — existing customer %s', $name, $ledger->id, $clash->code);

                    continue;
                }

                $customers->create([
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
            ['ledgers with a blank name — SKIPPED', count($blankNames)],
        ]);

        // The skips are the point of the report: each line is a decision a
        // person still owes, named specifically enough to act on.
        foreach ($nameClashes as $line) {
            $this->warn('  name clash, not imported: '.$line);
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
}
