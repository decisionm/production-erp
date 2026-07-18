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
}
