<?php

namespace App\Modules\Quality\Models;

use App\Modules\Quality\Models\Enums\CalibrationResult;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['measuring_instrument_id', 'calibrated_date', 'certificate_number', 'result', 'performed_by', 'notes'])]
class CalibrationRecord extends Model
{
    protected function casts(): array
    {
        return [
            'calibrated_date' => 'date',
            'result' => CalibrationResult::class,
        ];
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(MeasuringInstrument::class, 'measuring_instrument_id');
    }
}
