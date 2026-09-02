<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['purchase_order_id', 'item_id', 'quantity', 'unit_price', 'quantity_received', 'unclassified_reason'])]
class PurchaseOrderLine extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'quantity_received' => 'decimal:4',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** Item/due-date delivery windows, oldest due first — the GRN allocation order. */
    public function schedules(): HasMany
    {
        return $this->hasMany(PurchaseOrderSchedule::class)->orderBy('due_date')->orderBy('id');
    }
}
