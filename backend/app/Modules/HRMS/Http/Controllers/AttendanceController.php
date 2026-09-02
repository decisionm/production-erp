<?php

namespace App\Modules\HRMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HRMS\Http\Requests\ListAttendanceRequest;
use App\Modules\HRMS\Http\Requests\MarkAttendanceRequest;
use App\Modules\HRMS\Http\Resources\AttendanceResource;
use App\Modules\HRMS\Services\AttendanceService;
use App\Modules\HRMS\Services\HrmsListQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance) {}

    public function index(ListAttendanceRequest $request, HrmsListQuery $query): AnonymousResourceCollection
    {
        $filters = $request->validated();

        return AttendanceResource::collection($this->attendance->paginate($query->perPage($filters), $filters));
    }

    public function mark(MarkAttendanceRequest $request): AttendanceResource
    {
        return AttendanceResource::make($this->attendance->mark($request->validated()));
    }
}
