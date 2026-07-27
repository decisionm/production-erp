<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['code', 'name', 'display_sequence', 'capacity_hours_per_day', 'is_active'])]
class WorkCenter extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'capacity_hours_per_day' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
