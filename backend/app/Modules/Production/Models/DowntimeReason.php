<?php

namespace App\Modules\Production\Models;

use App\Modules\Production\Models\Enums\DowntimePlanningType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'code', 'category', 'description', 'planning_type', 'reduces_runtime',
    'requires_note', 'selectable_at_start', 'is_active', 'confirmation_status',
])]
class DowntimeReason extends Model
{
    protected function casts(): array
    {
        return [
            'planning_type' => DowntimePlanningType::class,
            'reduces_runtime' => 'boolean',
            'requires_note' => 'boolean',
            'selectable_at_start' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
