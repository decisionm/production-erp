<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\StoreWarehouseRequest;
use App\Modules\Inventory\Http\Requests\UpdateWarehouseRequest;
use App\Modules\Inventory\Http\Resources\WarehouseResource;
use App\Modules\Inventory\Models\Warehouse;
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

    public function index(Request $request): AnonymousResourceCollection
    {
        return WarehouseResource::collection($this->warehouses->paginate($this->perPage($request)));
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
