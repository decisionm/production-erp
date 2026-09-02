<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['purchase_requisition_id', 'item_id', 'quantity', 'notes', 'unclassified_reason'])]
class PurchaseRequisitionLine extends Model
{
    /**
     * Read-side decoration set by PurchaseRequisitionService (from
     * RequisitionCoverageService) and read by PurchaseRequisitionLineResource
     * — the PurchaseOrder::$tallyLink / $can pattern: a plain property, never
     * an attribute; not persisted, not in toArray(), null on a bare model.
     *
     * {requested_quantity, ordered_quantity, balance_quantity, order_status}
     * — how much of THIS line has been answered by the purchase orders raised
     * from its requisition. Null means "not looked up", which the resource
     * reports as an absent key rather than as zero: a line that has been
     * ordered in full and a line nobody computed must never print the same.
     *
     * @var array{requested_quantity: string, ordered_quantity: string, balance_quantity: string, order_status: string}|null
     */
    public ?array $coverage = null;

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
