<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\CompleteWorkOrderRequest;
use App\Modules\Production\Http\Requests\ListWorkOrdersRequest;
use App\Modules\Production\Http\Requests\StoreWorkOrderRequest;
use App\Modules\Production\Http\Resources\WorkOrderResource;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WorkOrderController extends Controller
{
    public function __construct(private readonly WorkOrderService $workOrders) {}

    public function index(ListWorkOrdersRequest $request): AnonymousResourceCollection
    {
        return WorkOrderResource::collection($this->workOrders->paginate($request->perPage(), $request->sort()));
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
        $scrapEntries = collect($request->validated('scrap') ?? [])
            ->map(fn (array $entry) => [
                'scrap_reason_id' => $entry['scrap_reason_id'],
                'quantity' => (string) $entry['quantity'],
                'notes' => $entry['notes'] ?? null,
            ])
            ->all();

        return WorkOrderResource::make(
            $this->workOrders->complete(
                $workOrder,
                (string) $request->validated('quantity_completed'),
                $request->validated('batch_number'),
                $scrapEntries,
            ),
        );
    }
}
