<?php

namespace App\Modules\HRMS\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One person's month in a punch-report run: who they are, how many days
 * still need an answer, and the day-by-day states the review strip draws.
 * Wraps a plain array from AttendanceImportService::employeeSummary().
 *
 * @property array<string, mixed> $resource
 */
class AttendanceImportEmployeeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'employee_code' => $this->resource['employee_code'],
            'employee_name' => $this->resource['employee_name'],
            'employee_id' => $this->resource['employee_id'],
            'known' => $this->resource['known'],
            'department' => $this->resource['department'],
            'designation' => $this->resource['designation'],
            'day_count' => $this->resource['day_count'],
            'open_count' => $this->resource['open_count'],
            'resolved_count' => $this->resource['resolved_count'],
            'clean_count' => $this->resource['clean_count'],
            'days' => $this->resource['days'],
        ];
    }
}
