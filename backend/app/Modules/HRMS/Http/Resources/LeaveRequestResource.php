<?php

namespace App\Modules\HRMS\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveRequestResource extends JsonResource
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
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'days' => $this->days,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'approved_by' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy?->name),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
