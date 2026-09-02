<?php

namespace App\Modules\Procurement\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

/**
 * DEC-20260902-025: the person who raised a requisition may not decide it.
 * One message for approve and reject; the refusal names the rule, not the user.
 */
class SelfDecisionException extends RuntimeException implements DomainException
{
    public static function forRequisition(int $requisitionId, string $verb): self
    {
        return new self("A requisition cannot be {$verb} by the person who raised it.");
    }
}
