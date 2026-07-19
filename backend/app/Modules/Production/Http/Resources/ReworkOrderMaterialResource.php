<?php

namespace App\Modules\Production\Http\Resources;

use App\Modules\Inventory\Http\Resources\ItemResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReworkOrderMaterialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'component' => ItemResource::make($this->whenLoaded('component')),
            'quantity_required' => $this->quantity_required,
            'quantity_issued' => $this->quantity_issued,
        ];
    }
}
