<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['shift_id', 'production_date', 'from_time', 'to_time', 'idle_hours', 'created_by'])]
class PowerInterruptionLog extends Model
{
    protected function casts(): array
    {
        return [
            'production_date' => 'date',
            'from_time' => 'datetime',
            'to_time' => 'datetime',
        ];
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
