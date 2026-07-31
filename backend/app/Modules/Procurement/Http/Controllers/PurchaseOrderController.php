<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Http\Requests\StorePurchaseOrderRequest;
use App\Modules\Procurement\Http\Resources\PurchaseOrderResource;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Services\PurchaseOrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchaseOrderService $orders) {}

    /**
     * `per_page` so a link that points at one older order can actually find
     * it — the default first page of 20 would hide it.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return PurchaseOrderResource::collection($this->orders->paginate($this->perPage($request)));
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
