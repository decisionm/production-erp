<?php

namespace App\Modules\Quality\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

class CapaClosedException extends RuntimeException implements DomainException
{
    public static function forUpdate(int $capaId): self
    {
        return new self("CAPA #{$capaId} is closed and cannot be edited.");
    }
}
