<?php

namespace App\Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            // 'tally' = a read-only mirror of the order living in Tally.
            'source' => $this->source ?? 'erp',
            'tally_order_no' => $this->tally_order_no,
            'vendor' => VendorResource::make($this->whenLoaded('vendor')),
            'purchase_requisition_id' => $this->purchase_requisition_id,
            'order_date' => $this->order_date?->toDateString(),
            'expected_date' => $this->expected_date?->toDateString(),
            'notes' => $this->notes,
            'lines' => PurchaseOrderLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
