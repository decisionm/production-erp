<?php

namespace App\Modules\Production\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Exceptions\InvalidTimeRangeException;
use App\Modules\Production\Models\Enums\LogStatus;
use App\Modules\Production\Models\MoldChangeLog;
use App\Modules\Production\Models\Shift;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Bridges two "Complete Batch"/"Start Batch" calls on the same machine —
 * both the mold going in and the item it's about to run are known up
 * front, only the clock is running: open() stamps from_time, close() stamps
 * to_time and computes total_minutes.
 *
 * Mold and item are two independent selections, not one derived from the
 * other — a single mold is reused across an item's colour variants (each
 * colour its own Item record), so there's no fixed mold->item mapping.
 * "Changed From" mirrors "Changed To": which mold came out, not what item
 * it had been running.
 *
 * A mold change commonly runs well over an hour, so open() supports two
 * distinct real cases, not just "logging it live":
 *   - from_time only (or omitted, defaulting to now) → opens in_progress,
 *     to be finished later via close().
 *   - both from_time and to_time given → the change already happened
 *     start to finish; the log is created already closed in one step, so
 *     a supervisor catching up on paperwork doesn't have to open() and
 *     then immediately close() a change that's long since over.
 */
class MoldChangeLogService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return MoldChangeLog::query()
            ->with(['workCenter', 'shift', 'changedFromItem', 'changedFromMold', 'changedToItem', 'changedToMold', 'createdBy'])
            ->orderByDesc('production_date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  array{work_center_id: int, shift_id: int, production_date?: string, changed_from_item_id?: int, changed_from_mold_id?: int, changed_to_item_id: int, changed_to_mold_id: int, from_time?: string, to_time?: string}  $data
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

            $fromTime = isset($data['from_time']) ? Carbon::parse($data['from_time']) : now();
            $toTime = isset($data['to_time']) ? Carbon::parse($data['to_time']) : null;

            $attributes = [
                'work_center_id' => $data['work_center_id'],
                'shift_id' => $data['shift_id'],
                'production_date' => $data['production_date'] ?? Shift::query()->find($data['shift_id'])?->productionDateFor() ?? now()->toDateString(),
                'changed_from_item_id' => $data['changed_from_item_id'] ?? null,
                'changed_from_mold_id' => $data['changed_from_mold_id'] ?? null,
                'changed_to_item_id' => $data['changed_to_item_id'],
                'changed_to_mold_id' => $data['changed_to_mold_id'],
                'from_time' => $fromTime,
                'created_by' => $createdBy,
            ];

            if ($toTime !== null) {
                if ($toTime->lessThanOrEqualTo($fromTime)) {
                    throw InvalidTimeRangeException::make('Finish time');
                }

                $attributes['to_time'] = $toTime;
                $attributes['total_minutes'] = bcdiv((string) $fromTime->diffInSeconds($toTime), '60', 2);
                $attributes['status'] = LogStatus::Closed;
            } else {
                $attributes['status'] = LogStatus::Open;
            }

            $log = MoldChangeLog::create($attributes);

            return $log->fresh(['workCenter', 'shift', 'changedFromItem', 'changedFromMold', 'changedToItem', 'changedToMold']);
        });
    }

    /**
     * @param  array{to_time?: string}  $data
     */
    public function close(MoldChangeLog $log, array $data = []): MoldChangeLog
    {
        $toTime = isset($data['to_time']) ? Carbon::parse($data['to_time']) : now();

        if ($toTime->lessThanOrEqualTo($log->from_time)) {
            throw InvalidTimeRangeException::make('Finish time');
        }

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

        return $log->fresh(['workCenter', 'shift', 'changedFromItem', 'changedFromMold', 'changedToItem', 'changedToMold']);
    }
}
