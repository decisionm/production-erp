<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Http\Requests\StoreDeliveryRequest;
use App\Modules\Sales\Http\Resources\DeliveryResource;
use App\Modules\Sales\Services\DeliveryService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DeliveryController extends Controller
{
    public function __construct(private readonly DeliveryService $deliveries) {}

    public function index(): AnonymousResourceCollection
    {
        return DeliveryResource::collection($this->deliveries->paginate());
    }

    public function store(StoreDeliveryRequest $request): DeliveryResource
    {
        $delivery = $this->deliveries->create($request->validated(), $request->user()?->id);

        return DeliveryResource::make($delivery);
    }
}
