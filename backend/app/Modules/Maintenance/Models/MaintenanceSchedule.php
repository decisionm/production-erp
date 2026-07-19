<?php

namespace App\Modules\Maintenance\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['asset_id', 'name', 'frequency_days', 'next_due_date', 'is_active'])]
class MaintenanceSchedule extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'next_due_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
