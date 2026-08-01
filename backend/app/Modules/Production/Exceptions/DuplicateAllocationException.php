<?php

namespace App\Modules\Production\Exceptions;

use RuntimeException;

/**
 * A second LIVE allocation run was attempted for a batch that already has
 * one. This is an invariant breach, not a user error: corrections go
 * through reverse() first (amendCompletion does exactly that), so reaching
 * here means a caller booked costs twice and the batch would have been
 * charged for its resin twice over.
 *
 * It is loud on purpose. Inside completeBatch's transaction it rolls the
 * whole completion back, which is the correct outcome — a batch whose cost
 * cannot be allocated exactly once is a batch nobody should be shown.
 */
class DuplicateAllocationException extends RuntimeException
{
    public static function make(int $entryId): self
    {
        return new self(
            "shift production entry #{$entryId} already has a live bag-cost allocation run — "
            .'reverse it before allocating again, never allocate twice',
        );
    }
}
