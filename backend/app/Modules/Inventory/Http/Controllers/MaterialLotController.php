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
        $validated = $request->validate([
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
            'grn_id' => ['nullable', 'integer', 'exists:goods_receipt_notes,id'],
        ]);

        return MaterialLotResource::collection(
            $this->traceability->paginateLots(
                isset($validated['item_id']) ? (int) $validated['item_id'] : null,
                isset($validated['grn_id']) ? (int) $validated['grn_id'] : null,
            ),
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
