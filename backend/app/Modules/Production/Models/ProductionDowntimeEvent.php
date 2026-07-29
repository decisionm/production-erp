<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'shift_production_entry_id', 'work_center_id', 'downtime_reason_id',
    'production_date', 'minutes', 'is_planned', 'known_before_start',
    'note', 'recorded_by',
])]
class ProductionDowntimeEvent extends Model
{
    protected function casts(): array
    {
        return [
            'production_date' => 'date',
            'minutes' => 'decimal:2',
            'is_planned' => 'boolean',
            'known_before_start' => 'boolean',
        ];
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(DowntimeReason::class, 'downtime_reason_id');
    }

    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
