<?php

namespace App\Modules\Quality\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['spc_characteristic_id', 'value', 'measured_at', 'recorded_by', 'notes'])]
class SpcMeasurement extends Model
{
    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'measured_at' => 'datetime',
        ];
    }

    public function characteristic(): BelongsTo
    {
        return $this->belongsTo(SpcCharacteristic::class, 'spc_characteristic_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
