<?php

namespace App\Modules\Procurement\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

class InvalidStatusTransitionException extends RuntimeException implements DomainException
{
    public static function make(string $document, string $from, string $to): self
    {
        return new self("Cannot transition {$document} from \"{$from}\" to \"{$to}\".");
    }
}
