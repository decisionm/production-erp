<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Maintenance\Http\Requests\AddMaintenanceWorkOrderPartRequest;
use App\Modules\Maintenance\Http\Requests\CompleteMaintenanceWorkOrderRequest;
use App\Modules\Maintenance\Http\Requests\StoreMaintenanceWorkOrderRequest;
use App\Modules\Maintenance\Http\Resources\MaintenanceWorkOrderResource;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Services\MaintenanceWorkOrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MaintenanceWorkOrderController extends Controller
{
    public function __construct(private readonly MaintenanceWorkOrderService $workOrders) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return MaintenanceWorkOrderResource::collection($this->workOrders->paginate($request->integer('asset_id') ?: null));
    }

    public function store(StoreMaintenanceWorkOrderRequest $request): MaintenanceWorkOrderResource
    {
        return MaintenanceWorkOrderResource::make($this->workOrders->create($request->validated()));
    }

    public function addPart(AddMaintenanceWorkOrderPartRequest $request, MaintenanceWorkOrder $maintenanceWorkOrder): MaintenanceWorkOrderResource
    {
        return MaintenanceWorkOrderResource::make($this->workOrders->addPart(
            $maintenanceWorkOrder,
            (int) $request->validated('item_id'),
            (int) $request->validated('warehouse_id'),
            (string) $request->validated('quantity'),
        ));
    }

    public function start(MaintenanceWorkOrder $maintenanceWorkOrder): MaintenanceWorkOrderResource
    {
        return MaintenanceWorkOrderResource::make($this->workOrders->start($maintenanceWorkOrder));
    }

    public function complete(CompleteMaintenanceWorkOrderRequest $request, MaintenanceWorkOrder $maintenanceWorkOrder): MaintenanceWorkOrderResource
    {
        return MaintenanceWorkOrderResource::make(
            $this->workOrders->complete($maintenanceWorkOrder, (string) ($request->validated('labor_cost') ?? '0')),
        );
    }

    public function cancel(MaintenanceWorkOrder $maintenanceWorkOrder): MaintenanceWorkOrderResource
    {
        return MaintenanceWorkOrderResource::make($this->workOrders->cancel($maintenanceWorkOrder));
    }
}
