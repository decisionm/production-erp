<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\FactoryLookupRequest;
use App\Modules\Inventory\Services\FactoryLookupService;
use Illuminate\Http\JsonResponse;

/**
 * WHAT IS THIS NUMBER? — one read, and there is no write here by design.
 *
 * Deliberately NOT inside the `traceability` middleware group, though two of
 * the six identifier spaces it covers are: the service reads the flag and
 * reports bags and lots as omitted. Behind the middleware the whole question
 * would 404 on a flag-off instance, which reads as "that number is unknown"
 * rather than "the ERP did not look".
 */
class FactoryLookupController extends Controller
{
    public function __construct(private readonly FactoryLookupService $lookup) {}

    public function __invoke(FactoryLookupRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->lookup->find((string) $request->validated('q'))]);
    }
}
