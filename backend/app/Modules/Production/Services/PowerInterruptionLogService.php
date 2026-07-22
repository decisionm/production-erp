<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\PowerInterruptionLog;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Single-shot, plant-wide — logged after the fact once power is back, so
 * unlike downtime/mold-change there's no open/closed lifecycle: both
 * timestamps are known at creation, idle_hours is computed from them.
 */
class PowerInterruptionLogService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return PowerInterruptionLog::query()
            ->with(['shift', 'createdBy'])
            ->orderByDesc('production_date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  array{shift_id: int, production_date?: string, from_time: string, to_time: string}  $data
     */
    public function create(array $data, ?int $createdBy): PowerInterruptionLog
    {
        $fromTime = Carbon::parse($data['from_time']);
        $toTime = Carbon::parse($data['to_time']);
        $idleHours = bcdiv((string) $fromTime->diffInSeconds($toTime), '3600', 2);

        $log = PowerInterruptionLog::create([
            'shift_id' => $data['shift_id'],
            'production_date' => $data['production_date'] ?? now()->toDateString(),
            'from_time' => $fromTime,
            'to_time' => $toTime,
            'idle_hours' => $idleHours,
            'created_by' => $createdBy,
        ]);

        return $log->fresh(['shift']);
    }
}
