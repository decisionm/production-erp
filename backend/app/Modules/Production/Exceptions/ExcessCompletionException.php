<?php

namespace App\Modules\Production\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

class ExcessCompletionException extends RuntimeException implements DomainException
{
    public static function forWorkOrder(int $workOrderId, string $quantityPlanned, string $accountedFor): self
    {
        return new self(
            "Work order #{$workOrderId}: completed quantity plus scrap ({$accountedFor}) exceeds ".
            "quantity planned ({$quantityPlanned})."
        );
    }
}
