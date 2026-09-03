<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\ListWarehousesRequest;
use App\Modules\Inventory\Http\Requests\StoreWarehouseRequest;
use App\Modules\Inventory\Http\Requests\UpdateWarehouseRequest;
use App\Modules\Inventory\Http\Resources\WarehouseResource;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use App\Modules\Inventory\Services\QualityHoldLocationResolver;
use App\Modules\Inventory\Services\WarehouseService;
use App\Support\Configuration\Concerns\ServesConfigurationLifecycle;
use App\Support\Configuration\Http\ConfigurationReasonRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * THE REFERENCE SHAPE for a configuration master's endpoints
 * (DEC-20260817-002; docs/engineering/CONFIGURATION-LIFECYCLE-WIRING.md).
 * Every other master copies these actions verbatim:
 *
 *   GET    <module>/<resource>                 index    — cheap `can`, delete: null
 *   GET    <module>/<resource>/{id}            show     — AUTHORITATIVE `can`
 *   POST   <module>/<resource>                 store
 *   PUT    <module>/<resource>/{id}            update
 *   DELETE <module>/<resource>/{id}            hard delete, Super Admin / Owner
 *   POST   <module>/<resource>/{id}/archive    reason optional, reversible
 *   POST   <module>/<resource>/{id}/activate   reason optional
 *
 * The controller stays thin in the usual way — it holds no policy. What a
 * given user may do to a given record is answered once, by the Service (and
 * behind it ConfigurationLifecycle), and printed as `can`.
 */
class WarehouseController extends Controller
{
    use ServesConfigurationLifecycle;

    public function __construct(private readonly WarehouseService $warehouses) {}

    public function index(ListWarehousesRequest $request): AnonymousResourceCollection
    {
        return WarehouseResource::collection($this->warehouses->paginate(
            $this->perPage($request),
            $this->searchTerm($request),
            $request->sort(),
        ))->additional([
            'meta' => [
                // WHICH ROW IS PRODUCTION/WIP (DEC-20260817-001), resolved
                // once for the whole page rather than per row. The receiving
                // form reads this to keep the WIP row out of its picker —
                // the server refuses it anyway (StoreGoodsReceiptRequest);
                // this is the same rule offered before the mistake instead
                // of after it. Null when nothing resolves.
                'production_wip_warehouse_id' => app(ProductionWipLocationResolver::class)->warehouseId(),
                // AND WHICH ROW IS QUALITY HOLD (DEC-20260901-003), for the
                // same two reasons: the returns screen keeps it out of the
                // destination picker (a good return may never be sent to the
                // hold), and names it as the destination of a damaged line so
                // the row says where its material is actually going. Null when
                // nothing resolves — which on live is the state today, and the
                // screen must read that as "not configured" rather than
                // printing an id.
                'quality_hold_warehouse_id' => app(QualityHoldLocationResolver::class)->warehouseId(),
            ],
        ]);
    }

    /**
     * One warehouse by id — and the only read where `can.delete` is answered
     * for real. The confirm dialog fetches this before offering Delete.
     */
    public function show(Request $request, Warehouse $warehouse): WarehouseResource
    {
        return WarehouseResource::make($this->withAbilities($warehouse, $this->warehouses, $request));
    }

    public function store(StoreWarehouseRequest $request): WarehouseResource
    {
        return WarehouseResource::make($this->warehouses->create($request->validated()));
    }

    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse): WarehouseResource
    {
        return WarehouseResource::make($this->warehouses->update($warehouse, $request->validated()));
    }

    /**
     * Hard delete — 422 with counts when anything references the warehouse,
     * 403 for anyone below the hard-delete tier. Both refusals come out of
     * the Service; nothing is decided here.
     */
    public function destroy(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->warehouses->delete($warehouse, $request->user());

        return response()->json(null, 204);
    }

    /** Take out of service. Reversible, deletes nothing, no Tally mutation. */
    public function archive(ConfigurationReasonRequest $request, Warehouse $warehouse): WarehouseResource
    {
        $archived = $this->runLifecycleAction(
            fn () => $this->warehouses->archive($warehouse, $request->reason()),
        );

        return WarehouseResource::make($this->withAbilities($archived, $this->warehouses, $request));
    }

    /** Put an archived warehouse back in service. */
    public function activate(ConfigurationReasonRequest $request, Warehouse $warehouse): WarehouseResource
    {
        $activated = $this->runLifecycleAction(
            fn () => $this->warehouses->activate($warehouse, $request->reason()),
        );

        return WarehouseResource::make($this->withAbilities($activated, $this->warehouses, $request));
    }
}
