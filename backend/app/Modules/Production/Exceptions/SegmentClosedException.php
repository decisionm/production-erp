<?php

namespace App\Modules\Production\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

/**
 * Day-bin movements are per-segment history: once a segment (shift
 * production entry) leaves in_progress, its window is closed and nothing
 * may be back-recorded into it — a late load/return/count against a
 * completed segment would silently rewrite an already-submitted
 * consumption figure.
 */
class SegmentClosedException extends RuntimeException implements DomainException
{
    public static function make(int $shiftProductionEntryId): self
    {
        return new self(
            'Segment already completed — day-bin movements cannot reference '.
            "shift production entry #{$shiftProductionEntryId} because it is no longer in progress."
        );
    }
}
