<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\ShiftSummaryReportRequest;
use App\Modules\Production\Http\Requests\StoreShiftSummaryRequest;
use App\Modules\Production\Http\Resources\ShiftSummaryResource;
use App\Modules\Production\Services\ShiftSummaryService;
use Illuminate\Http\JsonResponse;

class ShiftSummaryController extends Controller
{
    public function __construct(private readonly ShiftSummaryService $summaries) {}

    public function store(StoreShiftSummaryRequest $request): ShiftSummaryResource
    {
        return ShiftSummaryResource::make(
            $this->summaries->upsert($request->validated(), $request->user()?->id),
        );
    }

    public function report(ShiftSummaryReportRequest $request): JsonResponse
    {
        // Validated by the ONE grammar the shift_summary export shares.
        $shiftId = $request->query('shift_id') ? (int) $request->query('shift_id') : null;

        return response()->json([
            'data' => $this->summaries->report($shiftId, $request->query('production_date')),
        ]);
    }
}
