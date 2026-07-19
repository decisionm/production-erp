<?php

namespace App\Modules\HRMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HRMS\Http\Requests\MarkAttendanceRequest;
use App\Modules\HRMS\Http\Resources\AttendanceResource;
use App\Modules\HRMS\Services\AttendanceService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance) {}

    public function index(): AnonymousResourceCollection
    {
        return AttendanceResource::collection($this->attendance->paginate());
    }

    public function mark(MarkAttendanceRequest $request): AttendanceResource
    {
        return AttendanceResource::make($this->attendance->mark($request->validated()));
    }
}
