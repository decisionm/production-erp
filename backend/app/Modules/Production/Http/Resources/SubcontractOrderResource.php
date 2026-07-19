<?php

namespace App\Modules\Production\Http\Resources;

use App\Modules\Inventory\Http\Resources\ItemResource;
use App\Modules\Inventory\Http\Resources\WarehouseResource;
use App\Modules\Procurement\Http\Resources\VendorResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubcontractOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vendor' => VendorResource::make($this->whenLoaded('vendor')),
            'item' => ItemResource::make($this->whenLoaded('item')),
            'bom_id' => $this->bom_id,
            'warehouse' => WarehouseResource::make($this->whenLoaded('warehouse')),
            'quantity_planned' => $this->quantity_planned,
            'quantity_received' => $this->quantity_received,
            'materials_cost' => $this->materials_cost,
            'service_cost' => $this->service_cost,
            'total_cost' => $this->total_cost,
            'status' => $this->status->value,
            'materials' => SubcontractOrderMaterialResource::collection($this->whenLoaded('materials')),
            'materials_sent_at' => $this->materials_sent_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
