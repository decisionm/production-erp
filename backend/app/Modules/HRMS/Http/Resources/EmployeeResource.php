<?php

namespace App\Modules\HRMS\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_code' => $this->employee_code,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'date_of_joining' => $this->date_of_joining?->toDateString(),
            'designation' => $this->designation,
            'department' => $this->department,
            'status' => $this->status->value,
            'manager' => $this->when(
                $this->relationLoaded('manager') && $this->manager,
                fn () => ['id' => $this->manager->id, 'name' => $this->manager->name],
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
