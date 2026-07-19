<?php

namespace App\Modules\HRMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HRMS\Http\Requests\AllocateLeaveBalanceRequest;
use App\Modules\HRMS\Http\Resources\LeaveBalanceResource;
use App\Modules\HRMS\Services\LeaveBalanceService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeaveBalanceController extends Controller
{
    public function __construct(private readonly LeaveBalanceService $balances) {}

    public function index(): AnonymousResourceCollection
    {
        return LeaveBalanceResource::collection($this->balances->paginate());
    }

    public function store(AllocateLeaveBalanceRequest $request): LeaveBalanceResource
    {
        return LeaveBalanceResource::make($this->balances->allocate($request->validated()));
    }
}
