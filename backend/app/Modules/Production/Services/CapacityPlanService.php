<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\Enums\WorkOrderStatus;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Models\WorkOrder;
use Carbon\CarbonPeriod;

/**
 * Deliberately single-day granularity: a work order's entire routing load
 * (standard time x quantity planned, summed per work center) is charged
 * to its scheduled_date in full, not spread across however many days the
 * job would actually take. This is a load-vs-capacity check, not a finite
 * scheduling engine — it tells you which work centers are over capacity on
 * a given day, not when a job will actually finish or how to sequence it.
 * Only work orders still ahead of you (draft/released) count as load;
 * completed work is done, not upcoming demand.
 */
class CapacityPlanService
{
    public function loadReport(string $startDate, string $endDate): array
    {
        $workCenters = WorkCenter::query()->where('is_active', true)->orderBy('code')->get();

        $workOrders = WorkOrder::query()
            ->whereIn('status', [WorkOrderStatus::Draft, WorkOrderStatus::Released])
            ->whereNotNull('scheduled_date')
            ->whereNotNull('routing_id')
            ->whereBetween('scheduled_date', [$startDate, $endDate])
            ->with('routing.operations')
            ->get();

        $loadByWorkCenterAndDate = [];
        foreach ($workOrders as $workOrder) {
            $dateKey = $workOrder->scheduled_date->toDateString();

            foreach ($workOrder->routing->operations as $operation) {
                if ($operation->standard_time_minutes === null) {
                    continue;
                }

                $hours = bcdiv(
                    bcmul((string) $operation->standard_time_minutes, (string) $workOrder->quantity_planned, 4),
                    '60',
                    4,
                );

                $key = "{$operation->work_center_id}|{$dateKey}";
                $loadByWorkCenterAndDate[$key] = bcadd($loadByWorkCenterAndDate[$key] ?? '0', $hours, 4);
            }
        }

        $dates = collect(CarbonPeriod::create($startDate, $endDate))->map(fn ($date) => $date->toDateString());

        return $workCenters->map(function (WorkCenter $workCenter) use ($dates, $loadByWorkCenterAndDate) {
            $capacity = $workCenter->capacity_hours_per_day !== null ? (string) $workCenter->capacity_hours_per_day : null;

            $days = $dates->map(function (string $date) use ($workCenter, $capacity, $loadByWorkCenterAndDate) {
                $loadHours = $loadByWorkCenterAndDate["{$workCenter->id}|{$date}"] ?? '0.0000';

                return [
                    'date' => $date,
                    'load_hours' => $loadHours,
                    'capacity_hours' => $capacity,
                    'utilization_percent' => $capacity !== null && bccomp($capacity, '0', 4) > 0
                        ? round(((float) $loadHours / (float) $capacity) * 100, 1)
                        : null,
                    'overloaded' => $capacity !== null && bccomp($loadHours, $capacity, 4) > 0,
                ];
            })->values()->all();

            return [
                'work_center_id' => $workCenter->id,
                'work_center_code' => $workCenter->code,
                'work_center_name' => $workCenter->name,
                'capacity_hours_per_day' => $capacity,
                'days' => $days,
            ];
        })->values()->all();
    }
}
