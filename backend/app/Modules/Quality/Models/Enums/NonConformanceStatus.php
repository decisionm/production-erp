<?php

namespace App\Modules\Quality\Models\Enums;

enum NonConformanceStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
