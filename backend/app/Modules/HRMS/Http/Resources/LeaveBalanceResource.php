<?php

namespace App\Modules\HRMS\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveBalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => $this->when($this->relationLoaded('employee'), fn () => [
                'id' => $this->employee->id,
                'name' => $this->employee->name,
            ]),
            'leave_type' => LeaveTypeResource::make($this->whenLoaded('leaveType')),
            'year' => $this->year,
            'allocated_days' => $this->allocated_days,
            'used_days' => $this->used_days,
            'remaining_days' => bcsub($this->allocated_days, $this->used_days, 2),
        ];
    }
}
