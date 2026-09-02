<?php

namespace App\Modules\Procurement\Http\Resources;

use App\Modules\Inventory\Http\Resources\ItemResource;
use App\Modules\Procurement\Models\PurchaseRequisitionLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One requisition line, and — new — how much of it has been ordered.
 *
 *   requested_quantity  what this line asked for (the same figure `quantity`
 *                       has always carried, named for what it is now that it
 *                       stands beside three others)
 *   ordered_quantity    what the purchase orders raised from this requisition
 *                       have ordered of this item, shared out across lines
 *                       that repeat an item (RequisitionCoverageService)
 *   balance_quantity    still to order; never below zero
 *   order_status        not_ordered | partially_ordered | fully_ordered
 *
 * `quantity` is kept, unchanged and first: every existing reader — the
 * drawer, the Raise-PO prefill, the exports — asks for it by that name, and
 * a rename would be a breaking change bought for nothing.
 *
 * The four keys are ABSENT, not null, when the service did not decorate the
 * line (a caller that built a resource off a bare model). Absent says "not
 * computed"; a zero would say "nothing is ordered", and those are different
 * facts about a purchase.
 *
 * NO UOM IS PRINTED HERE — `item.uom` already carries it, and the quantities
 * above all belong to that one item. Nothing in this file, or anywhere that
 * reads it, adds two items' quantities together (RequisitionCoverageService's
 * class note: one item is in Kgs, the next in Nos).
 */
class PurchaseRequisitionLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PurchaseRequisitionLine $line */
        $line = $this->resource;

        return [
            'id' => $this->id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'quantity' => $this->quantity,
            'notes' => $this->notes,
            'unclassified_reason' => $this->unclassified_reason,
            ...($line->coverage ?? []),
        ];
    }
}
