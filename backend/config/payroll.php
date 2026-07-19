<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Statutory Deduction Rates (India)
    |--------------------------------------------------------------------------
    |
    | Standard EPFO/ESIC rates as of this writing. Kept configurable per
    | instance since these are set by statute and can change — do not
    | hardcode them into the calculation logic.
    |
    */

    'pf' => [
        'employee_rate' => (float) env('PAYROLL_PF_EMPLOYEE_RATE', 0.12),
        'employer_rate' => (float) env('PAYROLL_PF_EMPLOYER_RATE', 0.12),
        'wage_ceiling' => (float) env('PAYROLL_PF_WAGE_CEILING', 15000),
    ],

    'esi' => [
        'employee_rate' => (float) env('PAYROLL_ESI_EMPLOYEE_RATE', 0.0075),
        'employer_rate' => (float) env('PAYROLL_ESI_EMPLOYER_RATE', 0.0325),
        'wage_ceiling' => (float) env('PAYROLL_ESI_WAGE_CEILING', 21000),
    ],

];
