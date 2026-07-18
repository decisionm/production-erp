<?php

namespace App\Modules\Inventory\Exceptions;

use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public static function forItem(int $itemId, int $warehouseId, string $available, string $requested): self
    {
        return new self(
            "Insufficient stock for item #{$itemId} at warehouse #{$warehouseId}: ".
            "available {$available}, requested {$requested}."
        );
    }
}
