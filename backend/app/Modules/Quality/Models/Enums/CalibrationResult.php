<?php

namespace App\Modules\Quality\Models\Enums;

enum CalibrationResult: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Adjusted = 'adjusted';
}
