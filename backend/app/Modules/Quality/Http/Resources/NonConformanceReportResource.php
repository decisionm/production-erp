<?php

namespace App\Modules\Quality\Http\Resources;

use App\Modules\Inventory\Http\Resources\ItemResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NonConformanceReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'incoming_inspection_id' => $this->incoming_inspection_id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'description' => $this->description,
            'severity' => $this->severity->value,
            'status' => $this->status->value,
            'quantity_affected' => $this->quantity_affected,
            'raised_by' => $this->whenLoaded('raisedBy', fn () => $this->raisedBy?->name),
            'raised_date' => $this->raised_date?->toDateString(),
            'resolution' => $this->resolution,
            'closed_date' => $this->closed_date?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
