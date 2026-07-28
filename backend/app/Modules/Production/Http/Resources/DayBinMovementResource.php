<?php

namespace App\Modules\Production\Http\Resources;

use App\Modules\Core\Http\Resources\UserResource;
use App\Modules\Inventory\Http\Resources\ItemResource;
use App\Modules\Inventory\Http\Resources\MaterialBagResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DayBinMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_center' => WorkCenterResource::make($this->whenLoaded('workCenter')),
            'work_center_id' => $this->work_center_id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'item_id' => $this->item_id,
            'shift_production_entry_id' => $this->shift_production_entry_id,
            'type' => $this->type->value,
            'material_bag' => MaterialBagResource::make($this->whenLoaded('materialBag')),
            'material_bag_id' => $this->material_bag_id,
            'quantity_kg' => $this->quantity_kg,
            'recorded_by' => UserResource::make($this->whenLoaded('recordedBy')),
            'recorded_at' => $this->recorded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
