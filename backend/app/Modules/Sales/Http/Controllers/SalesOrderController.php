<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Http\Requests\ListSalesOrdersRequest;
use App\Modules\Sales\Http\Requests\StoreSalesOrderRequest;
use App\Modules\Sales\Http\Resources\SalesOrderResource;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesDocumentQuery;
use App\Modules\Sales\Services\SalesOrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SalesOrderController extends Controller
{
    public function __construct(
        private readonly SalesOrderService $orders,
        private readonly SalesDocumentQuery $query,
    ) {}

    public function index(ListSalesOrdersRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        return SalesOrderResource::collection($this->orders->paginate($this->query->perPage($filters), $filters));
    }

    public function show(SalesOrder $salesOrder): SalesOrderResource
    {
        return SalesOrderResource::make($this->orders->show($salesOrder));
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

    /**
     * Cancelling also gives up the order's stock holds and withdraws its
     * open production requests (S6), so WHO did it is recorded on every hold
     * it released — hence the user, which a plain cancel never needed.
     */
    public function cancel(Request $request, SalesOrder $salesOrder): SalesOrderResource
    {
        return SalesOrderResource::make($this->orders->cancel($salesOrder, $request->user()?->id));
    }
}
