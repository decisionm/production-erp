<?php

namespace App\Modules\Sales\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

/**
 * Dispatch refused because internal Quality has not signed the line off, or has
 * not signed off this much of it — DEC-20260831-006.
 *
 * Separate from DispatchQualityException, which refuses the APPROVAL act. This
 * one refuses the DISPATCH, and it is the gate the owner's sequence names.
 */
class DispatchNotQualityApprovedException extends RuntimeException implements DomainException
{
    public static function forLine(int $lineId): self
    {
        return new self(
            "Line #{$lineId} has no internal quality approval, so it cannot be dispatched. "
            .'Quality signs a line off once its stock is fully held; until then the goods stay in the store.'
        );
    }

    public static function beyondApproved(int $lineId, string $approvedRemaining, string $attempted): self
    {
        return new self(
            "Line #{$lineId} is approved by Quality for {$approvedRemaining} more, and this dispatch is for {$attempted}. "
            .'Quality approves a quantity, not a line — ask them to look at the rest before it goes.'
        );
    }
}
