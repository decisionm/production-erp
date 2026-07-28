<?php

namespace App\Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaterialBagResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'material_lot_id' => $this->material_lot_id,
            'lot' => MaterialLotResource::make($this->whenLoaded('lot')),
            'barcode' => $this->barcode,
            'original_kg' => $this->original_kg,
            'remaining_kg' => $this->remaining_kg,
            'status' => $this->status->value,
            'current_warehouse_id' => $this->current_warehouse_id,
            'day_bin_work_center_id' => $this->day_bin_work_center_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
