<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\StoreWarehouseRequest;
use App\Modules\Inventory\Http\Requests\UpdateWarehouseRequest;
use App\Modules\Inventory\Http\Resources\WarehouseResource;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\WarehouseService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WarehouseController extends Controller
{
    public function __construct(private readonly WarehouseService $warehouses) {}

    public function index(): AnonymousResourceCollection
    {
        return WarehouseResource::collection($this->warehouses->paginate());
    }

    public function store(StoreWarehouseRequest $request): WarehouseResource
    {
        return WarehouseResource::make($this->warehouses->create($request->validated()));
    }

    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse): WarehouseResource
    {
        return WarehouseResource::make($this->warehouses->update($warehouse, $request->validated()));
    }
}
