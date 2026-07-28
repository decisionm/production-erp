<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\ProductionReportRequest;
use App\Modules\Production\Http\Requests\ReconciliationReportRequest;
use App\Modules\Production\Http\Requests\TraceabilityReportRequest;
use App\Modules\Production\Services\ProductionReportService;
use Illuminate\Http\JsonResponse;

/**
 * Read-only report endpoints (reports wave). GET under module:production,
 * so production.view is enough — no manage permission needed to read.
 * The traceability report additionally sits behind the 'traceability'
 * middleware (404 while production.traceability_enabled is off).
 */
class ProductionReportController extends Controller
{
    public function __construct(private readonly ProductionReportService $reports) {}

    public function production(ProductionReportRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return response()->json(['data' => $this->reports->productionReport(
            $validated['date'],
            isset($validated['shift_id']) ? (int) $validated['shift_id'] : null,
            isset($validated['work_center_id']) ? (int) $validated['work_center_id'] : null,
        )]);
    }

    public function reconciliation(ReconciliationReportRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return response()->json(['data' => $this->reports->reconciliationReport(
            $validated['date_from'],
            $validated['date_to'],
            isset($validated['shift_id']) ? (int) $validated['shift_id'] : null,
        )]);
    }

    public function traceability(TraceabilityReportRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return response()->json(['data' => $this->reports->traceabilityReport(
            isset($validated['lot_id']) ? (int) $validated['lot_id'] : null,
            isset($validated['item_id']) ? (int) $validated['item_id'] : null,
            $validated['date_from'],
            $validated['date_to'],
        )]);
    }
}
