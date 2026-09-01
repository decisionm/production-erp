<?php

namespace App\Modules\Production\Http\Resources;

use App\Modules\Inventory\Http\Resources\ItemResource;
use App\Modules\Inventory\Http\Resources\WarehouseResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftMaterialConsumptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'warehouse' => WarehouseResource::make($this->whenLoaded('warehouse')),
            'quantity_issued_kg' => $this->quantity_issued_kg,
            // An off-plan line stays legible for the life of the record. The
            // rule is that a substitution is never silent, and a line that
            // reached the floor as one but reads back as an ordinary
            // consumption on every later screen would be exactly that.
            'is_substitution' => (bool) $this->is_substitution,
            'substitution_reason' => $this->substitution_reason,
        ];
    }
}
