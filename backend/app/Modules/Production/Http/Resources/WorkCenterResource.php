<?php

namespace App\Modules\Production\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkCenterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'capacity_class' => $this->capacity_class,
            'min_cavities' => $this->min_cavities,
            'max_cavities' => $this->max_cavities,
            'permitted_cavities' => $this->permitted_cavities,
            'cycle_time_min' => $this->cycle_time_min,
            'cycle_time_max' => $this->cycle_time_max,
            'default_shift_hours' => $this->default_shift_hours,
            'confirmation_status' => $this->confirmation_status,
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'display_sequence' => $this->display_sequence,
            'capacity_hours_per_day' => $this->capacity_hours_per_day,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
