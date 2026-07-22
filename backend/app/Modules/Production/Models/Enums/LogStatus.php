<?php

namespace App\Modules\Production\Models\Enums;

/**
 * Shared open/closed lifecycle for MachineDowntimeLog and MoldChangeLog —
 * both are "stopwatch" events: opened when the thing starts, closed when
 * it ends, with total_minutes computed from the two timestamps at close.
 */
enum LogStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
