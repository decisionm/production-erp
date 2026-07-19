<?php

namespace App\Modules\Quality\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Quality\Http\Requests\StoreSpcCharacteristicRequest;
use App\Modules\Quality\Http\Resources\SpcCharacteristicResource;
use App\Modules\Quality\Services\SpcCharacteristicService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SpcCharacteristicController extends Controller
{
    public function __construct(private readonly SpcCharacteristicService $characteristics) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return SpcCharacteristicResource::collection($this->characteristics->paginate($request->integer('item_id') ?: null));
    }

    public function store(StoreSpcCharacteristicRequest $request): SpcCharacteristicResource
    {
        return SpcCharacteristicResource::make($this->characteristics->create($request->validated()));
    }
}
