<?php

namespace App\Modules\Maintenance\Services;

use App\Modules\Maintenance\Models\Enums\MaintenanceWorkOrderStatus;
use App\Modules\Maintenance\Models\Enums\MaintenanceWorkOrderType;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;

/**
 * MTTR/MTBF are derived analytics over already-modeled timestamps, not
 * money or stock quantity — plain float hours (not bcmath) is the right
 * tool here, same as any other duration-based report figure.
 */
class MaintenanceReportService
{
    public function reliability(int $assetId): array
    {
        $completed = MaintenanceWorkOrder::query()
            ->where('asset_id', $assetId)
            ->where('status', MaintenanceWorkOrderStatus::Completed)
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->get();

        $mttrHours = $completed->isEmpty()
            ? null
            : round($completed->avg(fn (MaintenanceWorkOrder $wo) => $wo->started_at->diffInMinutes($wo->completed_at)) / 60, 2);

        $breakdowns = MaintenanceWorkOrder::query()
            ->where('asset_id', $assetId)
            ->where('type', MaintenanceWorkOrderType::Corrective)
            ->orderBy('reported_date')
            ->get();

        $mtbfHours = null;
        if ($breakdowns->count() >= 2) {
            $gaps = [];
            for ($i = 1; $i < $breakdowns->count(); $i++) {
                $gaps[] = $breakdowns[$i - 1]->reported_date->diffInHours($breakdowns[$i]->reported_date);
            }
            $mtbfHours = round(array_sum($gaps) / count($gaps), 2);
        }

        return [
            'asset_id' => $assetId,
            'completed_work_orders' => $completed->count(),
            'breakdown_count' => $breakdowns->count(),
            'mttr_hours' => $mttrHours,
            'mtbf_hours' => $mtbfHours,
        ];
    }
}
