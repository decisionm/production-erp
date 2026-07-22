<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use App\Modules\Production\Models\Enums\LogStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'work_center_id', 'shift_id', 'production_date', 'nature_of_problem', 'remedy', 'parts_changed',
    'from_time', 'to_time', 'total_minutes', 'status', 'created_by',
])]
class MachineDowntimeLog extends Model
{
    protected function casts(): array
    {
        return [
            'production_date' => 'date',
            'from_time' => 'datetime',
            'to_time' => 'datetime',
            'status' => LogStatus::class,
        ];
    }

    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
