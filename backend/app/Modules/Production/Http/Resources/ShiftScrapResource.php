<?php

namespace App\Modules\Production\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftScrapResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'quantity_nos' => $this->quantity_nos,
            'quantity_kg' => $this->quantity_kg,
            'scrap_reason' => ScrapReasonResource::make($this->whenLoaded('scrapReason')),
        ];
    }
}
