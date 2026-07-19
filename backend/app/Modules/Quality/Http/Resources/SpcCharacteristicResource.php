<?php

namespace App\Modules\Quality\Http\Resources;

use App\Modules\Inventory\Http\Resources\ItemResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpcCharacteristicResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'name' => $this->name,
            'unit_of_measure' => $this->unit_of_measure,
            'target_value' => $this->target_value,
            'lower_spec_limit' => $this->lower_spec_limit,
            'upper_spec_limit' => $this->upper_spec_limit,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
