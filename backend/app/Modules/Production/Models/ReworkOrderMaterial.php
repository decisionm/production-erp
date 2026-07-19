<?php

namespace App\Modules\Production\Models;

use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['rework_order_id', 'component_item_id', 'quantity_required', 'quantity_issued'])]
class ReworkOrderMaterial extends Model
{
    public function reworkOrder(): BelongsTo
    {
        return $this->belongsTo(ReworkOrder::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'component_item_id');
    }
}
