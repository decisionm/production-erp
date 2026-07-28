<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Resources\MaterialBagResource;
use App\Modules\Inventory\Services\TraceabilityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MaterialBagController extends Controller
{
    public function __construct(private readonly TraceabilityService $traceability) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return MaterialBagResource::collection($this->traceability->paginateBags(
            $request->query('item_id') ? (int) $request->query('item_id') : null,
            $request->query('status'),
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
