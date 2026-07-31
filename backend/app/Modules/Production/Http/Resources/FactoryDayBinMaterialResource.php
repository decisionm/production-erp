<?php

namespace App\Modules\Production\Http\Resources;

use App\Modules\Inventory\Http\Resources\ItemResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One material's balance in the factory day bin. Wraps an Inventory
 * StockBalance row — the day bin is a warehouse, so its content is ordinary
 * stock, not a parallel figure.
 *
 * `quantity_kg` is the same 4dp decimal string the rest of the shift engine
 * speaks; the item's own `uom` says what the unit actually is (resin is Kg,
 * caps are Nos).
 */
class FactoryDayBinMaterialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'item' => ItemResource::make($this->whenLoaded('item')),
            'item_id' => $this->item_id,
            'quantity_kg' => $this->quantity,
            'average_cost' => $this->average_cost,
        ];
    }
}
