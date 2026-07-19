<?php

namespace App\Modules\Quality\Models\Enums;

enum CapaStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Closed = 'closed';
}
