<?php

namespace App\Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseRequisitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'requested_by' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy?->name),
            'needed_by_date' => $this->needed_by_date?->toDateString(),
            'notes' => $this->notes,
            'lines' => PurchaseRequisitionLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
