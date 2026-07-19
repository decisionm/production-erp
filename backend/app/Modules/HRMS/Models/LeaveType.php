<?php

namespace App\Modules\HRMS\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['code', 'name', 'default_annual_days', 'is_active'])]
class LeaveType extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'default_annual_days' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
