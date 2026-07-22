<?php

namespace App\Modules\Production\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PowerInterruptionLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shift' => ShiftResource::make($this->whenLoaded('shift')),
            'production_date' => $this->production_date?->toDateString(),
            'from_time' => $this->from_time?->toIso8601String(),
            'to_time' => $this->to_time?->toIso8601String(),
            'idle_hours' => $this->idle_hours,
        ];
    }
}
