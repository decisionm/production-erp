<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\StoreSerialNumberRequest;
use App\Modules\Inventory\Http\Resources\SerialNumberResource;
use App\Modules\Inventory\Models\SerialNumber;
use App\Modules\Inventory\Services\SerialNumberService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SerialNumberController extends Controller
{
    public function __construct(private readonly SerialNumberService $serialNumbers) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return SerialNumberResource::collection($this->serialNumbers->paginate($request->integer('item_id') ?: null));
    }

    public function store(StoreSerialNumberRequest $request): SerialNumberResource
    {
        return SerialNumberResource::make($this->serialNumbers->create($request->validated()));
    }

    public function history(SerialNumber $serialNumber): SerialNumberResource
    {
        return SerialNumberResource::make($this->serialNumbers->history($serialNumber));
    }
}
