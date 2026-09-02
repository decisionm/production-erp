<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\ListMaterialBagsRequest;
use App\Modules\Inventory\Http\Resources\MaterialBagResource;
use App\Modules\Inventory\Services\TraceabilityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MaterialBagController extends Controller
{
    public function __construct(private readonly TraceabilityService $traceability) {}

    public function index(ListMaterialBagsRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();

        return MaterialBagResource::collection($this->traceability->paginateBags(
            ! empty($validated['item_id']) ? (int) $validated['item_id'] : null,
            $validated['status'] ?? null,
            // Read for the first time (03-Sep-2026): the bench's pager asked
            // for a size the server never heard.
            (int) ($validated['per_page'] ?? 20),
            $request->sort(),
        ));
    }

    /**
     * FIFO pick list — oldest received_date first, then bag sequence: the
     * suggestion the supervisor's scan is checked against.
     */
    public function pickList(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'item_id' => ['required', 'integer', 'exists:items,id'],
        ]);

        return MaterialBagResource::collection(
            $this->traceability->pickList((int) $validated['item_id']),
        );
    }
}
