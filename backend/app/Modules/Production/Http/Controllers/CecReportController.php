<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\CecReportRequest;
use App\Modules\Production\Services\CecReportService;
use Illuminate\Http\JsonResponse;

/**
 * GET /production/cec — the CEC's DATA (Phase 5.7, P5.7-02): the Shift
 * Summary and the completed entries of a date composed per shift and per
 * machine, the format itself still BLOCKED — SOURCE DOCUMENT REQUIRED (the
 * response says so). Read-only; production.view suffices (module guard).
 */
class CecReportController extends Controller
{
    public function __construct(private readonly CecReportService $cec) {}

    public function __invoke(CecReportRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->cec->report($request->productionDate(), $request->shiftId()),
        ]);
    }
}
