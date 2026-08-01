<?php

namespace App\Console\Commands;

use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * One read-only answer to "is the Tally sync working?".
 *
 * The question came from the owner mid-go-live, and nothing could answer it
 * remotely: the queue lives in the database, the agent lives on a factory
 * desktop, and the server log only records exceptions — a perfectly stuck
 * queue is silent. This prints what the queue itself knows, which is enough
 * to tell WHERE the problem is without touching anything:
 *
 *  - pending entries with an old last-delivery  -> the agent is not polling
 *    (factory desktop: agent closed or machine off);
 *  - entries delivered recently but still pending/failed -> the agent runs
 *    but Tally is refusing (Tally closed, wrong company, or a naming error —
 *    the per-entry message says which);
 *  - everything synced -> the pipe is healthy end to end.
 *
 * Strictly read-only by construction: one query family, no state change, no
 * artisan side effects — safe to run against the live database at any hour,
 * which is the whole point.
 */
class TallySyncStatus extends Command
{
    protected $signature = 'tally:sync-status';

    protected $description = 'Read-only: queue counts, last delivery/ack times, and the most recent failure messages.';

    public function handle(): int
    {
        $counts = TallySyncEntry::query()
            ->selectRaw('status, COUNT(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $this->table(['status', 'count'], collect(['pending', 'failed', 'synced'])
            ->map(fn (string $status) => [$status, (string) ($counts[$status] ?? 0)])
            ->all());

        $lastDelivered = TallySyncEntry::query()->max('delivered_at');
        $lastSynced = TallySyncEntry::query()->max('synced_at');
        $lastQueued = TallySyncEntry::query()->max('created_at');

        $describe = function (?string $timestamp): string {
            if ($timestamp === null) {
                return 'never';
            }
            $moment = Carbon::parse($timestamp);

            return sprintf('%s (%s)', $moment->toDateTimeString(), $moment->diffForHumans());
        };

        $this->line('last voucher queued:    '.$describe($lastQueued));
        // delivered_at is stamped when the AGENT collects the voucher — it is
        // the proof the factory desktop is alive and polling, independent of
        // whether Tally then accepted anything.
        $this->line('last agent delivery:    '.$describe($lastDelivered));
        $this->line('last Tally acceptance:  '.$describe($lastSynced));

        $failed = TallySyncEntry::query()
            ->where('status', 'failed')
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get();

        if ($failed->isNotEmpty()) {
            $this->newLine();
            $this->warn('Most recent failures and their reasons:');
            foreach ($failed as $entry) {
                $number = data_get($entry->payload, 'voucher_number', '#'.$entry->id);
                $this->line(sprintf(
                    '  · %s  attempts=%d  %s',
                    $number,
                    (int) $entry->attempts,
                    (string) ($entry->error_message ?? '(no message recorded)'),
                ));
            }
        }

        // The one-line verdict, so a non-technical reader gets an answer and
        // not a table. Thresholds are deliberately coarse — this is triage,
        // not monitoring.
        $pending = (int) ($counts['pending'] ?? 0);
        $failedCount = (int) ($counts['failed'] ?? 0);
        $agentRecent = $lastDelivered !== null && Carbon::parse($lastDelivered)->gt(now()->subMinutes(15));

        $this->newLine();
        if ($pending === 0 && $failedCount === 0) {
            $this->info('VERDICT: queue empty — nothing waiting, nothing failed.');
        } elseif (! $agentRecent && $pending > 0) {
            $this->warn('VERDICT: vouchers are waiting and the agent has not collected anything in the last 15 minutes — check the factory desktop: is the sync agent running?');
        } elseif ($failedCount > 0) {
            $this->warn('VERDICT: the agent is delivering but Tally is refusing some vouchers — read the reasons above (Tally closed / wrong company / a name mismatch).');
        } else {
            $this->info('VERDICT: agent active, vouchers flowing.');
        }

        return self::SUCCESS;
    }
}
