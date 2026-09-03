<?php

namespace App\Modules\HRMS\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ONE DAY IN THE ATTENDANCE LIST — applied or merely uploaded.
 *
 * The list pages a UNION of two tables that store the same day differently
 * (an instant in UTC on one side, the punch report's own wall clock on the
 * other), so the reconciling is done once in AttendanceService::listedDay
 * where the factory's timezone rule already lives, and this resource is the
 * envelope that keeps the endpoint's shape a resource's job rather than a
 * controller's.
 *
 * @property array<string, mixed> $resource
 */
class AttendanceDayResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
