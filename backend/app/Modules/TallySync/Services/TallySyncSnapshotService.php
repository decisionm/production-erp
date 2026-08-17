<?php

namespace App\Modules\TallySync\Services;

use App\Modules\TallySync\Models\Enums\TallySyncEventKind;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Models\TallySyncEvent;
use App\Modules\TallySync\Models\TallySyncSnapshot;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of tally_sync_snapshots — the agent's post-Tally record
 * of WHAT XML it sent and WHAT Tally answered (Phase 4; MASTER-PLAN
 * P4-01..05).
 *
 * A snapshot is an OBSERVATION kept beside the entry. store() touches no
 * column of the entry — not status, not attempts, not error_message, not
 * payload — and nothing anywhere reads a snapshot to decide what reaches
 * Tally: the agent uploads it AFTER its ack/fail path has run, fire-and-
 * forget, and an upload that never arrives changes nothing (the agent
 * contract in tally-sync-agent/src/snapshot.ts).
 *
 * Three rules, all here:
 *
 *   IDEMPOTENT-ISH. The agent's HTTP client can retry an upload whose
 *   response was lost; the same entry + the same xml_sha256 + the same
 *   attempt within IDEMPOTENCY_WINDOW_SECONDS is that retry, and returns
 *   the row already stored (wasRecentlyCreated false; the controller
 *   answers 200 instead of 201) — a retried upload must not double. A
 *   different attempt, a different document, or the same one past the
 *   window IS a new post and a new row: a snapshot is never edited.
 *
 *   payload_matches IS JUDGED NOW. The agent echoes the payload_hash the
 *   cloud stamped on its /pending row (PayloadHash::of); the verdict
 *   compares that echo with the hash of the payload the cloud holds AT
 *   STORE TIME. False means the payload was regenerated (a retry) between
 *   the hand-out and the upload — the XML on this row was not built from
 *   the payload a reader sees today. Null when nothing was echoed.
 *
 *   RETENTION ON WRITE. The host has no scheduler, so each store() prunes
 *   snapshots older than config('tally-sync.snapshot_retention_days') for
 *   ANY entry in one bounded DELETE. Zero or less: never prune. The XML
 *   body is bulk, not history — the snapshot.stored event (sha256, size,
 *   counts) on tally_sync_events outlives it.
 */
class TallySyncSnapshotService
{
    /** A re-upload of the same (entry, sha256, attempt) inside this window is the same report. */
    public const IDEMPOTENCY_WINDOW_SECONDS = 60;

    public function __construct(private readonly TallySyncEventRecorder $events) {}

    /**
     * Store one post report. $data is StoreTallySyncSnapshotRequest::validated():
     * xml_sha256 (verified against xml when a body is present — the request
     * did that), xml?, xml_bytes?, attempt?, tally?{success, created,
     * errors, message?, raw?}, agent_version?, payload_hash?.
     *
     * @param  array<string, mixed>  $data
     * @param  Authenticatable|null  $actor  the reporting agent — recorded on the history row
     */
    public function store(TallySyncEntry $entry, array $data, ?Authenticatable $actor): TallySyncSnapshot
    {
        $xml = is_string($data['xml'] ?? null) ? $data['xml'] : null;
        $sha = strtolower((string) $data['xml_sha256']);
        // The 1-based ordinal of THIS post as the AGENT counted it (attempts
        // at hand-out + 1). When the agent sent none (below 0.3.8) it is
        // stored as 0 — "not counted" — never guessed from the entry's own
        // counter, which markFailed increments and markSynced does not, so
        // it would name a different number for the same post depending on
        // whether Tally accepted it. isset, not ??: 0 is a value.
        $attempt = isset($data['attempt']) ? (int) $data['attempt'] : 0;

        $tally = is_array($data['tally'] ?? null) ? $data['tally'] : null;

        $existing = $this->recentDuplicate($entry, $sha, $attempt, $tally !== null);
        if ($existing !== null) {
            return $existing;
        }

        $echoedHash = is_string($data['payload_hash'] ?? null) ? strtolower($data['payload_hash']) : null;
        $matches = $echoedHash !== null && is_array($entry->payload)
            ? hash_equals(PayloadHash::of($entry->payload), $echoedHash)
            : null;

        return DB::transaction(function () use ($entry, $actor, $data, $xml, $sha, $attempt, $echoedHash, $matches, $tally) {
            $snapshot = TallySyncSnapshot::query()->create([
                'tally_sync_entry_id' => $entry->id,
                'attempt' => $attempt,
                'direction' => TallySyncSnapshot::DIRECTION_POST,
                'xml_sha256' => $sha,
                // With a body the server's own count wins over anything the
                // agent claims; without one, the agent's figure if it sent
                // one; else null — never 0 for a size nobody measured.
                'xml_bytes' => $xml !== null
                    ? strlen($xml)
                    : (isset($data['xml_bytes']) ? (int) $data['xml_bytes'] : null),
                'xml' => $xml,
                'tally_success' => $tally === null ? null : (bool) $tally['success'],
                'tally_created' => isset($tally['created']) ? (int) $tally['created'] : null,
                'tally_errors' => isset($tally['errors']) ? (int) $tally['errors'] : null,
                'tally_message' => is_string($tally['message'] ?? null) && $tally['message'] !== '' ? $tally['message'] : null,
                'tally_raw' => is_string($tally['raw'] ?? null) && $tally['raw'] !== '' ? $tally['raw'] : null,
                'agent_version' => is_string($data['agent_version'] ?? null) && $data['agent_version'] !== '' ? $data['agent_version'] : null,
                'payload_hash' => $echoedHash,
                'payload_matches' => $matches,
            ]);

            $this->pruneExpired();

            // Counts, ids and the hash — NEVER the XML or Tally's text: the
            // events table is readable by anyone with tally-sync.view, and
            // both are reader-gated on the snapshot (FC-06).
            $this->events->record(TallySyncEventKind::SnapshotStored, $entry, [
                'snapshot_id' => $snapshot->id,
                'attempt' => $snapshot->attempt,
                'xml_sha256' => $snapshot->xml_sha256,
                'xml_bytes' => $snapshot->xml_bytes,
                'tally_success' => $snapshot->tally_success,
                'agent_version' => $snapshot->agent_version,
                'payload_matches' => $snapshot->payload_matches,
            ], TallySyncEvent::DIRECTION_ERP_TO_TALLY, $actor);

            return $snapshot;
        });
    }

    /** The row a retried upload already landed on, if any (class docblock). */
    private function recentDuplicate(TallySyncEntry $entry, string $sha, int $attempt, bool $answered): ?TallySyncSnapshot
    {
        return TallySyncSnapshot::query()
            ->where('tally_sync_entry_id', $entry->id)
            ->where('xml_sha256', $sha)
            ->where('attempt', $attempt)
            // A timed-out post (no answer) followed within the window by the
            // same XML re-posted and ANSWERED is two posts, not one retried
            // upload — the answered one must land.
            ->when($answered, fn ($query) => $query->whereNotNull('tally_success'))
            ->when(! $answered, fn ($query) => $query->whereNull('tally_success'))
            ->where('created_at', '>=', now()->subSeconds(self::IDEMPOTENCY_WINDOW_SECONDS))
            ->orderByDesc('id')
            ->first();
    }

    /**
     * One bounded DELETE of every snapshot older than the retention, on any
     * entry. Retention of zero or less means keep everything.
     */
    private function pruneExpired(): void
    {
        $days = (int) config('tally-sync.snapshot_retention_days', 90);
        if ($days <= 0) {
            return;
        }

        TallySyncSnapshot::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }
}
