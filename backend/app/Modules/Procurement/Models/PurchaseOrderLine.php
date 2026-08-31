<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['purchase_order_id', 'item_id', 'quantity', 'unit_price', 'quantity_received'])]
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

    /**
     * The arrivals booked against THIS line. The inverse of
     * GoodsReceiptNoteLine::purchaseOrderLine, added so the requisition's
     * coverage can ask what a closed order actually delivered — the owner's
     * rule counts material received AND ACCEPTED BY QUALITY, and Quality's
     * verdict hangs off the receipt line, not off this one.
     */
    public function goodsReceiptNoteLines(): HasMany
    {
        return $this->hasMany(GoodsReceiptNoteLine::class, 'purchase_order_line_id');
    }

    /** Item/due-date delivery windows, oldest due first — the GRN allocation order. */
    public function schedules(): HasMany
    {
        return $this->hasMany(PurchaseOrderSchedule::class)->orderBy('due_date')->orderBy('id');
    }
}
