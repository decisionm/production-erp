<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\StoreMaterialLotRequest;
use App\Modules\Inventory\Http\Resources\MaterialLotResource;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Services\TraceabilityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MaterialLotController extends Controller
{
    public function __construct(private readonly TraceabilityService $traceability) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return MaterialLotResource::collection(
            $this->traceability->paginateLots($request->query('item_id') ? (int) $request->query('item_id') : null),
        );
    }

    public function store(StoreMaterialLotRequest $request): MaterialLotResource
    {
        return MaterialLotResource::make(
            $this->traceability->createLot($request->validated(), $request->user()?->id),
        );
    }

    public function show(MaterialLot $materialLot): MaterialLotResource
    {
        return MaterialLotResource::make($this->traceability->loadLot($materialLot));
    }
}
