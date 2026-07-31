<?php

namespace App\Modules\Production\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One choice in the Day Bin page's raw-material picker: an active kg-uom
 * item and its current store kg. Deliberately slim — the picker needs a
 * label and a "how much is left to load" figure, not the full item master.
 * Wraps the array rows FactoryDayBinService::rawMaterials() builds.
 */
class RawMaterialPickerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this['item']->id,
            'label' => $this['item']->name,
            'uom' => $this['item']->uom,
            'store_kg' => $this['store_kg'],
        ];
    }
}
