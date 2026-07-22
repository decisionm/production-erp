<?php

namespace App\Modules\Production\Models\Enums;

enum BatchStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
}
