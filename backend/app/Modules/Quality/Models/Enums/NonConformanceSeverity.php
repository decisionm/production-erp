<?php

namespace App\Modules\Quality\Models\Enums;

enum NonConformanceSeverity: string
{
    case Minor = 'minor';
    case Major = 'major';
    case Critical = 'critical';
}
