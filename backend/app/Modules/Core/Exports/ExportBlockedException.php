<?php

namespace App\Modules\Core\Exports;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * The kind exists, the reader may run it, and it is deliberately not
 * runnable — the CEC slot until the owner's sample document exists. The
 * reason is the kind's own words, verbatim; the endpoint answers 409 with
 * it. Not a DomainException (those render 422): a blocked export is not a
 * fault in the request, it is the state of the kind.
 */
class ExportBlockedException extends RuntimeException
{
    public function __construct(public readonly ExportKind $kind, string $reason)
    {
        parent::__construct($reason);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'kind' => $this->kind->key(),
        ], 409);
    }
}
