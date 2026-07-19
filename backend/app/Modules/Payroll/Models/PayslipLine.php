<?php

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Models\Enums\PayslipLineType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['payslip_id', 'label', 'type', 'amount'])]
class PayslipLine extends Model
{
    protected function casts(): array
    {
        return [
            'type' => PayslipLineType::class,
        ];
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}
