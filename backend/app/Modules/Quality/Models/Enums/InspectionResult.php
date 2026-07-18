<?php

namespace App\Modules\Quality\Models\Enums;

enum InspectionResult: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Partial = 'partial';
}
