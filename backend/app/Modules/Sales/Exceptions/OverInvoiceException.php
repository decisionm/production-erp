<?php

namespace App\Modules\Sales\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

/**
 * An invoice line asked for more than the order line has left to bill — the
 * invoice-side twin of OverDeliveryException, rendered as the same 422.
 */
class OverInvoiceException extends RuntimeException implements DomainException
{
    public static function forLine(int $salesOrderLineId, string $remaining, string $requested): self
    {
        return new self(
            "Cannot invoice more than the remaining ordered quantity for sales order line #{$salesOrderLineId}: ".
            "remaining {$remaining}, requested {$requested}."
        );
    }
}
