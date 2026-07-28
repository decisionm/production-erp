<?php

namespace App\Modules\Production\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

/**
 * Day-bin no-negative-balance guards (design doc "Allocation rules"):
 * a return can never exceed what the bin holds, and a closing count can
 * never exceed opening + loaded − returned for the segment window.
 */
class DayBinBalanceException extends RuntimeException implements DomainException
{
    public static function forReturn(string $requested, string $balance): self
    {
        return new self(
            "Cannot return {$requested} kg from the day bin: only {$balance} kg present."
        );
    }

    public static function forCount(string $counted, string $maximum): self
    {
        return new self(
            "Day-bin count of {$counted} kg exceeds the possible {$maximum} kg ".
            '(opening + loaded − returned) — recheck the scale or the loads.'
        );
    }
}
