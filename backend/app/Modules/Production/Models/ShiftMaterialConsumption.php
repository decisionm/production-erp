<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['shift_production_entry_id', 'item_id', 'warehouse_id', 'quantity_issued_kg', 'substitutes_item_id', 'substitution_reason', 'created_by'])]
class ShiftMaterialConsumption extends Model
{
    /**
     * A line IS a substitution when it names what it stood in for — the same
     * predicate StoreIssueLine uses, so both surfaces answer the question the
     * same way (DEC-20260901-004).
     */
    public function isSubstitution(): bool
    {
        return $this->substitutes_item_id !== null;
    }

    /** The item this line stood in for. */
    public function substitutesItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'substitutes_item_id');
    }

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
}
