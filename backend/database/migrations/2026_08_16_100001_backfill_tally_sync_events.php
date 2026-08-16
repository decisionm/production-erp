<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Seed tally_sync_events from the timestamps every existing entry already
 * carries, so the Control Center's timeline does not start blank for the
 * vouchers the factory has posted since July.
 *
 * BEST-EFFORT, AND SAYS SO. An entry's columns are the LAST value of each
 * fact, not the history of it (TALLY-SYNC-CHAIN.md §2): a voucher failed
 * three times has one error_message, a retried voucher has one
 * delivered_at. So this reconstructs what the columns can still prove —
 *
 *   created_at            → voucher.enqueued
 *   delivered_at          → pending.delivered  (the LAST hand-out)
 *   synced_at             → voucher.synced
 *   released_at           → voucher.released   (released_by carried in details)
 *   status = failed       → voucher.failed     at updated_at, with the LAST error
 *   status = dismissed    → voucher.dismissed  at updated_at
 *
 * — and EVERY row it writes carries details.backfilled = true, actor_type
 * 'system' and actor_label 'backfill 2026-08-16', so nobody reads a
 * reconstruction as an observation. Rows the live recorder writes never
 * carry that flag.
 *
 * IDEMPOTENT: an entry that already has ANY event is skipped whole (a
 * half-finished earlier run, or an entry the live recorder has already
 * touched — this migration ships with the recorder, so a live event on an
 * entry means the entry's history has begun and must not be prefixed with
 * a reconstruction after the fact). Chunked by keyset, 500 at a time.
 *
 * down() deletes ONLY the rows this migration wrote (details.backfilled =
 * true) — the live-recorded history is not this migration's to remove.
 * Query-builder deletes on purpose: the model refuses delete(), and it is
 * right to; this is the schema-level exception a rollback needs.
 */
return new class extends Migration
{
    private const ACTOR_LABEL = 'backfill 2026-08-16';

    public function up(): void
    {
        $now = now();
        $entriesSeen = 0;
        $entriesSkipped = 0;
        $eventsWritten = 0;

        DB::table('tally_sync_entries')
            ->select([
                'id', 'tally_voucher_type', 'payload', 'status', 'attempts', 'error_message',
                'created_at', 'updated_at', 'delivered_at', 'synced_at', 'released_at', 'released_by',
            ])
            ->chunkById(500, function ($entries) use ($now, &$entriesSeen, &$entriesSkipped, &$eventsWritten): void {
                $entriesSeen += $entries->count();

                // The replay guard, one query per chunk.
                $alreadyRecorded = DB::table('tally_sync_events')
                    ->whereIn('tally_sync_entry_id', $entries->pluck('id'))
                    ->distinct()
                    ->pluck('tally_sync_entry_id')
                    ->flip();

                $rows = [];

                foreach ($entries as $entry) {
                    if ($alreadyRecorded->has($entry->id)) {
                        $entriesSkipped++;

                        continue;
                    }

                    foreach ($this->eventsFor($entry) as $event) {
                        $rows[] = $this->row($entry->id, $event, $now);
                    }
                }

                if ($rows !== []) {
                    DB::table('tally_sync_events')->insert($rows);
                    $eventsWritten += count($rows);
                }
            });

        Log::info('backfill_tally_sync_events: done', [
            'entries_seen' => $entriesSeen,
            'entries_skipped_already_recorded' => $entriesSkipped,
            'events_written' => $eventsWritten,
        ]);
    }

    public function down(): void
    {
        $deleted = DB::table('tally_sync_events')
            ->where('details->backfilled', true)
            ->delete();

        Log::info('backfill_tally_sync_events: rolled back', ['events_deleted' => $deleted]);
    }

    /**
     * The events one entry's columns can still vouch for, oldest first.
     *
     * @return list<array{event: string, at: string, details: array<string, mixed>}>
     */
    private function eventsFor(object $entry): array
    {
        $payload = is_string($entry->payload) ? (json_decode($entry->payload, true) ?: []) : [];
        $voucherNumber = $payload['voucher_number'] ?? null;
        $identity = ['voucher_type' => $entry->tally_voucher_type, 'voucher_number' => $voucherNumber];

        $events = [];

        if ($entry->created_at !== null) {
            $events[] = ['event' => 'voucher.enqueued', 'at' => $entry->created_at, 'details' => $identity];
        }

        if ($entry->delivered_at !== null) {
            $events[] = ['event' => 'pending.delivered', 'at' => $entry->delivered_at, 'details' => $identity];
        }

        if ($entry->synced_at !== null) {
            $events[] = ['event' => 'voucher.synced', 'at' => $entry->synced_at, 'details' => $identity];
        }

        if ($entry->released_at !== null) {
            $events[] = [
                'event' => 'voucher.released',
                'at' => $entry->released_at,
                'details' => $identity + ['released_by' => $entry->released_by],
            ];
        }

        // Current terminal-ish state, dated by the row's last write — the
        // only clock a status column carries.
        if ($entry->status === 'failed' && $entry->updated_at !== null) {
            $events[] = [
                'event' => 'voucher.failed',
                'at' => $entry->updated_at,
                'details' => ['error_message' => $entry->error_message, 'attempt' => (int) $entry->attempts],
            ];
        }

        if ($entry->status === 'dismissed' && $entry->updated_at !== null) {
            $events[] = [
                'event' => 'voucher.dismissed',
                'at' => $entry->updated_at,
                'details' => ['previous_error' => $entry->error_message],
            ];
        }

        // Oldest first so ids read in time order like the live rows do.
        usort($events, fn (array $a, array $b) => strcmp((string) $a['at'], (string) $b['at']));

        return $events;
    }

    /**
     * @param  array{event: string, at: string, details: array<string, mixed>}  $event
     * @return array<string, mixed>
     */
    private function row(int $entryId, array $event, Carbon $now): array
    {
        return [
            'tally_sync_entry_id' => $entryId,
            'event' => $event['event'],
            'direction' => 'erp_to_tally',
            'occurred_at' => $event['at'],
            'actor_type' => 'system',
            'actor_id' => null,
            'actor_label' => self::ACTOR_LABEL,
            'details' => json_encode($event['details'] + ['backfilled' => true]),
            'created_at' => $now,
        ];
    }
};
