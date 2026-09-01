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
            // A line the run was not planned on says so on its own row. Null
            // on every ordinary line — an expected material needs no reason
            // and nobody's authority.
            'added_reason' => $this->added_reason,
            'added_by' => $this->added_by === null ? null : (int) $this->added_by,
            // What it stood in for, where it stood in for something. A
            // substitution reads as one for the life of the record.
            'substitutes_item_id' => $this->substitutes_item_id === null ? null : (int) $this->substitutes_item_id,
            'is_substitution' => $this->substitutes_item_id !== null,
        ];
    }
}
