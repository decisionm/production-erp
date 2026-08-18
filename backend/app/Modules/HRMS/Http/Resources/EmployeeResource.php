<?php

namespace App\Modules\HRMS\Http\Resources;

use App\Modules\HRMS\Models\Employee;
use App\Modules\HRMS\Services\EmployeeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Employee $employee */
        $employee = $this->resource;

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
            // Archived (soft-deleted) rows still render — history has to stay
            // readable — and say so, so a list can mark them without guessing
            // from a missing field.
            'is_archived' => $employee->trashed(),
            // The Configuration Lifecycle Contract's `can`: the SAME predicate
            // the actions enforce, so nothing client-side re-derives
            // eligibility. Stamped by the controller on a single record
            // (delete resolved); asked cheaply here otherwise, where `delete`
            // comes back null — undetermined, ask show().
            'can' => $employee->can ?? (
                $request->user()?->hasAnyPermission(['hrms.manage'])
                    ? app(EmployeeService::class)->abilities($employee, resolveDelete: false)
                    // A read-only user is answered FALSE, not null: no amount
                    // of counting would change it (ConfigurationLifecycle's
                    // own reading of the three-valued `delete`).
                    : ['edit' => false, 'activate' => false, 'archive' => false, 'delete' => false]
            ),
        ];
    }
}
