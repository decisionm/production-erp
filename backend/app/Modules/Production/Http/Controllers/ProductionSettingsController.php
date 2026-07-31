<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\UpdateDayBinWarehouseRequest;
use App\Modules\Production\Services\FactoryDayBinService;
use Illuminate\Http\JsonResponse;

class ProductionSettingsController extends Controller
{
    public function __construct(private readonly FactoryDayBinService $dayBin) {}

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
}
