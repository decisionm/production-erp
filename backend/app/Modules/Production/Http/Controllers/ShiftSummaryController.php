<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\StoreShiftSummaryRequest;
use App\Modules\Production\Http\Resources\ShiftSummaryResource;
use App\Modules\Production\Services\ShiftSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftSummaryController extends Controller
{
    public function __construct(private readonly ShiftSummaryService $summaries) {}

    public function store(StoreShiftSummaryRequest $request): ShiftSummaryResource
    {
        return ShiftSummaryResource::make(
            $this->summaries->upsert($request->validated(), $request->user()?->id),
        );
    }

    public function report(Request $request): JsonResponse
    {
        $request->validate([
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'production_date' => ['required', 'date'],
        ]);

        return response()->json([
            'data' => $this->summaries->report((int) $request->query('shift_id'), $request->query('production_date')),
        ]);
    }
}
