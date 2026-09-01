<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['shift_production_entry_id', 'item_id', 'warehouse_id', 'quantity_issued_kg', 'added_reason', 'added_by', 'created_by'])]
class ShiftMaterialConsumption extends Model
{
    public function shiftProductionEntry(): BelongsTo
    {
        return $this->belongsTo(ShiftProductionEntry::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Who authorised a line the run was not planned on. Null on every
     * ordinary line — an expected material needs nobody's authority.
     */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /** Is this a line the run was not planned on? */
    public function isAddedLine(): bool
    {
        return $this->added_reason !== null;
    }
}
