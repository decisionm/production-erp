<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Http\Requests\ListPurchaseOrdersRequest;
use App\Modules\Procurement\Http\Requests\StorePurchaseOrderRequest;
use App\Modules\Procurement\Http\Resources\PurchaseOrderResource;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Services\ProcurementDocumentQuery;
use App\Modules\Procurement\Services\PurchaseOrderService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderService $orders,
        private readonly ProcurementDocumentQuery $query,
    ) {}

    /**
     * The list, filtered by ListPurchaseOrdersRequest (Phase 4.5); an empty
     * query string is the same unfiltered, newest-first list as before.
     * `per_page` up to 1000 so a link that points at one older order can
     * actually find it — the default first page of 20 would hide it.
     */
    public function index(ListPurchaseOrdersRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        return PurchaseOrderResource::collection($this->orders->paginate($this->query->perPage($filters), $filters));
    }

    public function store(StorePurchaseOrderRequest $request): PurchaseOrderResource
    {
        $order = $this->orders->create($request->validated(), $request->user()?->id);

        return PurchaseOrderResource::make($order);
    }

    public function send(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        return PurchaseOrderResource::make($this->orders->send($purchaseOrder));
    }
}
