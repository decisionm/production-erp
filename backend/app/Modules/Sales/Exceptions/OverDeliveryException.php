<?php

namespace App\Modules\Sales\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

class OverDeliveryException extends RuntimeException implements DomainException
{
    public static function forLine(int $salesOrderLineId, string $remaining, string $requested): self
    {
        return new self(
            "Cannot deliver more than the remaining ordered quantity for sales order line #{$salesOrderLineId}: ".
            "remaining {$remaining}, requested {$requested}."
        );
    }
}
