<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\ReportDamagedFinishedGoodsRequest;
use App\Modules\Inventory\Services\DamagedFinishedGoodService;
use Illuminate\Http\JsonResponse;

/**
 * The Store reports finished goods as damaged; they go to Quality
 * (DEC-20260901-006).
 *
 * There is no index here on purpose. What is standing in quality hold is one
 * question with one answer, and it is already served by
 * `quality/returned-material-holds` — a second read would be a second place
 * for the same figure to be wrong.
 */
class DamagedFinishedGoodController extends Controller
{
    public function __construct(private readonly DamagedFinishedGoodService $service) {}

    public function store(ReportDamagedFinishedGoodsRequest $request): JsonResponse
    {
        $reported = $this->service->report(
            lines: $request->validated('lines'),
            fromWarehouseId: (int) $request->validated('from_warehouse_id'),
            reportedBy: (int) $request->user()->id,
            notes: $request->validated('notes'),
        );

        return response()->json(['data' => $reported], 201);
    }
}
