<?php

namespace App\Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaterialLotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'grn_id' => $this->grn_id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'supplier_lot_no' => $this->supplier_lot_no,
            'received_date' => $this->received_date?->toDateString(),
            'bag_count' => $this->bag_count,
            'bag_weight_kg' => $this->bag_weight_kg,
            'total_received_kg' => $this->total_received_kg,
            'bags' => MaterialBagResource::collection($this->whenLoaded('bags')),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
