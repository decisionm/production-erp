<?php

namespace App\Modules\Production\Http\Resources;

use App\Modules\Inventory\Http\Resources\ItemResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftStockCountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shift' => ShiftResource::make($this->whenLoaded('shift')),
            'production_date' => $this->production_date?->toDateString(),
            'location_label' => $this->location_label,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'quantity_kg' => $this->quantity_kg,
        ];
    }
}
