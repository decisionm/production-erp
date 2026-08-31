<?php

namespace App\Console\Commands;

use App\Modules\Procurement\Models\Vendor;
use App\Modules\Procurement\Services\VendorFromLedger;
use App\Modules\Procurement\Services\VendorService;
use App\Modules\TallySync\Models\Ledger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Create Procurement vendors from the Tally ledgers already pulled into this
 * database — no Tally contact, no invented party.
 *
 * The sibling of ImportCustomersFromLedgers, and deliberately the same shape:
 * that command has been reviewed and run, and a vendor import that behaved
 * differently would be a second set of rules for the same act. Read that file
 * for the reasoning; what follows is what differs.
 *
 * WHY THIS IS AN EXPLICIT RUN AND NOT A SYNC. The vendor list is the factory's
 * own commercial record. A background job that quietly invented hundreds of
 * vendors, or silently changed one, is exactly what nobody notices until an
 * order goes to the wrong party. So it is a command a person runs, reads, and
 * only then re-runs with --write, matching AGENTS.md for every live
 * master-data change.
 *
 * SELECTION IS AN ALLOW-LIST OF GROUP NAMES, and the evidence says why it must
 * be. The Sundry Creditors group in this factory's books holds far more than
 * suppliers: the 28-Aug voucher exports alone surfaced an INTEREST ledger whose
 * name differs from a real supplier's by two letters, and the company's OWN
 * second GST registration, both sitting among the parties. Deciding which
 * creditor is a vendor is the owner's call, not a filter an agent writes. So
 * the caller names the groups, and a dry run with no --groups prints every
 * group with its count so the naming is an informed act.
 *
 * WHAT IT NEVER DOES: it never updates or deletes an existing vendor, and it
 * never invents a field. A ledger now carries a GSTIN and a state where the
 * masters pull found them (migration 2026_08_28_120000) — those come across
 * when present and stay NULL when not. Nothing else about a vendor exists in
 * Tally, so email, phone and address are left NULL rather than fabricated
 * (AGENTS.md: never invent a factory value).
 *
 * THE CODE. A vendor's code is minted by VendorService, the same sequence a
 * person creating one on the form gets. It is deliberately NOT derived from
 * the ledger id: a code is a business identifier a person may change, and the
 * identity is carried by tally_ledger_guid instead — the column this command
 * is the only writer of.
 */
class ImportVendorsFromLedgers extends Command
{
    /**
     * The census label for ledgers with no group. It is NOT a group name and
     * must never be selectable: passing it would match nothing while passing
     * the unknown-group check, and the command would report IMPORTED having
     * imported nothing.
     */
    private const NO_GROUP = '(no group)';

    protected $signature = 'procurement:import-vendors-from-ledgers
        {--groups=* : Tally group name to import. Repeatable, and also accepts a comma-separated list. Required to write. Omit for a census of every group in the data.}
        {--backfill : Import nothing; fill the GSTIN and state code on vendors this command already created that have none. Dry run unless --write.}
        {--write : Actually write (default is a dry run)}';

    protected $description = 'Create Procurement vendors from pulled Tally ledgers, selected by an explicit allow-list of group names';

    public function handle(VendorService $vendors): int
    {
        // THE BACKFILL BRANCHES FIRST, and it imports nothing, so the census
        // below is noise for it. Refused together with --groups rather than
        // silently doing one of the two: an operator who asked for both must
        // not be left guessing which happened.
        if ($this->option('backfill')) {
            if (! empty(array_filter((array) $this->option('groups')))) {
                $this->error('--backfill imports nothing, so --groups has no meaning with it.');
                $this->line('  Run the import first (--groups=... --write), then the backfill (--backfill --write).');

                return self::FAILURE;
            }

            return $this->backfill();
        }

        $census = Ledger::query()
            // Single-quoted: a double-quoted literal is only a string while
            // MySQL is not in ANSI_QUOTES mode, where it would be read as an
            // identifier and the query would fail.
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
        $this->table(['group', 'ledgers'], $census->map(fn (int $n, string $g) => [$g, $n])->values()->all());
        $this->line('  total ledgers: '.$census->sum());
        $this->newLine();

        // Repeatable option, each value possibly a comma-separated list. A
        // value matching a census group EXACTLY is taken whole, so a Tally
        // group whose own name contains a comma stays selectable.
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
            $this->line('    --groups="Sundry Creditors"');
            $this->line('  Groups are matched by name exactly, and membership is NEVER inferred from a');
            $this->line('  parent group — see this command\'s docblock for why.');

            return self::SUCCESS;
        }

        if ($groups->contains(self::NO_GROUP)) {
            $this->error(sprintf('"%s" is a label for ledgers that have no group, not a group you can import.', self::NO_GROUP));
            $this->line('  Those ledgers cannot be attributed to anything, so importing them would create');
            $this->line('  vendors on no authority at all. Give them a group in Tally first.');

            return self::FAILURE;
        }

        $unknown = $groups->reject(fn (string $g) => $census->has($g));
        if ($unknown->isNotEmpty()) {
            $this->error('These groups are not in the data, so they would silently import nothing:');
            foreach ($unknown as $g) {
                $this->line('  · '.$g);
            }
            $this->line('  Fix the spelling against the census above and re-run. Refusing rather than');
            $this->line('  importing a subset, because a typo that quietly halves the vendor list is');
            $this->line('  worse than a stopped command.');

            return self::FAILURE;
        }

        $ledgers = Ledger::query()
            ->whereIn('tally_group_name', $groups)
            ->orderBy('name')
            ->get(['id', 'tally_guid', 'name', 'tally_group_name', 'gstin', 'state_name']);

        $created = 0;
        $existing = 0;
        $nameClashes = [];
        $blankNames = [];
        $withoutGstin = 0;

        $apply = function () use ($ledgers, $vendors, &$created, &$existing, &$nameClashes, &$blankNames, &$withoutGstin) {
            foreach ($ledgers as $ledger) {
                $name = trim((string) $ledger->name);

                // A ledger with no usable name cannot become a vendor, and
                // inventing one is not an option. Report and move on.
                if ($name === '') {
                    $blankNames[] = 'ledger #'.$ledger->id;

                    continue;
                }

                // Already imported by this command: same ledger, matched on the
                // identity column rather than the name, so a renamed vendor is
                // still recognised as this ledger's.
                if (Vendor::withTrashed()->where('tally_ledger_guid', $ledger->tally_guid)->exists()) {
                    $existing++;

                    continue;
                }

                // A vendor of the same name from another source is NOT the same
                // row and is NOT merged — reported so a person decides. Includes
                // trashed rows: a vendor somebody retired stays retired.
                $clash = Vendor::withTrashed()->where('name', $name)->first();
                if ($clash !== null) {
                    $nameClashes[] = sprintf('%s (ledger #%d) — existing vendor %s', $name, $ledger->id, $clash->code);

                    continue;
                }

                $gstin = trim((string) $ledger->gstin);

                // THE MAPPING IS SHARED, NOT REPEATED. VendorFromLedger is the
                // one answer to "what does a Tally ledger say a vendor is",
                // read by this command and by the Owner/Accounts review screen
                // alike. It used to be spelled out here, which was fine while
                // this was the only path; a second path with its own copy is
                // how two rival sets of rules for one act begin. What it does
                // — the GSTIN-derived state code, nothing invented for the
                // fields Tally does not carry — is documented on that class.
                $vendor = $vendors->create([
                    // No code: VendorService mints the next in the same
                    // sequence a person creating one on the form gets.
                    ...VendorFromLedger::attributes($ledger),
                    // Absent from a Tally ledger entirely. Left NULL, never
                    // fabricated (AGENTS.md).
                    'address' => null,
                    'is_active' => true,
                ]);

                $this->writeLink($vendor, $ledger);

                if ($gstin === '') {
                    $withoutGstin++;
                }

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
            [$this->option('write') ? 'vendors created' : 'vendors that would be created', $created],
            ['of those, no GSTIN in Tally — left blank', $withoutGstin],
            ['already imported (same ledger) — untouched', $existing],
            ['name already used by another vendor — SKIPPED for review', count($nameClashes)],
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

    /**
     * --backfill: give the vendors this command already created the GSTIN and
     * state code their ledgers carry now.
     *
     * WHY IT IS A SEPARATE RUN. 628 vendors were imported on 28-Aug before the
     * agent asked Tally for party details, so every one of them landed with no
     * GSTIN. The import cannot fix them on a re-run: it matches on the ledger
     * and skips a vendor it has already made, which is exactly the behaviour
     * that stops a rename becoming a duplicate.
     *
     * It creates nothing, deletes nothing and renames nothing. The only rows it
     * considers are the ones this command itself made — those carrying a
     * tally_ledger_guid — and a field ALREADY SET is left alone: filling a
     * blank is completing this command's own work, while overwriting a value a
     * person may have typed is not this command's business.
     */
    private function backfill(): int
    {
        $write = (bool) $this->option('write');

        $filled = 0;
        $alreadySet = 0;
        $ledgerHasNone = 0;
        $missingLedger = [];

        $apply = function () use (&$filled, &$alreadySet, &$ledgerHasNone, &$missingLedger): void {
            Vendor::query()
                ->whereNotNull('tally_ledger_guid')
                ->orderBy('code')
                ->each(function (Vendor $vendor) use (&$filled, &$alreadySet, &$ledgerHasNone, &$missingLedger): void {
                    if (trim((string) $vendor->gstin) !== '') {
                        $alreadySet++;

                        return;
                    }

                    // Plain first(), so a soft-deleted ledger does NOT match:
                    // the mirror is re-pulled from Tally, and a ledger no
                    // longer in it is a fact for a person to look at.
                    $ledger = Ledger::where('tally_guid', $vendor->tally_ledger_guid)->first();

                    if ($ledger === null) {
                        $missingLedger[] = sprintf('%s (%s)', $vendor->name, $vendor->code);

                        return;
                    }

                    $gstin = trim((string) $ledger->gstin);

                    if ($gstin === '') {
                        $ledgerHasNone++;

                        return;
                    }

                    $vendor->update([
                        'gstin' => $gstin,
                        'state_code' => $vendor->state_code ?: VendorFromLedger::stateCodeFrom($gstin),
                    ]);
                    $filled++;
                });
        };

        if ($write) {
            DB::transaction($apply);
        } else {
            DB::beginTransaction();

            try {
                $apply();
            } finally {
                DB::rollBack();
            }
        }

        $this->info($write ? 'BACKFILLED' : 'DRY RUN — nothing written');
        $this->newLine();

        $this->table(['count', 'value'], [
            ['vendors this command created', $filled + $alreadySet + $ledgerHasNone + count($missingLedger)],
            [$write ? 'GSTINs written' : 'GSTINs that would be written', $filled],
            ['already had a GSTIN — untouched', $alreadySet],
            ['the ledger has no GSTIN either — nothing to fill', $ledgerHasNone],
            ['ledger not in this database — SKIPPED for review', count($missingLedger)],
        ]);

        foreach ($missingLedger as $line) {
            $this->warn('  ledger missing, not filled: '.$line);
        }

        if (! $write) {
            $this->newLine();
            $this->line('Re-run with --backfill --write after reading the plan above.');
        }

        return self::SUCCESS;
    }

    /**
     * Record WHICH Tally ledger a vendor is — the ONLY writer of that column
     * anywhere in the app.
     *
     * forceFill, not a mass assign: `tally_ledger_guid` is deliberately absent
     * from Vendor's #[Fillable] so no request, form or future
     * `Vendor::create([...$input])` can point a vendor at a different ledger.
     * That protection applies here too, hence the explicit fill.
     */
    private function writeLink(Vendor $vendor, Ledger $ledger): void
    {
        $vendor->forceFill(['tally_ledger_guid' => $ledger->tally_guid])->save();
    }
}
