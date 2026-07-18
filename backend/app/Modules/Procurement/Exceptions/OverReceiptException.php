<?php

namespace App\Modules\Procurement\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

class OverReceiptException extends RuntimeException implements DomainException
{
    public static function forLine(int $purchaseOrderLineId, string $remaining, string $requested): self
    {
        return new self(
            "Cannot receive more than the remaining ordered quantity for purchase order line #{$purchaseOrderLineId}: ".
            "remaining {$remaining}, requested {$requested}."
        );
    }
}
