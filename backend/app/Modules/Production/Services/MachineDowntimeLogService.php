<?php

namespace App\Modules\Production\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Exceptions\InvalidTimeRangeException;
use App\Modules\Production\Models\Enums\LogStatus;
use App\Modules\Production\Models\MachineDowntimeLog;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * A "stopwatch" log — opened when a machine goes down, closed once it's
 * fixed. total_minutes is always computed from from_time/to_time, never a
 * manual input. Both open() and close() are concurrency-guarded the same
 * way as ShiftProductionEntryService — several people can act on any of
 * the floor's machines ad hoc, so two people reporting/closing the same
 * breakdown at once must fail loudly, not double-log it.
 *
 * from_time/to_time default to "now" but accept an explicit override —
 * a supervisor sometimes logs a breakdown after it's already been fixed,
 * not live as it happens.
 */
class MachineDowntimeLogService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return MachineDowntimeLog::query()
            ->with(['workCenter', 'shift', 'createdBy'])
            ->orderByDesc('production_date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  array{work_center_id: int, shift_id: int, production_date?: string, nature_of_problem: string, from_time?: string}  $data
     */
    public function open(array $data, ?int $createdBy): MachineDowntimeLog
    {
        return DB::transaction(function () use ($data, $createdBy) {
            $alreadyOpen = MachineDowntimeLog::query()
                ->where('work_center_id', $data['work_center_id'])
                ->where('status', LogStatus::Open->value)
                ->lockForUpdate()
                ->exists();

            if ($alreadyOpen) {
                throw InvalidStatusTransitionException::make('machine downtime log', 'idle', LogStatus::Open->value);
            }

            $log = MachineDowntimeLog::create([
                'work_center_id' => $data['work_center_id'],
                'shift_id' => $data['shift_id'],
                'production_date' => $data['production_date'] ?? now()->toDateString(),
                'nature_of_problem' => $data['nature_of_problem'],
                'from_time' => isset($data['from_time']) ? Carbon::parse($data['from_time']) : now(),
                'status' => LogStatus::Open,
                'created_by' => $createdBy,
            ]);

            return $log->fresh(['workCenter', 'shift']);
        });
    }

    /**
     * @param  array{remedy?: string, parts_changed?: string, to_time?: string}  $data
     */
    public function close(MachineDowntimeLog $log, array $data): MachineDowntimeLog
    {
        $toTime = isset($data['to_time']) ? Carbon::parse($data['to_time']) : now();

        if ($toTime->lessThanOrEqualTo($log->from_time)) {
            throw InvalidTimeRangeException::make('Fixed time');
        }

        $totalMinutes = bcdiv((string) $log->from_time->diffInSeconds($toTime), '60', 2);

        $affected = MachineDowntimeLog::query()
            ->where('id', $log->id)
            ->where('status', LogStatus::Open->value)
            ->update([
                'remedy' => $data['remedy'] ?? null,
                'parts_changed' => $data['parts_changed'] ?? null,
                'to_time' => $toTime,
                'total_minutes' => $totalMinutes,
                'status' => LogStatus::Closed->value,
            ]);

        if ($affected === 0) {
            throw InvalidStatusTransitionException::make('machine downtime log', LogStatus::Open->value, LogStatus::Closed->value);
        }

        return $log->fresh(['workCenter', 'shift']);
    }
}
