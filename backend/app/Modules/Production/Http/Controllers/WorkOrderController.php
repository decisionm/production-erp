<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\CompleteWorkOrderRequest;
use App\Modules\Production\Http\Requests\StoreWorkOrderRequest;
use App\Modules\Production\Http\Resources\WorkOrderResource;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WorkOrderController extends Controller
{
    public function __construct(private readonly WorkOrderService $workOrders) {}

    public function index(): AnonymousResourceCollection
    {
        return WorkOrderResource::collection($this->workOrders->paginate());
    }

    public function store(StoreWorkOrderRequest $request): WorkOrderResource
    {
        return WorkOrderResource::make($this->workOrders->create($request->validated()));
    }

    public function release(WorkOrder $workOrder): WorkOrderResource
    {
        return WorkOrderResource::make($this->workOrders->release($workOrder));
    }

    public function complete(CompleteWorkOrderRequest $request, WorkOrder $workOrder): WorkOrderResource
    {
        return WorkOrderResource::make(
            $this->workOrders->complete(
                $workOrder,
                (string) $request->validated('quantity_completed'),
                $request->validated('batch_number'),
            ),
        );
    }
}
