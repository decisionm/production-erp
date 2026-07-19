<?php

namespace App\Modules\Production\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderScrapResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reason' => ScrapReasonResource::make($this->whenLoaded('reason')),
            'quantity' => $this->quantity,
            'cost_impact' => $this->cost_impact,
            'notes' => $this->notes,
        ];
    }
}
