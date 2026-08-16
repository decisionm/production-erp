<?php

namespace App\Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockBalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // average_cost is the weighted average of the purchase rates received
        // into this balance — Owner/Accounts data (FC-06). Same gate, same
        // omit-not-null rule as MaterialLotResource (see its class note);
        // the key is ABSENT, not nulled, for anyone without finance access.
        $showsCost = $request->user()?->hasAnyPermission(['finance.view', 'finance.manage']) ?? false;

        return [
            'id' => $this->id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'warehouse' => WarehouseResource::make($this->whenLoaded('warehouse')),
            'quantity' => $this->quantity,
            ...($showsCost ? ['average_cost' => $this->average_cost] : []),
        ];
    }
}
