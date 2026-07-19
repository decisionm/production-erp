<?php

namespace App\Modules\Production\Http\Resources;

use App\Modules\Inventory\Http\Resources\ItemResource;
use App\Modules\Inventory\Http\Resources\WarehouseResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'bom_id' => $this->bom_id,
            'routing_id' => $this->routing_id,
            'warehouse' => WarehouseResource::make($this->whenLoaded('warehouse')),
            'quantity_planned' => $this->quantity_planned,
            'quantity_completed' => $this->quantity_completed,
            'material_cost' => $this->material_cost,
            'status' => $this->status->value,
            'materials' => WorkOrderMaterialResource::collection($this->whenLoaded('materials')),
            'released_at' => $this->released_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
