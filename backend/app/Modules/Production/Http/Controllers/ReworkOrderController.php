<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\CompleteReworkOrderRequest;
use App\Modules\Production\Http\Requests\StoreReworkOrderRequest;
use App\Modules\Production\Http\Resources\ReworkOrderResource;
use App\Modules\Production\Models\ReworkOrder;
use App\Modules\Production\Services\ReworkOrderService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReworkOrderController extends Controller
{
    public function __construct(private readonly ReworkOrderService $reworkOrders) {}

    public function index(): AnonymousResourceCollection
    {
        return ReworkOrderResource::collection($this->reworkOrders->paginate());
    }

    public function store(StoreReworkOrderRequest $request): ReworkOrderResource
    {
        return ReworkOrderResource::make($this->reworkOrders->create($request->validated()));
    }

    public function release(ReworkOrder $reworkOrder): ReworkOrderResource
    {
        return ReworkOrderResource::make($this->reworkOrders->release($reworkOrder));
    }

    public function complete(CompleteReworkOrderRequest $request, ReworkOrder $reworkOrder): ReworkOrderResource
    {
        return ReworkOrderResource::make($this->reworkOrders->complete(
            $reworkOrder,
            (string) $request->validated('quantity_recovered'),
            (string) $request->validated('labor_cost'),
        ));
    }
}
