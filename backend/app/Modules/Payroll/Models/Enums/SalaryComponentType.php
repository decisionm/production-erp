<?php

namespace App\Modules\Payroll\Models\Enums;

enum SalaryComponentType: string
{
    case Earning = 'earning';
    case Deduction = 'deduction';
}
