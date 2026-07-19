<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\CapacityLoadReportRequest;
use App\Modules\Production\Services\CapacityPlanService;
use Illuminate\Http\JsonResponse;

class CapacityPlanController extends Controller
{
    public function __construct(private readonly CapacityPlanService $capacityPlan) {}

    public function loadReport(CapacityLoadReportRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->capacityPlan->loadReport(
                (string) $request->validated('start_date'),
                (string) $request->validated('end_date'),
            ),
        ]);
    }
}
