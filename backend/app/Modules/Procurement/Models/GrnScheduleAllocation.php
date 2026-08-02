<?php

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which schedule window an arrival's quantity was booked against. One GRN
 * line may split across schedules when a single delivery genuinely covers
 * more than one due date; the sum of a line's allocations equals the line.
 */
class GrnScheduleAllocation extends Model
{
    protected $fillable = [
        'goods_receipt_note_line_id', 'purchase_order_schedule_id', 'quantity',
    ];

    protected $casts = ['quantity' => 'decimal:4'];

    public function grnLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptNoteLine::class, 'goods_receipt_note_line_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderSchedule::class, 'purchase_order_schedule_id');
    }
}
