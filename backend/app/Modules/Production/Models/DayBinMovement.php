<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Production\Models\Enums\DayBinMovementType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The shift-side day-bin ledger per machine per material (Phase 6):
 * load / return / count rows, referencing bag AND segment so multi-bag
 * batches and one-bag-across-shifts both fall out naturally. Lives in
 * Production because its grain is the machine/segment, not the store.
 * All writes go through DayBinLedgerService.
 */
#[Fillable([
    'work_center_id', 'item_id', 'shift_production_entry_id',
    'type',
    'material_bag_id', 'quantity_kg', 'recorded_by', 'recorded_at',
    // Why this machine was topped up while the estimate still expected
    // material in it — see the balance-ack migration.
    'balance_ack_reason', 'balance_ack_note',
])]
class DayBinMovement extends Model
{
    protected function casts(): array
    {
        return [
            'type' => DayBinMovementType::class,
            'quantity_kg' => 'decimal:4',
            'recorded_at' => 'datetime',
        ];
    }

    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function shiftProductionEntry(): BelongsTo
    {
        return $this->belongsTo(ShiftProductionEntry::class);
    }

    /*
     * `intended_shift_production_entry_id` EXISTS ON THIS TABLE AND IS NEVER
     * WRITTEN OR READ.
     *
     * It was added, shipped, and withdrawn within the same day. The idea was to
     * record which batch the operator was loading for — but the factory's flow
     * has one common resin input with NO bag-to-batch assignment and no
     * bag-to-batch intent, and a column holding "which batch this bag was for"
     * rebuilds exactly the claim that flow exists to prevent, however carefully
     * it is labelled. Several runs draw from the pool; cost comes from the
     * pool's weighted average.
     *
     * Left in place, nullable and unused, rather than dropped: the column is
     * already live, every row in it is null, and dropping a column on a
     * production table to tidy up is a bigger risk than an unused one. It is
     * absent from $fillable so nothing can write it by accident, and it has no
     * relation, so nothing can read it into a screen.
     */

    /**
     * Read-only cross-module relation — bag-state writes stay behind
     * Inventory's TraceabilityService.
     */
    public function materialBag(): BelongsTo
    {
        return $this->belongsTo(MaterialBag::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
