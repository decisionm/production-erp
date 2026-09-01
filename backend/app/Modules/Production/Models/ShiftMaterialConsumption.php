<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['shift_production_entry_id', 'item_id', 'warehouse_id', 'quantity_issued_kg', 'substitutes_item_id', 'added_reason', 'added_by', 'created_by'])]
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

    /**
     * The material this line STOOD IN FOR, where it stood in for one
     * (DEC-20260901-004). Null on an ordinary line, and null on an added line
     * that replaced nothing — a run may need a consumable that substituted for
     * no planned material, and naming one there would be inventing it.
     */
    public function substitutesItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'substitutes_item_id');
    }

    /**
     * Is this line a SUBSTITUTION — an added line that names what it replaced?
     * The same predicate StoreIssueLine uses, so both points in the flow answer
     * the question the same way.
     */
    public function isSubstitution(): bool
    {
        return $this->substitutes_item_id !== null;
    }
}
