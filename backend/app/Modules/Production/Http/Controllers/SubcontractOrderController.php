<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\ListSubcontractOrdersRequest;
use App\Modules\Production\Http\Requests\ReceiveSubcontractOrderRequest;
use App\Modules\Production\Http\Requests\StoreSubcontractOrderRequest;
use App\Modules\Production\Http\Resources\SubcontractOrderResource;
use App\Modules\Production\Models\SubcontractOrder;
use App\Modules\Production\Services\SubcontractOrderService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SubcontractOrderController extends Controller
{
    public function __construct(private readonly SubcontractOrderService $subcontractOrders) {}

    public function index(ListSubcontractOrdersRequest $request): AnonymousResourceCollection
    {
        return SubcontractOrderResource::collection($this->subcontractOrders->paginate($request->perPage(), $request->sort()));
    }

    public function store(StoreSubcontractOrderRequest $request): SubcontractOrderResource
    {
        return SubcontractOrderResource::make($this->subcontractOrders->create($request->validated()));
    }

    public function sendMaterials(SubcontractOrder $subcontractOrder): SubcontractOrderResource
    {
        return SubcontractOrderResource::make($this->subcontractOrders->sendMaterials($subcontractOrder));
    }

    public function receive(ReceiveSubcontractOrderRequest $request, SubcontractOrder $subcontractOrder): SubcontractOrderResource
    {
        return SubcontractOrderResource::make($this->subcontractOrders->receive(
            $subcontractOrder,
            (string) $request->validated('quantity_received'),
            (string) $request->validated('service_cost'),
        ));
    }
}
