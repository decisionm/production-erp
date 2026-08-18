<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Http\Requests\ListDeliveriesRequest;
use App\Modules\Sales\Http\Requests\StoreDeliveryRequest;
use App\Modules\Sales\Http\Resources\DeliveryResource;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Services\DeliveryService;
use App\Modules\Sales\Services\SalesDocumentQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DeliveryController extends Controller
{
    public function __construct(
        private readonly DeliveryService $deliveries,
        private readonly SalesDocumentQuery $query,
    ) {}

    public function index(ListDeliveriesRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        return DeliveryResource::collection($this->deliveries->paginate($this->query->perPage($filters), $filters));
    }

    public function show(Delivery $delivery): DeliveryResource
    {
        return DeliveryResource::make($this->deliveries->show($delivery));
    }

    public function store(StoreDeliveryRequest $request): DeliveryResource
    {
        $delivery = $this->deliveries->create($request->validated(), $request->user()?->id);

        return DeliveryResource::make($delivery);
    }
}
