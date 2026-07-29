<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'code', 'name', 'display_sequence', 'capacity_hours_per_day', 'is_active',
    // Machine capabilities — the physical limits a configuration may narrow
    // but never widen. All null today: the factory's master workbook leaves
    // every cavity field empty, and a guessed limit would silently block or
    // silently permit the wrong thing.
    'capacity_class', 'min_cavities', 'max_cavities', 'permitted_cavities',
    'cycle_time_min', 'cycle_time_max', 'default_shift_hours', 'confirmation_status',
])]
class WorkCenter extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'capacity_hours_per_day' => 'decimal:2',
            'is_active' => 'boolean',
            'min_cavities' => 'integer',
            'max_cavities' => 'integer',
            'permitted_cavities' => 'array',
            'cycle_time_min' => 'decimal:2',
            'cycle_time_max' => 'decimal:2',
            'default_shift_hours' => 'decimal:2',
        ];
    }
}
