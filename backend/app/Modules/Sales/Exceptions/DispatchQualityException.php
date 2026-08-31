<?php

namespace App\Modules\Sales\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

/**
 * The refusals of the internal quality gate (DEC-20260831-003). Each one names
 * the figure it refused on, because a gate that says only "no" sends the reader
 * to guess which of four things is wrong.
 */
class DispatchQualityException extends RuntimeException implements DomainException
{
    public static function notFullyHeld(string $held, string $outstanding): self
    {
        return new self(
            "Quality approves a line only once the stock is fully held: {$held} is held against {$outstanding} still owed. "
            .'Hold the remainder on Store Fulfilment first, or ask the floor for it.'
        );
    }

    public static function alreadyApproved(): self
    {
        return new self(
            'This line is already approved by Quality. Withdraw the existing approval first if it needs to be looked at again — '
            .'re-approving in place would raise the dispatch cap with no record that anybody re-inspected the stock.'
        );
    }

    public static function notApproved(): self
    {
        return new self('This line has no quality approval to withdraw.');
    }

    public static function alreadyDispatched(string $delivered): self
    {
        return new self(
            "A quality approval cannot be withdrawn once goods have gone: {$delivered} has already been dispatched against this line. "
            .'Raise a non-conformance report instead — the approval is a record of what was signed for, and history is not rewritten.'
        );
    }

    public static function orderNotLive(string $status): self
    {
        return new self("Quality approves lines of live orders only; this order is {$status}.");
    }
}
