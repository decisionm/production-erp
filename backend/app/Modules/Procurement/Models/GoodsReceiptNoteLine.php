<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['goods_receipt_note_id', 'purchase_order_line_id', 'item_id', 'stock_movement_id', 'quantity', 'unit_cost'])]
class GoodsReceiptNoteLine extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function goodsReceiptNote(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptNote::class);
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * The ONE ledger row this receipt line wrote — stamped at receipt time
     * (Phase 6). NULL on rows booked before the column existed; those are
     * still found by the reference the movement carries, and the trace says
     * which road it took.
     */
    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }

    public function materialLots(): HasMany
    {
        return $this->hasMany(MaterialLot::class, 'goods_receipt_note_line_id');
    }

    public function scheduleAllocations(): HasMany
    {
        return $this->hasMany(GrnScheduleAllocation::class, 'goods_receipt_note_line_id');
    }
}
