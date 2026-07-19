<?php

namespace App\Modules\Quality\Services;

use App\Modules\Quality\Models\SpcCharacteristic;
use App\Modules\Quality\Models\SpcMeasurement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SpcMeasurementService
{
    public function paginate(SpcCharacteristic $characteristic, int $perPage = 50): LengthAwarePaginator
    {
        return $characteristic->measurements()
            ->orderByDesc('measured_at')
            ->paginate($perPage);
    }

    /**
     * @param  array{value: string, measured_at?: string, notes?: string}  $data
     */
    public function record(SpcCharacteristic $characteristic, array $data, ?int $recordedBy): SpcMeasurement
    {
        return $characteristic->measurements()->create([
            'value' => $data['value'],
            'measured_at' => $data['measured_at'] ?? now(),
            'recorded_by' => $recordedBy,
            'notes' => $data['notes'] ?? null,
        ]);
    }
}
