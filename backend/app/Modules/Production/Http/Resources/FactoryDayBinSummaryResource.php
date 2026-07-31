<?php

namespace App\Modules\Production\Http\Resources;

use App\Modules\Inventory\Http\Resources\ItemResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One raw material's owner-summary row on the factory day bin read:
 * what is in the bin vs still in the store (Tally-linked warehouses),
 * plus how many registered bags are still holding material. Wraps the
 * array rows FactoryDayBinService::rawMaterialSummary() builds.
 */
class FactoryDayBinSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'item' => ItemResource::make($this['item']),
            'item_id' => $this['item']->id,
            'bin_kg' => $this['bin_kg'],
            'store_kg' => $this['store_kg'],
            'unopened_bags' => $this['unopened_bags'],
        ];
    }
}
