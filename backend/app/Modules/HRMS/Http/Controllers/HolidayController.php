<?php

namespace App\Modules\HRMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HRMS\Http\Requests\ListHolidaysRequest;
use App\Modules\HRMS\Http\Requests\ReplaceHolidaysRequest;
use App\Modules\HRMS\Http\Requests\StoreHolidayRequest;
use App\Modules\HRMS\Http\Resources\HolidayResource;
use App\Modules\HRMS\Models\Holiday;
use App\Modules\HRMS\Services\HolidayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * THE FACTORY'S CALENDAR.
 *
 * Read a year at a time and never paged: a year has at most a few dozen
 * holidays and a pager over them would be furniture. Everything that
 * judges a day reads this through HolidayService, not through here.
 */
class HolidayController extends Controller
{
    public function __construct(private readonly HolidayService $holidays) {}

    public function index(ListHolidaysRequest $request): AnonymousResourceCollection
    {
        $year = (int) ($request->validated('year') ?? now()->year);

        return HolidayResource::collection($this->holidays->forYear($year));
    }

    public function store(StoreHolidayRequest $request): HolidayResource
    {
        return HolidayResource::make(Holiday::create($request->validated()));
    }

    /**
     * The uploaded calendar. Returns what it CHANGED — added, renamed,
     * unchanged — because an import that only says "done" is one nobody
     * can check against the list they meant to load.
     */
    public function replace(ReplaceHolidaysRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->holidays->replaceDates($request->validated('holidays')),
        ]);
    }

    /**
     * Withdraw a holiday. Soft, because a past month's sheet was built on
     * it and a deleted row would rewrite what that month said.
     */
    public function destroy(Holiday $holiday): Response
    {
        $holiday->delete();

        return response()->noContent();
    }
}
