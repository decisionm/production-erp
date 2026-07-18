<?php

namespace App\Modules\Compliance\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

class GstComputationException extends RuntimeException implements DomainException
{
    public static function missingHsnCode(int $itemId): self
    {
        return new self("Item #{$itemId} has no HSN/SAC code set — cannot compute GST.");
    }

    public static function missingRate(int $itemId, string $hsnSacCode): self
    {
        return new self("No active GST rate configured for HSN/SAC code \"{$hsnSacCode}\" (item #{$itemId}).");
    }

    public static function noPrimaryRegistration(): self
    {
        return new self('No primary GST registration is configured for this company.');
    }

    public static function customerStateUnknown(int $customerId): self
    {
        return new self("Customer #{$customerId} has no GST state code set — cannot determine intra-state vs inter-state supply.");
    }
}
