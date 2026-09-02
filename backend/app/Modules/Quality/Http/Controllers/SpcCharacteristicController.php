<?php

namespace App\Modules\Quality\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Quality\Http\Requests\ListSpcCharacteristicsRequest;
use App\Modules\Quality\Http\Requests\StoreSpcCharacteristicRequest;
use App\Modules\Quality\Http\Resources\SpcCharacteristicResource;
use App\Modules\Quality\Services\SpcCharacteristicService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SpcCharacteristicController extends Controller
{
    public function __construct(private readonly SpcCharacteristicService $characteristics) {}

    public function index(ListSpcCharacteristicsRequest $request): AnonymousResourceCollection
    {
        return SpcCharacteristicResource::collection($this->characteristics->paginate(
            itemId: $request->itemId(),
            perPage: $request->perPage(),
            sort: $request->sort(),
        ));
    }

    public function store(StoreSpcCharacteristicRequest $request): SpcCharacteristicResource
    {
        return SpcCharacteristicResource::make($this->characteristics->create($request->validated()));
    }
}
