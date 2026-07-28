<?php

namespace App\Modules\Inventory\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

/**
 * Over-consumption guard (design doc "Allocation rules"): a load can
 * never exceed the bag's remaining_kg.
 */
class BagOverloadException extends RuntimeException implements DomainException
{
    public static function make(string $barcode, string $requested, string $remaining): self
    {
        return new self(
            "Cannot load {$requested} kg from bag {$barcode}: only {$remaining} kg remaining."
        );
    }

    /**
     * The return-side cap: a bag can never hold more than it originally
     * did — remaining + returned must stay ≤ original_kg.
     */
    public static function forReturn(string $barcode, string $returned, string $remaining, string $original): self
    {
        return new self(
            "Cannot return {$returned} kg to bag {$barcode}: it already holds {$remaining} kg ".
            "of an original {$original} kg — the bag cannot exceed what it started with."
        );
    }
}
