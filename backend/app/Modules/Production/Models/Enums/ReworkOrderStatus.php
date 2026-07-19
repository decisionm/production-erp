<?php

namespace App\Modules\Production\Models\Enums;

enum ReworkOrderStatus: string
{
    case Draft = 'draft';
    case Released = 'released';
    case Completed = 'completed';
}
