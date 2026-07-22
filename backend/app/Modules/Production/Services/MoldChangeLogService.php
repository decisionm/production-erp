<?php

namespace App\Modules\Production\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\Production\Models\Enums\LogStatus;
use App\Modules\Production\Models\MoldChangeLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Bridges two "Complete Batch"/"Start Batch" calls on the same machine —
 * both items are known up front, only the clock is running: open() stamps
 * from_time, close() stamps to_time and computes total_minutes.
 */
class MoldChangeLogService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return MoldChangeLog::query()
            ->with(['workCenter', 'shift', 'changedFromItem', 'changedToItem', 'createdBy'])
            ->orderByDesc('production_date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  array{work_center_id: int, shift_id: int, production_date?: string, changed_from_item_id?: int, changed_to_item_id: int}  $data
     */
    public function open(array $data, ?int $createdBy): MoldChangeLog
    {
        return DB::transaction(function () use ($data, $createdBy) {
            $alreadyOpen = MoldChangeLog::query()
                ->where('work_center_id', $data['work_center_id'])
                ->where('status', LogStatus::Open->value)
                ->lockForUpdate()
                ->exists();

            if ($alreadyOpen) {
                throw InvalidStatusTransitionException::make('mold change log', 'idle', LogStatus::Open->value);
            }

            $log = MoldChangeLog::create([
                'work_center_id' => $data['work_center_id'],
                'shift_id' => $data['shift_id'],
                'production_date' => $data['production_date'] ?? now()->toDateString(),
                'changed_from_item_id' => $data['changed_from_item_id'] ?? null,
                'changed_to_item_id' => $data['changed_to_item_id'],
                'from_time' => now(),
                'status' => LogStatus::Open,
                'created_by' => $createdBy,
            ]);

            return $log->fresh(['workCenter', 'shift', 'changedFromItem', 'changedToItem']);
        });
    }

    public function close(MoldChangeLog $log): MoldChangeLog
    {
        $toTime = now();
        $totalMinutes = bcdiv((string) $log->from_time->diffInSeconds($toTime), '60', 2);

        $affected = MoldChangeLog::query()
            ->where('id', $log->id)
            ->where('status', LogStatus::Open->value)
            ->update([
                'to_time' => $toTime,
                'total_minutes' => $totalMinutes,
                'status' => LogStatus::Closed->value,
            ]);

        if ($affected === 0) {
            throw InvalidStatusTransitionException::make('mold change log', LogStatus::Open->value, LogStatus::Closed->value);
        }

        return $log->fresh(['workCenter', 'shift', 'changedFromItem', 'changedToItem']);
    }
}
