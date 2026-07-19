<?php

namespace App\Modules\Production\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

class CircularBomException extends RuntimeException implements DomainException
{
    public static function forItem(int $itemId): self
    {
        return new self("Circular BOM reference detected: item #{$itemId} appears in its own bill-of-materials chain.");
    }
}
