<?php

namespace App\Modules\Production\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

/**
 * Adding a consumption line the run did not plan for is an AUTHORISED act,
 * not a floor convenience: it needs material-substitution.manage AND the
 * explicit per-line flag, exactly as an out-of-order bag needs
 * production.override-fifo with override_fifo set (FifoPolicyException).
 *
 * The refusal names the item, because a supervisor holding the wrong tub
 * needs to know which line was rejected, not merely that one was.
 */
class SubstitutionNotPermittedException extends RuntimeException implements DomainException
{
    public static function forItem(string $itemName): self
    {
        return new self(
            "{$itemName} was not planned for this run — adding it as consumed ".
            'requires the material-substitution.manage permission.'
        );
    }

    /**
     * Machine-readable discriminator for the 422 body, same contract as
     * `fifo_order`: the SPA keys its "ask someone authorised" prompt off the
     * code, never off message text.
     */
    public function errorCode(): string
    {
        return 'substitution_not_permitted';
    }
}
