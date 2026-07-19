<?php

namespace App\Modules\Maintenance\Http\Resources;

use App\Modules\Inventory\Http\Resources\ItemResource;
use App\Modules\Inventory\Http\Resources\WarehouseResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaintenanceWorkOrderPartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'warehouse' => WarehouseResource::make($this->whenLoaded('warehouse')),
            'quantity' => $this->quantity,
            'unit_cost' => $this->unit_cost,
        ];
    }
}
