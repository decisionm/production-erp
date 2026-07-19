<?php

namespace App\Modules\Production\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

class MissingBomException extends RuntimeException implements DomainException
{
    public static function forItem(int $itemId): self
    {
        return new self("No BOM specified and no active BOM found for item #{$itemId}.");
    }
}
