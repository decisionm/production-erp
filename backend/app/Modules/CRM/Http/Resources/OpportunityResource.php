<?php

namespace App\Modules\CRM\Http\Resources;

use App\Modules\Sales\Http\Resources\CustomerResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OpportunityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            'lead_id' => $this->lead_id,
            'stage' => $this->stage->value,
            'estimated_value' => $this->estimated_value,
            'probability' => $this->probability,
            'expected_close_date' => $this->expected_close_date?->toDateString(),
            'notes' => $this->notes,
            'assigned_to' => $this->whenLoaded('assignedTo', fn () => $this->assignedTo?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
