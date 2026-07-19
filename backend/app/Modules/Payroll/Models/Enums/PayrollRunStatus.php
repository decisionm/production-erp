<?php

namespace App\Modules\Payroll\Models\Enums;

enum PayrollRunStatus: string
{
    case Draft = 'draft';
    case Processed = 'processed';
    case Paid = 'paid';
}
