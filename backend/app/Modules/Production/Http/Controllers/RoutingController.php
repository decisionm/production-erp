<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\ListRoutingsRequest;
use App\Modules\Production\Http\Requests\StoreRoutingRequest;
use App\Modules\Production\Http\Resources\RoutingResource;
use App\Modules\Production\Services\RoutingService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RoutingController extends Controller
{
    public function __construct(private readonly RoutingService $routings) {}

    public function index(ListRoutingsRequest $request): AnonymousResourceCollection
    {
        return RoutingResource::collection($this->routings->paginate($request->itemId(), $request->perPage(), $request->sort()));
    }

    public function store(StoreRoutingRequest $request): RoutingResource
    {
        return RoutingResource::make($this->routings->create($request->validated()));
    }
}
