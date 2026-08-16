<?php

namespace App\Modules\TallySync\Models;

use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'syncable_type', 'syncable_id', 'tally_voucher_type', 'payload',
    'status', 'attempts', 'error_message', 'synced_at', 'delivered_at',
    'last_merged_at', 'released_at', 'released_by',
])]
class TallySyncEntry extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => TallySyncStatus::class,
            'synced_at' => 'datetime',
            'delivered_at' => 'datetime',
            'last_merged_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * Whether this voucher is (as far as anyone here knows) already in the
     * accountant's live books. The single question every guard against a
     * double post asks — a voucher Tally has accepted must never be
     * re-queued, re-posted, or re-marked as failed.
     */
    public function isInTally(): bool
    {
        return $this->status === TallySyncStatus::Synced || $this->synced_at !== null;
    }

    /** The Tally voucher number staff will search for — "SPE-12", "SJ-20260723-S1". */
    public function voucherNumber(): string
    {
        $number = $this->payload['voucher_number'] ?? null;

        return is_string($number) && $number !== '' ? $number : "#{$this->id}";
    }

    public function syncable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * What happened to this voucher, in the order it happened — the
     * append-only history (tally_sync_events) that survives every retry
     * overwriting error_message and delivered_at on this row.
     */
    public function events(): HasMany
    {
        return $this->hasMany(TallySyncEvent::class, 'tally_sync_entry_id')->orderBy('id');
    }
}
