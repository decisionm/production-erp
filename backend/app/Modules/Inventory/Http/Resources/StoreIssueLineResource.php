<?php

namespace App\Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One material on a store issue.
 *
 * THREE QUANTITIES, NAMED SO THEY CANNOT BE CONFUSED:
 *   quantity_issued              what left the store;
 *   quantity_returned            what came back;
 *   quantity_outstanding         what is standing in Production/WIP now.
 *
 * There is no "consumed" figure here and there must not be: consumption is
 * the batch's calculation, and a column for it on the store's paperwork is
 * exactly how "issued" and "consumed" get collapsed back into one thing.
 *
 * quantity_remaining_on_request is the REQUEST's arithmetic — what the
 * request still wants after this issue — computed from the snapshot taken
 * when the line was written, never from a stored counter kept in step by
 * hand.
 */
class StoreIssueLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_issue_id' => $this->store_issue_id,
            'material_request_line_id' => $this->material_request_line_id,
            'item_id' => $this->item_id,
            'item_name' => $this->whenLoaded('item', fn () => $this->item?->name),
            'item_sku' => $this->whenLoaded('item', fn () => $this->item?->sku),
            'uom' => $this->uom,
            'from_warehouse_id' => $this->from_warehouse_id,
            'to_warehouse_id' => $this->to_warehouse_id,
            'quantity_requested' => $this->quantity_requested !== null ? (string) $this->quantity_requested : null,
            'quantity_issued' => (string) $this->quantity_issued,
            'quantity_returned' => (string) $this->quantity_returned,
            'quantity_outstanding' => $this->quantityOutstanding(),
            'quantity_remaining_on_request' => $this->quantityRemainingOnRequest(),
            'notes' => $this->notes,
        ];
    }
}
