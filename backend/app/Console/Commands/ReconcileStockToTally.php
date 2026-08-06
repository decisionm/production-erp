<?php

namespace App\Console\Commands;

use App\Modules\TallySync\Models\TallyStockSnapshot;
use App\Modules\TallySync\Services\TallyStockReconcileService;
use Illuminate\Console\Command;

/**
 * Make the ERP's stock match Tally's, from a stock summary the agent has taken.
 *
 * The owner (06-Aug), reading the approval desk's shortfall list: "we can sync
 * the live stock from the tally and start consume from here". This is that.
 *
 * WHY A COMMAND, dry by default: it writes live stock balances, which this
 * project holds must be a deliberate act rather than a deploy side effect —
 * the same shape as the colour derivation, the packing seed and the pouch
 * doses. Unlike those, this one is meant to be RE-RUN whenever a fresh
 * snapshot is taken, so the dry run is the important half: it prints every
 * balance that would move, in both directions, before anything does.
 */
class ReconcileStockToTally extends Command
{
    protected $signature = 'tally:reconcile-stock
        {--snapshot= : Snapshot id. Defaults to the most recent one.}
        {--write : Apply the corrections. Without it, nothing is written.}';

    protected $description = "Match the ERP's stock balances to a Tally stock summary";

    public function handle(TallyStockReconcileService $reconcile): int
    {
        $write = (bool) $this->option('write');

        $snapshot = $this->option('snapshot') !== null
            ? TallyStockSnapshot::query()->find((int) $this->option('snapshot'))
            : TallyStockSnapshot::query()->latest('id')->first();

        if ($snapshot === null) {
            $this->error('No Tally stock snapshot found. Take a stock summary from the sync agent first.');

            return self::FAILURE;
        }

        $this->line("Snapshot #{$snapshot->id} — {$snapshot->company}, as of {$snapshot->as_of->toDateString()} ({$snapshot->status})");

        if (! $write) {
            $this->warn('DRY RUN — nothing written. Re-run with --write to apply.');
        }

        $this->line('');

        $result = $reconcile->apply($snapshot, null, $write);

        foreach ($result['changes'] as $change) {
            // The DIRECTION stated as a word, not left to the reader to work out
            // from a signed number. "+" and "-" beside four-decimal quantities
            // in a terminal is exactly the sort of thing that gets misread once
            // and then trusted.
            $sign = str_starts_with($change['difference'], '-') ? 'issue ' : 'receive';

            $this->line(sprintf(
                '  %-7s %-14s %-36s @ %-24s ERP %14s -> Tally %14s',
                $sign,
                ltrim($change['difference'], '-'),
                mb_strimwidth($change['item'], 0, 36, '…'),
                mb_strimwidth($change['warehouse'], 0, 24, '…'),
                $change['erp'],
                $change['tally'],
            ));
        }

        $this->line('');
        $this->table(
            ['received', 'issued', 'already equal', 'skipped'],
            [[$result['received'], $result['issued'], $result['already_equal'], count($result['skipped'])]],
        );

        foreach ($result['skipped'] as $note) {
            $this->warn('  '.$note);
        }

        if ($write) {
            $this->line('');
            $this->info("Applied. Every movement carries the reference {$result['reference']}.");
        }

        return self::SUCCESS;
    }
}
