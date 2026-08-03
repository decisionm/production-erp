<?php

namespace App\Modules\Production\Models\Enums;

enum BatchStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';

    /**
     * Started by mistake and withdrawn before it produced anything.
     *
     * NOT a third kind of finished batch. Every query that means "a batch that
     * ran" already asks for InProgress or Completed by name, so a cancelled
     * row disappears from the machine-busy guard, the Shift Floor's active
     * list and the approval queue without any of them being taught about it —
     * which is the point: the safest way to add a terminal state is one that
     * existing filters exclude by construction rather than by remembering to.
     */
    case Cancelled = 'cancelled';
}
