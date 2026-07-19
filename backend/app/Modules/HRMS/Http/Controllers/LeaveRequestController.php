<?php

namespace App\Modules\HRMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HRMS\Http\Requests\StoreLeaveRequestRequest;
use App\Modules\HRMS\Http\Resources\LeaveRequestResource;
use App\Modules\HRMS\Models\LeaveRequest;
use App\Modules\HRMS\Services\LeaveRequestService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeaveRequestController extends Controller
{
    public function __construct(private readonly LeaveRequestService $leaveRequests) {}

    public function index(): AnonymousResourceCollection
    {
        return LeaveRequestResource::collection($this->leaveRequests->paginate());
    }

    public function store(StoreLeaveRequestRequest $request): LeaveRequestResource
    {
        return LeaveRequestResource::make($this->leaveRequests->create($request->validated()));
    }

    public function approve(Request $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        return LeaveRequestResource::make($this->leaveRequests->approve($leaveRequest, $request->user()?->id));
    }

    public function reject(Request $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        return LeaveRequestResource::make($this->leaveRequests->reject($leaveRequest, $request->user()?->id));
    }
}
