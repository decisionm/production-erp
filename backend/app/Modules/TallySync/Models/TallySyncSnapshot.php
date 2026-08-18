<?php

namespace App\Modules\TallySync\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One post-Tally snapshot: the XML the agent built and sent for an entry,
 * its sha256, and what Tally answered — uploaded by the agent after each
 * post (Phase 4). An OBSERVATION kept beside the entry: nothing reads it to
 * decide a status, an attempt count, or what reaches Tally.
 *
 * NEVER EDITED. A new attempt is a new row; booted() refuses update in
 * code so a row here can never stop saying what the agent sent. Deleting
 * IS allowed — by the retention prune only (TallySyncSnapshotService), for
 * bulk: the XML body ages out, the history row (snapshot.stored on
 * tally_sync_events, with the sha256 and counts) does not.
 *
 * FC-06: the `xml` column holds the whole document — a Receipt Note's XML
 * names the supplier and carries RATE / AMOUNT — and `tally_message` /
 * `tally_raw` are Tally's own words, which can name a supplier ledger.
 * Neither is gated here; TallySyncSnapshotResource decides who reads them.
 *
 * Written by TallySyncSnapshotService only.
 */
#[Fillable([
    'tally_sync_entry_id', 'attempt', 'direction', 'xml_sha256', 'xml_bytes', 'xml',
    'tally_success', 'tally_created', 'tally_errors', 'tally_message', 'tally_raw',
    'agent_version', 'payload_hash', 'payload_matches', 'created_at',
])]
class TallySyncSnapshot extends Model
{
    /** No updated_at column exists: a snapshot is never edited (tally_sync_events precedent). */
    public const UPDATED_AT = null;

    /** The one direction reported today: the agent posted XML to Tally. */
    public const DIRECTION_POST = 'post';

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'xml_bytes' => 'integer',
            'tally_success' => 'boolean',
            'tally_created' => 'integer',
            'tally_errors' => 'integer',
            'payload_matches' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Refuse, in code, what the schema already declines to support: a
     * snapshot is what the agent sent, and that is never rewritten. A
     * correction is a new attempt and a new row.
     */
    protected static function booted(): void
    {
        static::updating(function (self $snapshot): void {
            throw new LogicException("tally_sync_snapshots is never edited: snapshot #{$snapshot->id} cannot be updated.");
        });
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(TallySyncEntry::class, 'tally_sync_entry_id');
    }

    /**
     * Whether Tally answered at all. False on the inconclusive-timeout path
     * (XML sent, nothing came back), where every tally_* column is null.
     */
    public function tallyAnswered(): bool
    {
        return $this->tally_success !== null
            || $this->tally_created !== null
            || $this->tally_errors !== null
            || $this->tally_message !== null
            || $this->tally_raw !== null;
    }
}
