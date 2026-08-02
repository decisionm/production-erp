<?php

namespace App\Modules\Quality\Models;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use App\Modules\Quality\Models\Enums\InspectionResult;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'goods_receipt_note_line_id', 'item_id', 'inspected_quantity', 'accepted_quantity',
    'rejected_quantity', 'result', 'inspection_date', 'inspected_by', 'notes',
    'rejections_out_reference', 'bag_disposition_note',
])]
class IncomingInspection extends Model
{
    protected function casts(): array
    {
        return [
            'result' => InspectionResult::class,
            'inspected_quantity' => 'decimal:4',
            'accepted_quantity' => 'decimal:4',
            'rejected_quantity' => 'decimal:4',
            'inspection_date' => 'date',
        ];
    }

    public function nonConformanceReports(): HasMany
    {
        return $this->hasMany(NonConformanceReport::class);
    }

    public function goodsReceiptNoteLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptNoteLine::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function inspectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }
}
