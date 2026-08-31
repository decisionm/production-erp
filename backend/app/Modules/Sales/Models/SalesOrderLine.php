<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['sales_order_id', 'item_id', 'quantity', 'unit_price', 'quantity_delivered'])]
class SalesOrderLine extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'quantity_delivered' => 'decimal:4',
            'quality_approved_at' => 'datetime',
            'quality_approved_quantity' => 'decimal:4',
        ];
    }

    /**
     * Has Quality signed this line off for dispatch? DEC-20260831-006.
     *
     * The TIMESTAMP is the fact, not the quantity: a line approved for zero is
     * still a line Quality looked at, and `quality_approved_quantity` is what
     * caps the dispatch rather than what proves the approval happened.
     */
    public function isQualityApproved(): bool
    {
        return $this->quality_approved_at !== null;
    }

    /** What Quality signed for — '0.0000' when they have not looked yet. */
    public function qualityApprovedQuantity(): string
    {
        return $this->isQualityApproved() ? (string) $this->quality_approved_quantity : '0.0000';
    }

    /** Who signed it off. Null until they do, and null if that user is later removed. */
    public function qualityApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'quality_approved_by');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
