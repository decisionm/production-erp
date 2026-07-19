<?php

namespace App\Modules\Payroll\Models;

use App\Modules\HRMS\Models\Employee;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['employee_id', 'effective_from'])]
class SalaryStructure extends Model
{
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalaryStructureLine::class);
    }
}
