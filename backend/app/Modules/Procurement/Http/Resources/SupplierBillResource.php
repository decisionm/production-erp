<?php

namespace App\Modules\Procurement\Http\Resources;

use App\Modules\Procurement\Models\SupplierBill;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierBillResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var SupplierBill $bill */
        $bill = $this->resource;

        return [
            'id' => $this->id,
            'document_number' => $bill->documentNumber(),
            'status' => $this->status->value,
            'vendor' => $this->whenLoaded('vendor', fn () => [
                'id' => $this->vendor->id,
                'code' => $this->vendor->code,
                'name' => $this->vendor->name,
            ]),
            'purchase_order_id' => $this->purchase_order_id,
            'bill_number' => $this->bill_number,
            'bill_date' => $this->bill_date?->toDateString(),
            'purchase_ledger_name' => $this->purchase_ledger_name,
            'subtotal' => $this->subtotal,
            'cgst' => $this->cgst,
            'sgst' => $this->sgst,
            'igst' => $this->igst,
            'rounding' => $this->rounding,
            'total' => $this->total,
            'attachment_name' => $this->attachment_name,
            'has_attachment' => $this->attachment_path !== null,
            'notes' => $this->notes,
            'cancelled_reason' => $this->cancelled_reason,
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'recorded_by' => $this->whenLoaded('recordedBy', fn () => $this->recordedBy?->name),
            'recorded_at' => $this->recorded_at?->toIso8601String(),
            'lines' => SupplierBillLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
