<?php

namespace App\Modules\Production\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MachineDowntimeLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_center' => WorkCenterResource::make($this->whenLoaded('workCenter')),
            'shift' => ShiftResource::make($this->whenLoaded('shift')),
            'production_date' => $this->production_date?->toDateString(),
            'nature_of_problem' => $this->nature_of_problem,
            'remedy' => $this->remedy,
            'parts_changed' => $this->parts_changed,
            'from_time' => $this->from_time?->toIso8601String(),
            'to_time' => $this->to_time?->toIso8601String(),
            'total_minutes' => $this->total_minutes,
            'status' => $this->status->value,
        ];
    }
}
