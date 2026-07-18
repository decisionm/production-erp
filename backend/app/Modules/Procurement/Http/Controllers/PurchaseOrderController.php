<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Http\Requests\StorePurchaseOrderRequest;
use App\Modules\Procurement\Http\Resources\PurchaseOrderResource;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Services\PurchaseOrderService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchaseOrderService $orders) {}

    public function index(): AnonymousResourceCollection
    {
        return PurchaseOrderResource::collection($this->orders->paginate());
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
