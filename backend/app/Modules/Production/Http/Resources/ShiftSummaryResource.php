<?php

namespace App\Modules\Production\Http\Resources;

use App\Modules\HRMS\Http\Resources\EmployeeResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shift' => ShiftResource::make($this->whenLoaded('shift')),
            'production_date' => $this->production_date?->toDateString(),
            'supervisor' => EmployeeResource::make($this->whenLoaded('supervisor')),
            'target_production_kg' => $this->target_production_kg,
            'power_consumption_units' => $this->power_consumption_units,
            'remarks' => $this->remarks,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
