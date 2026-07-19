<?php

namespace App\Modules\Maintenance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaintenanceScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset' => AssetResource::make($this->whenLoaded('asset')),
            'name' => $this->name,
            'frequency_days' => $this->frequency_days,
            'next_due_date' => $this->next_due_date?->toDateString(),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
