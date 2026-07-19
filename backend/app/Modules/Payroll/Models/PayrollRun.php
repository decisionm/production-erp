<?php

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Models\Enums\PayrollRunStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['year', 'month', 'status', 'processed_at', 'paid_at'])]
class PayrollRun extends Model
{
    protected function casts(): array
    {
        return [
            'status' => PayrollRunStatus::class,
            'processed_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }
}
