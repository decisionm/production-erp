<?php

namespace App\Modules\HRMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HRMS\Http\Requests\StoreLeaveTypeRequest;
use App\Modules\HRMS\Http\Requests\UpdateLeaveTypeRequest;
use App\Modules\HRMS\Http\Resources\LeaveTypeResource;
use App\Modules\HRMS\Models\LeaveType;
use App\Modules\HRMS\Services\LeaveTypeService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeaveTypeController extends Controller
{
    public function __construct(private readonly LeaveTypeService $leaveTypes) {}

    public function index(): AnonymousResourceCollection
    {
        return LeaveTypeResource::collection($this->leaveTypes->paginate());
    }

    public function store(StoreLeaveTypeRequest $request): LeaveTypeResource
    {
        return LeaveTypeResource::make($this->leaveTypes->create($request->validated()));
    }

    public function update(UpdateLeaveTypeRequest $request, LeaveType $leaveType): LeaveTypeResource
    {
        return LeaveTypeResource::make($this->leaveTypes->update($leaveType, $request->validated()));
    }
}
