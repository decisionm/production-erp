<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\StoreShiftRequest;
use App\Modules\Production\Http\Resources\ShiftResource;
use App\Modules\Production\Services\ShiftService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ShiftController extends Controller
{
    public function __construct(private readonly ShiftService $shifts) {}

    public function index(): AnonymousResourceCollection
    {
        return ShiftResource::collection($this->shifts->paginate());
    }

    public function store(StoreShiftRequest $request): ShiftResource
    {
        return ShiftResource::make($this->shifts->create($request->validated()));
    }
}
