<?php

namespace App\Modules\Payroll\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

class MissingBasicComponentException extends RuntimeException implements DomainException
{
    public static function forPercentageComponent(string $componentCode): self
    {
        return new self(
            "Cannot resolve \"{$componentCode}\": it is calculated as a percentage of Basic, ".
            'but this structure has no line for the "BASIC" component.'
        );
    }
}
