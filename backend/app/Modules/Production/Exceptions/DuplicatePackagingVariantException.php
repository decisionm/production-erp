<?php

namespace App\Modules\Production\Exceptions;

use App\Exceptions\DomainException;
use App\Modules\Production\Models\ProductionStandard;
use RuntimeException;

/**
 * A packaging row stating the SAME mode and the SAME counts as one the
 * standard already carries. Two such rows would leave the Start Batch
 * picker offering one choice twice, so the twin is refused with the row
 * named — the person corrects the existing option instead of adding one.
 *
 * Renders as a 422 through the DomainException handler. It ALSO carries the
 * field-level `errors.mode` shape the standards page already reads
 * (showSaveError() joins `errors` first): the refusal moved from a
 * ValidationException in the controller to a domain rule in the service,
 * and the wire contract must not move with it.
 */
class DuplicatePackagingVariantException extends RuntimeException implements DomainException
{
    public static function make(ProductionStandard $standard, string $mode): self
    {
        $product = trim((string) ($standard->source_product_name ?: $standard->item?->name ?: "standard #{$standard->id}"));

        return new self(
            "An identical {$mode} packing option already exists on {$product} — edit that one instead of adding a twin.",
        );
    }

    public function errorCode(): string
    {
        return 'duplicate_packaging_variant';
    }

    /** @return array{errors: array{mode: list<string>}} */
    public function payload(): array
    {
        return ['errors' => ['mode' => [$this->getMessage()]]];
    }
}
