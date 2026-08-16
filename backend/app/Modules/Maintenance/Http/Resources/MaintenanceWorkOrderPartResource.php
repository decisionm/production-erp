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
        // A part is drawn from stock at its purchase rate (the issue
        // movement's unit_cost) — Owner/Accounts data (FC-06). Same gate,
        // same omit-not-null rule as MaterialLotResource (see its class
        // note); ABSENT, not nulled, for anyone without finance access.
        $showsCost = $request->user()?->hasAnyPermission(['finance.view', 'finance.manage']) ?? false;

        return [
            'id' => $this->id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'warehouse' => WarehouseResource::make($this->whenLoaded('warehouse')),
            'quantity' => $this->quantity,
            ...($showsCost ? ['unit_cost' => $this->unit_cost] : []),
        ];
    }
}
