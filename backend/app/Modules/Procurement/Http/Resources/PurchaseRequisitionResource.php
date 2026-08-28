<?php

namespace App\Modules\Procurement\Http\Resources;

use App\Modules\Procurement\Models\PurchaseRequisition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseRequisitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PurchaseRequisition $requisition */
        $requisition = $this->resource;

        return [
            'id' => $this->id,
            'document_number' => $requisition->documentNumber(),
            'status' => $this->status->value,
            'requested_by' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy?->name),
            // The decision trail (28-Aug audit finding 8). NULL name + NULL
            // instant on a requisition decided before the stamps existed —
            // the page words that honestly rather than inventing an approver.
            'approved_by' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy?->name),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_by' => $this->whenLoaded('rejectedBy', fn () => $this->rejectedBy?->name),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'needed_by_date' => $this->needed_by_date?->toDateString(),
            'notes' => $this->notes,
            'lines' => PurchaseRequisitionLineResource::collection($this->whenLoaded('lines')),
            // The orders raised FROM this requisition — id + status only;
            // the PO list is where an order is read.
            'purchase_orders' => $this->whenLoaded('purchaseOrders', fn () => $this->purchaseOrders
                ->map(fn ($order) => ['id' => $order->id, 'status' => $order->status->value])
                ->values()
                ->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
