<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One supplier lot on a GRN (Phase 6 traceability chain: GRN → lot → bag →
 * day bin → batch). Lives in Inventory because it IS raw-material stock
 * detail — the store-side identity of what stock_movements track in
 * aggregate. All writes go through TraceabilityService.
 */
#[Fillable([
    'grn_id', 'goods_receipt_note_line_id', 'item_id', 'supplier_lot_no',
    'received_date', 'bag_count', 'bag_weight_kg', 'total_received_kg',
    'notes', 'created_by',
])]
class MaterialLot extends Model
{
    protected function casts(): array
    {
        return [
            'received_date' => 'date',
            'bag_count' => 'integer',
            'bag_weight_kg' => 'decimal:4',
            'total_received_kg' => 'decimal:4',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * Read-only cross-module relation (same precedent as
     * ShiftProductionEntry→Item): GRN writes stay in Procurement.
     */
    public function grn(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptNote::class, 'grn_id');
    }

    public function goodsReceiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptNoteLine::class, 'goods_receipt_note_line_id');
    }

    public function bags(): HasMany
    {
        return $this->hasMany(MaterialBag::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
