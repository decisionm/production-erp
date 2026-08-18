<?php

namespace App\Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // A receipt movement's unit_cost IS the GRN purchase rate, and every
        // later issue carries the weighted average of those rates — Owner/
        // Accounts data (FC-06). Same gate, same omit-not-null rule as
        // MaterialLotResource (see its class note): a null cost is a real
        // state (opening stock), so it must be ABSENT, never nulled, for
        // anyone else. Served open, the item history handed the rate to any
        // inventory viewer while the lot and GRN line were hiding it.
        $showsCost = $request->user()?->hasAnyPermission(['finance.view', 'finance.manage']) ?? false;

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'warehouse' => WarehouseResource::make($this->whenLoaded('warehouse')),
            'batch' => BatchResource::make($this->whenLoaded('batch')),
            'serial_number' => SerialNumberResource::make($this->whenLoaded('serialNumber')),
            'quantity' => $this->quantity,
            ...($showsCost ? ['unit_cost' => $this->unit_cost] : []),
            'reference' => $this->reference,
            'transfer_group' => $this->transfer_group,
            'movement_date' => $this->movement_date?->toIso8601String(),
            'notes' => $this->notes,
        ];
    }
}
