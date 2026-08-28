<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['supplier_bill_id', 'goods_receipt_note_line_id', 'item_id', 'quantity', 'rate', 'amount'])]
class SupplierBillLine extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'rate' => 'decimal:4',
            'amount' => 'decimal:4',
        ];
    }

    public function supplierBill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class);
    }

    public function goodsReceiptNoteLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptNoteLine::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
