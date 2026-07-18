<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['purchase_requisition_id', 'item_id', 'quantity', 'notes'])]
class PurchaseRequisitionLine extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
        ];
    }

    public function purchaseRequisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
