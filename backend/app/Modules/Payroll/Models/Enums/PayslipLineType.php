<?php

namespace App\Modules\Payroll\Models\Enums;

enum PayslipLineType: string
{
    case Earning = 'earning';
    case Deduction = 'deduction';
    case EmployerContribution = 'employer_contribution';
}
