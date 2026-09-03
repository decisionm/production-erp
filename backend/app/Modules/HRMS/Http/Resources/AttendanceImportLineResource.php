<?php

namespace App\Modules\HRMS\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One employee-day of a punch-report import. Times are the report's
 * wall-clock `HH:MM` (IST) — never an instant, so the screen shows what
 * the machine printed.
 */
class AttendanceImportLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attendance_import_id' => $this->attendance_import_id,
            'employee_id' => $this->employee_id,
            'employee_code' => $this->employee_code,
            'employee_name' => $this->employee_name,
            'employee' => $this->when($this->relationLoaded('employee') && $this->employee, fn () => [
                'id' => $this->employee->id,
                'name' => $this->employee->name,
                'department' => $this->employee->department,
                'designation' => $this->employee->designation,
            ]),
            'date' => $this->date?->toDateString(),
            'raw_status' => $this->raw_status,
            'first_in' => self::clock($this->first_in),
            'last_out' => self::clock($this->last_out),
            'ot_minutes' => $this->ot_minutes,
            'late_minutes' => $this->late_minutes,
            'early_minutes' => $this->early_minutes,
            'worked_minutes' => $this->worked_minutes,
            'issue' => $this->issue?->value,
            'resolution' => $this->resolution?->value,
            'resolved_check_in' => self::clock($this->resolved_check_in),
            'resolved_check_out' => self::clock($this->resolved_check_out),
            'resolved_by' => $this->when($this->relationLoaded('resolver') && $this->resolver, fn () => [
                'id' => $this->resolver->id,
                'name' => $this->resolver->name,
            ]),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            // Set when the punch app changed its own figures for a day
            // somebody had already answered — the answer stands, this asks
            // for a second look.
            'report_changed_at' => $this->report_changed_at?->toIso8601String(),
            'notes' => $this->notes,
            'applied_at' => $this->applied_at?->toIso8601String(),
        ];
    }

    /** A TIME column ("10:10:00") as the wire's "10:10". */
    public static function clock(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }
}
