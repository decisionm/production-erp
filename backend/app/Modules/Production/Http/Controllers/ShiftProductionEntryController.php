<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\StoreShiftProductionEntryRequest;
use App\Modules\Production\Http\Resources\ShiftProductionEntryResource;
use App\Modules\Production\Services\ShiftProductionEntryService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ShiftProductionEntryController extends Controller
{
    public function __construct(private readonly ShiftProductionEntryService $entries) {}

    public function index(): AnonymousResourceCollection
    {
        return ShiftProductionEntryResource::collection($this->entries->paginate());
    }

    public function store(StoreShiftProductionEntryRequest $request): ShiftProductionEntryResource
    {
        return ShiftProductionEntryResource::make(
            $this->entries->create($request->validated(), $request->user()?->id),
        );
    }
}
