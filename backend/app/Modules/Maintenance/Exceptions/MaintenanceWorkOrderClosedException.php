<?php

namespace App\Modules\Maintenance\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

class MaintenanceWorkOrderClosedException extends RuntimeException implements DomainException
{
    public static function forWorkOrder(int $workOrderId, string $status): self
    {
        return new self("Cannot add parts to maintenance work order #{$workOrderId}: it is {$status}.");
    }
}
