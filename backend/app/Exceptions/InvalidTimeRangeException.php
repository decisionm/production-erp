<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidTimeRangeException extends RuntimeException implements DomainException
{
    public static function make(string $label = 'End time'): self
    {
        return new self("{$label} must be after the start time.");
    }
}
