<?php

namespace App\Modules\Production\Http\Resources;

use App\Modules\Inventory\Http\Resources\ItemResource;
use App\Modules\Inventory\Http\Resources\WarehouseResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReworkOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'source_work_order_id' => $this->source_work_order_id,
            'bom_id' => $this->bom_id,
            'warehouse' => WarehouseResource::make($this->whenLoaded('warehouse')),
            'quantity_input' => $this->quantity_input,
            'quantity_recovered' => $this->quantity_recovered,
            'material_cost' => $this->material_cost,
            'labor_cost' => $this->labor_cost,
            'total_cost' => $this->total_cost,
            'status' => $this->status->value,
            'materials' => ReworkOrderMaterialResource::collection($this->whenLoaded('materials')),
            'released_at' => $this->released_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
