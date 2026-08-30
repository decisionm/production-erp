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
            // THE FOUR FIGURES a storekeeper actually needs, attached by the
            // controller for the page it just read. Quantities only — no rate
            // may ride along here (FC-06).
            //
            // `free_to_issue` subtracts the QC hold AND customer reservations,
            // which is STRICTER than the write path (that consults only the
            // hold). The owner ruled on 31-Aug that the screen must
            // under-report rather than let a storekeeper give away promised
            // stock by accident; the components sit beside it so nothing is
            // hidden.
            ...($this->stock_state === null ? [] : ['state' => $this->stock_state]),
            ...($showsCost ? ['average_cost' => $this->average_cost] : []),
        ];
    }
}
