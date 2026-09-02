<?php

namespace App\Modules\HRMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HRMS\Http\Requests\AllocateLeaveBalanceRequest;
use App\Modules\HRMS\Http\Requests\ListLeaveBalancesRequest;
use App\Modules\HRMS\Http\Resources\LeaveBalanceResource;
use App\Modules\HRMS\Services\HrmsListQuery;
use App\Modules\HRMS\Services\LeaveBalanceService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeaveBalanceController extends Controller
{
    public function __construct(private readonly LeaveBalanceService $balances) {}

    /** The list, sorted and paged by ListLeaveBalancesRequest; an empty query string is the list every earlier caller got. */
    public function index(ListLeaveBalancesRequest $request, HrmsListQuery $query): AnonymousResourceCollection
    {
        $filters = $request->validated();

        return LeaveBalanceResource::collection($this->balances->paginate($query->perPage($filters), $filters['sort'] ?? null));
    }

    public function store(AllocateLeaveBalanceRequest $request): LeaveBalanceResource
    {
        return LeaveBalanceResource::make($this->balances->allocate($request->validated()));
    }
}
