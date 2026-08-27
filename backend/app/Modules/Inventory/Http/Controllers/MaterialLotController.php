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
        /*
         * THE DATE FILTER IS SERVER-SIDE, and it has to be: this register is
         * paginated, so narrowing it in the browser would filter the page that
         * had already arrived while the pager went on reporting the whole
         * total. "Which resin came in on the 14th" must search the register,
         * not the twenty rows in front of you.
         */
        $validated = $request->validate([
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
            'grn_id' => ['nullable', 'integer', 'exists:goods_receipt_notes,id'],
            'received_from' => ['nullable', 'date'],
            'received_to' => ['nullable', 'date', 'after_or_equal:received_from'],
            'order' => ['nullable', 'in:newest,oldest'],
        ]);

        return MaterialLotResource::collection(
            $this->traceability->paginateLots(
                isset($validated['item_id']) ? (int) $validated['item_id'] : null,
                isset($validated['grn_id']) ? (int) $validated['grn_id'] : null,
                receivedFrom: $validated['received_from'] ?? null,
                receivedTo: $validated['received_to'] ?? null,
                order: $validated['order'] ?? 'newest',
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
