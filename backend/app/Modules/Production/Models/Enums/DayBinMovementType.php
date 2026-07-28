<?php

namespace App\Modules\Production\Models\Enums;

enum DayBinMovementType: string
{
    case Load = 'load';
    case Return = 'return';
    case Count = 'count';
}
