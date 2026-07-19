<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Maintenance\Http\Requests\ReliabilityReportRequest;
use App\Modules\Maintenance\Services\MaintenanceReportService;
use Illuminate\Http\JsonResponse;

class MaintenanceReportController extends Controller
{
    public function __construct(private readonly MaintenanceReportService $reports) {}

    public function reliability(ReliabilityReportRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->reports->reliability((int) $request->validated('asset_id')),
        ]);
    }
}
