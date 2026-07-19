<?php

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Models\Enums\SalaryCalculationType;
use App\Modules\Payroll\Models\Enums\SalaryComponentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['code', 'name', 'type', 'calculation_type', 'percentage', 'is_active'])]
class SalaryComponent extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'type' => SalaryComponentType::class,
            'calculation_type' => SalaryCalculationType::class,
            'percentage' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
