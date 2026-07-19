<?php

namespace App\Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'batch_number' => $this->batch_number,
            'manufactured_date' => $this->manufactured_date?->toDateString(),
            'expiry_date' => $this->expiry_date?->toDateString(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
