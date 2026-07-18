<?php

namespace App\Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'warehouse' => WarehouseResource::make($this->whenLoaded('warehouse')),
            'quantity' => $this->quantity,
            'unit_cost' => $this->unit_cost,
            'reference' => $this->reference,
            'transfer_group' => $this->transfer_group,
            'movement_date' => $this->movement_date?->toIso8601String(),
            'notes' => $this->notes,
        ];
    }
}
