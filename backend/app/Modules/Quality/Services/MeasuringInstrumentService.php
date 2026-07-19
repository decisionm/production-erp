<?php

namespace App\Modules\Quality\Services;

use App\Modules\Quality\Models\MeasuringInstrument;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class MeasuringInstrumentService
{
    public function paginate(bool $dueOnly = false, int $perPage = 20): LengthAwarePaginator
    {
        return MeasuringInstrument::query()
            ->when($dueOnly, fn ($query) => $query->where('next_calibration_due', '<=', today()))
            ->orderBy('next_calibration_due')
            ->paginate($perPage);
    }

    /**
     * @param  array{code: string, name: string, location?: string, calibration_frequency_days: int, next_calibration_due: string}  $data
     */
    public function create(array $data): MeasuringInstrument
    {
        return MeasuringInstrument::create([
            'status' => 'active',
            ...$data,
        ]);
    }

    public function calibrationHistory(MeasuringInstrument $instrument): MeasuringInstrument
    {
        return $instrument->load('calibrationRecords');
    }

    /**
     * @param  array{calibrated_date: string, certificate_number?: string, result: string, performed_by?: string, notes?: string}  $data
     */
    public function recordCalibration(MeasuringInstrument $instrument, array $data): MeasuringInstrument
    {
        return DB::transaction(function () use ($instrument, $data) {
            $instrument->calibrationRecords()->create([
                'calibrated_date' => $data['calibrated_date'],
                'certificate_number' => $data['certificate_number'] ?? null,
                'result' => $data['result'],
                'performed_by' => $data['performed_by'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $nextDue = Carbon::parse($data['calibrated_date'])->addDays($instrument->calibration_frequency_days);

            $instrument->update([
                'last_calibrated_date' => $data['calibrated_date'],
                'next_calibration_due' => $nextDue->toDateString(),
            ]);

            return $instrument->fresh('calibrationRecords');
        });
    }
}
