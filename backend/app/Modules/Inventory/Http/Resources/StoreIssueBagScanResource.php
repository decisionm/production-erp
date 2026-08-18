<?php

namespace App\Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A scanned bag at the handover: identity, kilograms, and the two people.
 *
 * NO RATE, NO AMOUNT, NO SUPPLIER NAME — FC-06. The lot's purchase rate is
 * Owner/Accounts and reaches nobody through this surface; MaterialLotResource
 * is where that gating already lives, and this resource simply never carries
 * the fields. The lot is named by id and supplier lot number, which is what
 * the store reads off the bag itself.
 */
class StoreIssueBagScanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_issue_line_id' => $this->store_issue_line_id,
            'material_request_line_id' => $this->material_request_line_id,
            'material_bag_id' => $this->material_bag_id,
            'barcode' => $this->whenLoaded('bag', fn () => $this->bag?->barcode),
            'material_lot_id' => $this->material_lot_id,
            'supplier_lot_no' => $this->whenLoaded('lot', fn () => $this->lot?->supplier_lot_no),
            'quantity_kg' => (string) $this->quantity_kg,
            'issued_by' => $this->issued_by,
            'issued_by_name' => $this->whenLoaded('issuedBy', fn () => $this->issuedBy?->name),
            'received_by' => $this->received_by,
            'received_by_name' => $this->whenLoaded('receivedBy', fn () => $this->receivedBy?->name),
            'scanned_at' => $this->scanned_at?->toIso8601String(),
            'notes' => $this->notes,
        ];
    }
}
