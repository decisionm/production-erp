<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\MaterialLot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One slice of one batch's resin consumption, drawn from one bag-load
 * layer at that bag's own preserved rate. Written only by
 * BagCostAllocationService — see that class and the table's migration for
 * the whole rationale.
 *
 * APPEND-ONLY. There is no updated_at because a row is written once; the
 * single mutation it ever receives is reversed_at being stamped, which is
 * the audit record of a correction, not an edit of the figures.
 */
#[Fillable([
    'shift_production_entry_id', 'allocation_run', 'day_bin_movement_id',
    'material_bag_id', 'material_lot_id', 'item_id', 'quantity_kg',
    'rate_per_kg', 'amount', 'rate_source', 'reversed_at', 'created_by',
])]
class BatchResinAllocation extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'allocation_run' => 'integer',
            'quantity_kg' => 'decimal:4',
            'rate_per_kg' => 'decimal:4',
            'amount' => 'decimal:4',
            'reversed_at' => 'datetime',
        ];
    }

    /**
     * The rows that COUNT — a reversed row is history, never arithmetic.
     * Every quantity and cost read in this module goes through this scope,
     * so "reversed" can never be forgotten at one call site.
     *
     * @param  Builder<BatchResinAllocation>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('reversed_at');
    }

    public function shiftProductionEntry(): BelongsTo
    {
        return $this->belongsTo(ShiftProductionEntry::class);
    }

    /** The Load layer this slice was drawn from; null = beyond-layers fallback. */
    public function dayBinMovement(): BelongsTo
    {
        return $this->belongsTo(DayBinMovement::class);
    }

    /**
     * Read-only cross-module relations — bag and lot writes stay behind
     * Inventory's own services, exactly as DayBinMovement::materialBag does.
     */
    public function materialBag(): BelongsTo
    {
        return $this->belongsTo(MaterialBag::class);
    }

    public function materialLot(): BelongsTo
    {
        return $this->belongsTo(MaterialLot::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
