<?php

namespace App\Modules\Quality\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

class IncompleteCapaException extends RuntimeException implements DomainException
{
    public static function forCapa(int $capaId): self
    {
        return new self(
            "CAPA #{$capaId} cannot be closed: root cause, corrective action, and preventive action must all be documented first."
        );
    }
}
