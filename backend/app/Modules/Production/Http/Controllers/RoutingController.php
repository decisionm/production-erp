<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\StoreRoutingRequest;
use App\Modules\Production\Http\Resources\RoutingResource;
use App\Modules\Production\Services\RoutingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RoutingController extends Controller
{
    public function __construct(private readonly RoutingService $routings) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return RoutingResource::collection($this->routings->paginate($request->integer('item_id') ?: null));
    }

    public function store(StoreRoutingRequest $request): RoutingResource
    {
        return RoutingResource::make($this->routings->create($request->validated()));
    }
}
