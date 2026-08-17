<?php

namespace App\Modules\Inventory\Exceptions;

use App\Exceptions\DomainException;
use App\Modules\Inventory\Models\MaterialRequest;
use RuntimeException;

/**
 * A material request was asked to do something its current state does not
 * allow. Always an expected business-rule refusal (a 422 naming what state
 * the document is actually in), never a bug.
 */
class MaterialRequestLifecycleException extends RuntimeException implements DomainException
{
    public static function cannotSubmit(MaterialRequest $request): self
    {
        return new self(
            "Material request {$request->documentNumber()} is {$request->status->value}: only a draft can be submitted."
        );
    }

    public static function nothingToSubmit(MaterialRequest $request): self
    {
        return new self(
            "Material request {$request->documentNumber()} has no lines — there is nothing to ask the store for."
        );
    }

    public static function cannotCancel(MaterialRequest $request): self
    {
        return new self(
            "Material request {$request->documentNumber()} is {$request->status->value}: it can no longer be cancelled. "
            .'Material already issued stands in production and is returned through a store return, not by cancelling '
            .'the paperwork behind it.'
        );
    }

    public static function notOpenToTheStore(MaterialRequest $request): self
    {
        return new self(
            "Material request {$request->documentNumber()} is {$request->status->value}: only a submitted or "
            .'partially issued request can be fulfilled.'
        );
    }

    public static function lineNotOnThisRequest(MaterialRequest $request, int $lineId): self
    {
        return new self(
            "Line {$lineId} is not on material request {$request->documentNumber()} — an issue can only be recorded "
            .'against a line of the request it is fulfilling.'
        );
    }

    public function errorCode(): string
    {
        return 'material_request_lifecycle';
    }
}
