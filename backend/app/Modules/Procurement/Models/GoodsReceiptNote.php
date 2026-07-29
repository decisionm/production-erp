<?php

namespace App\Modules\Procurement\Models;

use App\Models\User;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'receipt_key', 'receipt_payload_hash', 'purchase_order_id', 'warehouse_id',
    'reference', 'received_date', 'notes', 'created_by',
])]
class GoodsReceiptNote extends Model
{
    protected function casts(): array
    {
        return [
            'received_date' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptNoteLine::class);
    }

    public function materialLots(): HasMany
    {
        return $this->hasMany(MaterialLot::class, 'grn_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
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
