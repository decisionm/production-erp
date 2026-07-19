<?php

namespace App\Modules\Production\Http\Resources;

use App\Modules\Inventory\Http\Resources\ItemResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoutingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'name' => $this->name,
            'is_active' => $this->is_active,
            'operations' => RoutingOperationResource::collection($this->whenLoaded('operations')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
