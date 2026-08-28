<?php

namespace App\Modules\Quality\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

class InvalidInspectionQuantityException extends RuntimeException implements DomainException
{
    public static function mismatch(string $inspected, string $accepted, string $rejected): self
    {
        return new self(
            "Accepted ({$accepted}) plus rejected ({$rejected}) must equal inspected quantity ({$inspected})."
        );
    }

    public static function exceedsReceived(string $received, string $inspected): self
    {
        return new self(
            "Cannot inspect more than the quantity received on this line: received {$received}, inspected {$inspected}."
        );
    }

    /**
     * AN INSPECTION COVERS THE WHOLE ARRIVAL LINE, or it is refused.
     *
     * The disposition that follows an inspection releases every bag on the line
     * that was not rejected. It cannot do otherwise: a bag held back would have
     * no way out, because a line that already has an inspection refuses a
     * second one. So an inspection of PART of a line silently released the
     * uninspected remainder into available stock as though it had passed — a
     * quality escape with nothing on the record to show it happened.
     *
     * Refused rather than guessed at. Inspecting part of a line is a real
     * thing a factory might want; it needs re-inspection built to go with it,
     * and the rule for what happens to the remainder is the quality desk's to
     * give. Until then the honest answer is to say so.
     */
    public static function mustCoverWholeLine(string $received, string $inspected): self
    {
        return new self(
            "An inspection must cover the whole arrival line: this line received {$received} and only {$inspected} was inspected. ".
            'Releasing bags for the uninspected remainder would pass material nobody looked at, and holding them back would strand them, '.
            'because a line that already has an inspection cannot be inspected again. Inspect the full quantity, or raise partial inspection as a change.'
        );
    }
}
