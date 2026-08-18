<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Services\TallyMirrorStatementService;
use Illuminate\Http\JsonResponse;

/**
 * GET /sales/tally-mirror — the honesty statement the Sales pages render
 * (Phase 3.5). A bare object, not a resource: it describes no row.
 */
class SalesTallyMirrorController extends Controller
{
    public function __construct(private readonly TallyMirrorStatementService $statement) {}

    public function show(): JsonResponse
    {
        return response()->json($this->statement->statement());
    }
}
