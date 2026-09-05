<?php

namespace App\Modules\HRMS\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['code', 'name', 'default_annual_days', 'monthly_accrual_days', 'is_active'])]
class LeaveType extends Model
{
    use SoftDeletes;

    /**
     * The column default again, in the model. A DB default is not read
     * back onto the instance the insert returns, so a type created
     * without an increment would answer `null` for it exactly once —
     * on the response to its own creation.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'monthly_accrual_days' => 0,
    ];

    protected function casts(): array
    {
        return [
            'default_annual_days' => 'decimal:2',
            'monthly_accrual_days' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
