<?php

namespace App\Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SerialNumberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'serial_number' => $this->serial_number,
            'status' => $this->status->value,
            'warehouse' => WarehouseResource::make($this->whenLoaded('warehouse')),
            'movements' => StockMovementResource::collection($this->whenLoaded('movements')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
