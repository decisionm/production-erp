<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Http\Requests\StoreSalesOrderRequest;
use App\Modules\Sales\Http\Resources\SalesOrderResource;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SalesOrderController extends Controller
{
    public function __construct(private readonly SalesOrderService $orders) {}

    public function index(): AnonymousResourceCollection
    {
        return SalesOrderResource::collection($this->orders->paginate());
    }

    public function store(StoreSalesOrderRequest $request): SalesOrderResource
    {
        $order = $this->orders->create($request->validated(), $request->user()?->id);

        return SalesOrderResource::make($order);
    }

    public function confirm(SalesOrder $salesOrder): SalesOrderResource
    {
        return SalesOrderResource::make($this->orders->confirm($salesOrder));
    }
}
