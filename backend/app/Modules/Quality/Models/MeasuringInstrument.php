<?php

namespace App\Modules\Quality\Models;

use App\Modules\Quality\Models\Enums\MeasuringInstrumentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'code', 'name', 'location', 'calibration_frequency_days',
    'last_calibrated_date', 'next_calibration_due', 'status',
])]
class MeasuringInstrument extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => MeasuringInstrumentStatus::class,
            'last_calibrated_date' => 'date',
            'next_calibration_due' => 'date',
        ];
    }

    public function calibrationRecords(): HasMany
    {
        return $this->hasMany(CalibrationRecord::class)->orderByDesc('calibrated_date');
    }
}
