<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Sales\Models\Delivery;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One physical packed carton with a permanent scannable identity. Born from
 * a completed batch's packed count, dispatched by scan, and traceable back
 * to its batch (machine, date, shift, resin allocation) for the life of the
 * record.
 */
#[Fillable([
    'shift_production_entry_id', 'carton_no', 'item_id', 'pieces',
    'is_partial', 'status', 'delivery_id', 'created_by',
])]
class FinishedCarton extends Model
{
    public const STATUS_IN_STOCK = 'in_stock';

    public const STATUS_DISPATCHED = 'dispatched';

    protected function casts(): array
    {
        return [
            'pieces' => 'decimal:4',
            'is_partial' => 'boolean',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(ShiftProductionEntry::class, 'shift_production_entry_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
