<?php

namespace App\Modules\HRMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HRMS\Http\Requests\StoreLeaveTypeRequest;
use App\Modules\HRMS\Http\Requests\UpdateLeaveTypeRequest;
use App\Modules\HRMS\Http\Resources\LeaveTypeResource;
use App\Modules\HRMS\Models\LeaveType;
use App\Modules\HRMS\Services\LeaveTypeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeaveTypeController extends Controller
{
    public function __construct(private readonly LeaveTypeService $leaveTypes) {}

    /**
     * The leave type list. `per_page` is honoured so a PICKER can ask for the
     * whole master: its dropdown offers ACTIVE rows only now, and
     * filtering the first 20 would hide part of a list that was already
     * truncated (the item/vendor picker defect, 12-Aug). The default is
     * unchanged for every other caller.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return LeaveTypeResource::collection($this->leaveTypes->paginate($this->perPage($request)));
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
