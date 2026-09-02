<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\ListSerialNumbersRequest;
use App\Modules\Inventory\Http\Requests\StoreSerialNumberRequest;
use App\Modules\Inventory\Http\Resources\SerialNumberResource;
use App\Modules\Inventory\Models\SerialNumber;
use App\Modules\Inventory\Services\SerialNumberService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SerialNumberController extends Controller
{
    public function __construct(private readonly SerialNumberService $serialNumbers) {}

    /** Same four parameters as the batch list, for the same reasons. */
    public function index(ListSerialNumbersRequest $request): AnonymousResourceCollection
    {
        return SerialNumberResource::collection($this->serialNumbers->paginate(
            itemId: $this->filterId($request, 'item_id'),
            perPage: $this->perPage($request),
            search: $this->searchTerm($request),
            code: $this->searchTerm($request, 'code'),
            sort: $request->sort(),
        ));
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
