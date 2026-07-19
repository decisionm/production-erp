<?php

namespace App\Modules\Core\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

class SelfDeactivationException extends RuntimeException implements DomainException
{
    public static function make(): self
    {
        return new self('You cannot deactivate your own account.');
    }
}
