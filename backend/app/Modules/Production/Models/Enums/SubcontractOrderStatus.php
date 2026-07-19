<?php

namespace App\Modules\Production\Models\Enums;

enum SubcontractOrderStatus: string
{
    case Draft = 'draft';
    case MaterialsSent = 'materials_sent';
    case Completed = 'completed';
}
