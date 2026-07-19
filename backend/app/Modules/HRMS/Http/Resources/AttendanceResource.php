<?php

namespace App\Modules\HRMS\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => $this->when($this->relationLoaded('employee'), fn () => [
                'id' => $this->employee->id,
                'name' => $this->employee->name,
            ]),
            'date' => $this->date?->toDateString(),
            'status' => $this->status->value,
            'check_in' => $this->check_in?->toIso8601String(),
            'check_out' => $this->check_out?->toIso8601String(),
            'notes' => $this->notes,
        ];
    }
}
