<?php

namespace App\Modules\Production\Models\Enums;

enum MoldStatus: string
{
    case Active = 'active';
    case UnderRepair = 'under_repair';
    case Retired = 'retired';
}
