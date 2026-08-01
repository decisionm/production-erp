<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\UpdateDayBinWarehouseRequest;
use App\Modules\Production\Http\Requests\UpdateFactoryWarehousesRequest;
use App\Modules\Production\Services\FactoryDayBinService;
use App\Modules\Production\Services\FactoryWarehouseResolver;
use App\Modules\Production\Services\MachineCapabilityService;
use Illuminate\Http\JsonResponse;

class ProductionSettingsController extends Controller
{
    public function __construct(
        private readonly FactoryDayBinService $dayBin,
        private readonly MachineCapabilityService $machineCapability,
        private readonly FactoryWarehouseResolver $factoryWarehouses,
    ) {}

    /**
     * Deployment-level production settings the frontend must agree with
     * the backend about — rounding mode and tolerance bands come from
     * config/production.php, never hard-coded client-side.
     */
    public function show(): JsonResponse
    {
        return response()->json([
            'data' => [
                'packing_rounding' => config('production.packing_rounding'),
                'tolerances' => config('production.tolerances'),
                // Phase 6 master switch — the SPA renders the traceability
                // surfaces only when the backend says they exist.
                'traceability_enabled' => (bool) config('production.traceability_enabled'),
                // Which warehouse IS the factory day bin. app_settings, not
                // config — the factory names it in the app, not in a deploy.
                // null = not chosen yet: every screen then behaves exactly as
                // it did before the day bin existed, and prompts for it.
                'day_bin_warehouse_id' => $this->dayBin->warehouseId(),
                // The warehouses the floor is never asked about. These are
                // what is STORED, before any fallback — the setting screen
                // must show "not set" as not set, rather than showing the
                // single-Tally-linked warehouse the resolver would currently
                // fall back to and implying someone chose it.
                //
                // `*_resolved_warehouse_id` is what a payload would actually
                // get right now, fallback included. Publishing both is what
                // lets a screen say "nothing is set, and today that resolves
                // to X" — and, when the resolved value is null, warn that
                // Start Batch will be refused before a supervisor discovers
                // it mid-shift.
                'finished_goods_warehouse_id' => $this->factoryWarehouses->configuredFinishedGoodsWarehouseId(),
                'raw_material_warehouse_id' => $this->factoryWarehouses->configuredRawMaterialWarehouseId(),
                // The Packing Material Store (cartons, trays, film, tape).
                // Its configured and resolved values are always the same
                // figure — this is the one role with no fallback, because
                // guessing it would issue cartons out of the resin godown
                // (see FactoryWarehouseResolver::packingMaterial). Published
                // as the pair anyway so a screen can read all three roles the
                // same way, and can say "not set" about the one that blocks
                // a Tally post.
                'packing_material_warehouse_id' => $this->factoryWarehouses->configuredPackingMaterialWarehouseId(),
                'finished_goods_resolved_warehouse_id' => $this->factoryWarehouses->finishedGoods()?->id,
                'raw_material_resolved_warehouse_id' => $this->factoryWarehouses->rawMaterial()?->id,
                'packing_material_resolved_warehouse_id' => $this->factoryWarehouses->packingMaterial()?->id,
                // The factory's machine rule, published so a screen can STATE
                // which machines a product runs on. It was enforced on every
                // start and displayed nowhere, which is why the owner could not
                // find the machine mapping they had asked for: it exists as a
                // rule, not as rows. Sent as the threshold plus the machines
                // above it belong to, so the client derives the answer per
                // product from the cavity count it already has — no
                // product-machine table to create, approve, or let drift from
                // the workbook.
                'machine_capability' => [
                    'cavity_threshold' => $this->machineCapability->threshold(),
                    'restricted_machines' => $this->machineCapability->restrictedMachines(),
                ],
            ],
        ]);
    }

    /**
     * Name the factory day-bin warehouse. Same settings shape as Tally's
     * `PUT settings/company`: one named value in, the stored value echoed
     * back. Sending null clears it.
     */
    public function updateDayBinWarehouse(UpdateDayBinWarehouseRequest $request): JsonResponse
    {
        $warehouseId = $request->validated()['warehouse_id'] ?? null;
        $this->dayBin->setWarehouseId($warehouseId !== null ? (int) $warehouseId : null);

        return response()->json(['data' => ['day_bin_warehouse_id' => $this->dayBin->warehouseId()]]);
    }

    /**
     * Name the finished-goods destination and/or the raw-material source.
     *
     * This is the endpoint the resolver's 422 points at. Without it that
     * message ("name one in Production settings") would be unactionable —
     * a deployment with no single Tally-linked warehouse could be told what
     * was wrong and given no way to fix it.
     *
     * Only the keys actually sent are written, so setting one role never
     * disturbs the other; an explicit null clears a role.
     */
    public function updateFactoryWarehouses(UpdateFactoryWarehousesRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists('finished_goods_warehouse_id', $data)) {
            $value = $data['finished_goods_warehouse_id'];
            $this->factoryWarehouses->setFinishedGoodsWarehouseId($value !== null ? (int) $value : null);
        }

        if (array_key_exists('raw_material_warehouse_id', $data)) {
            $value = $data['raw_material_warehouse_id'];
            $this->factoryWarehouses->setRawMaterialWarehouseId($value !== null ? (int) $value : null);
        }

        if (array_key_exists('packing_material_warehouse_id', $data)) {
            $value = $data['packing_material_warehouse_id'];
            $this->factoryWarehouses->setPackingMaterialWarehouseId($value !== null ? (int) $value : null);
        }

        return response()->json([
            'data' => [
                'finished_goods_warehouse_id' => $this->factoryWarehouses->configuredFinishedGoodsWarehouseId(),
                'raw_material_warehouse_id' => $this->factoryWarehouses->configuredRawMaterialWarehouseId(),
                'packing_material_warehouse_id' => $this->factoryWarehouses->configuredPackingMaterialWarehouseId(),
                'finished_goods_resolved_warehouse_id' => $this->factoryWarehouses->finishedGoods()?->id,
                'raw_material_resolved_warehouse_id' => $this->factoryWarehouses->rawMaterial()?->id,
                'packing_material_resolved_warehouse_id' => $this->factoryWarehouses->packingMaterial()?->id,
            ],
        ]);
    }
}
