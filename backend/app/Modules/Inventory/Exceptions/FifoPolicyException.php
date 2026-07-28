<?php

namespace App\Modules\Inventory\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

/**
 * FIFO by received_date, then bag sequence: scanning a newer bag while an
 * older one stays open in the store needs the production.override-fifo
 * permission AND an explicit override flag (so the override is a recorded
 * decision, never an accident). Enforcement itself is configurable —
 * production.traceability.fifo_enforced (Vincent Q3).
 */
class FifoPolicyException extends RuntimeException implements DomainException
{
    public static function make(string $scannedBarcode, string $expectedBarcode): self
    {
        return new self(
            "FIFO: bag {$expectedBarcode} is older and still open — loading {$scannedBarcode} ".
            'requires the production.override-fifo permission with override_fifo set.'
        );
    }

    /**
     * Machine-readable discriminator for the 422 body — the SPA keys its
     * "override FIFO?" retry prompt off `code === 'fifo_order'`, never off
     * message text (see bootstrap/app.php's DomainException render).
     */
    public function errorCode(): string
    {
        return 'fifo_order';
    }
}
