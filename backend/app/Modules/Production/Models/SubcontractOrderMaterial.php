<?php

namespace App\Modules\Production\Models;

use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['subcontract_order_id', 'component_item_id', 'quantity_required', 'quantity_sent'])]
class SubcontractOrderMaterial extends Model
{
    public function subcontractOrder(): BelongsTo
    {
        return $this->belongsTo(SubcontractOrder::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'component_item_id');
    }
}
