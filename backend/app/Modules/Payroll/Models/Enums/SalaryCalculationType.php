<?php

namespace App\Modules\Payroll\Models\Enums;

enum SalaryCalculationType: string
{
    case FixedAmount = 'fixed_amount';
    case PercentageOfBasic = 'percentage_of_basic';
}
