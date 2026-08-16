<?php

namespace App\Modules\TallySync\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One observed event in the Tally sync chain — a voucher enqueued, handed
 * to the agent, accepted, rejected, retried by a person, a masters pull
 * that landed. The durable form of what the agent file log already says.
 *
 * APPEND-ONLY. There is no update() and no delete() — not "none exposed on
 * a route", none at all: booted() refuses both, so a row here can never
 * stop saying what it said. A correction is a later event. This is what
 * makes the timeline the Control Center reads trustworthy after an entry
 * has been touched more than once (TALLY-SYNC-CHAIN.md §2): the entry's
 * own columns are overwritten by every retry, these rows are not.
 *
 * Written by TallySyncEventRecorder only. Read by anyone.
 */
#[Fillable([
    'tally_sync_entry_id', 'event', 'direction', 'occurred_at',
    'actor_type', 'actor_id', 'actor_label', 'details',
])]
class TallySyncEvent extends Model
{
    /**
     * No updated_at column exists, so Eloquent must not try to write one.
     * The append-only rule expressed in code (material_cost_versions
     * precedent): there is no "last modified" for a row that never changes.
     */
    public const UPDATED_AT = null;

    public const DIRECTION_ERP_TO_TALLY = 'erp_to_tally';

    public const DIRECTION_TALLY_TO_ERP = 'tally_to_erp';

    public const DIRECTION_NONE = 'none';

    public const ACTOR_USER = 'user';

    public const ACTOR_AGENT = 'agent';

    public const ACTOR_SYSTEM = 'system';

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Refuse, in code, what the schema already declines to support: an
     * event is an observation, and an observation is never edited or
     * erased. Throwing (rather than silently returning false) makes a
     * future "just fix the row" attempt fail its own test.
     */
    protected static function booted(): void
    {
        static::updating(function (self $event): void {
            throw new LogicException("tally_sync_events is append-only: event #{$event->id} cannot be updated.");
        });

        static::deleting(function (self $event): void {
            throw new LogicException("tally_sync_events is append-only: event #{$event->id} cannot be deleted.");
        });
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(TallySyncEntry::class, 'tally_sync_entry_id');
    }

    /** Whether this row is a reconstruction from timestamps rather than an observation. */
    public function isBackfilled(): bool
    {
        return (bool) ($this->details['backfilled'] ?? false);
    }
}
