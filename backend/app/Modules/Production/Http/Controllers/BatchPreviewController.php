<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Http\Requests\BatchPreviewRequest;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\BatchEstimationService;
use App\Modules\Production\Services\ProductReadinessService;
use Illuminate\Http\JsonResponse;

/**
 * The Start Batch preview: readiness + estimation in one call, so the
 * supervisor sees whether the product CAN run and what it SHOULD produce
 * before confirming — never after.
 *
 * Read-only by construction: nothing here writes, so the SPA can call it on
 * every product/machine change without side effects.
 */
class BatchPreviewController extends Controller
{
    public function __construct(
        private readonly ProductReadinessService $readiness,
        private readonly BatchEstimationService $estimation,
    ) {}

    public function __invoke(BatchPreviewRequest $request): JsonResponse
    {
        $data = $request->validated();

        $item = Item::query()->findOrFail($data['item_id']);
        $warehouse = isset($data['warehouse_id']) ? Warehouse::query()->find($data['warehouse_id']) : null;
        $workCenter = isset($data['work_center_id']) ? WorkCenter::query()->find($data['work_center_id']) : null;
        $shift = isset($data['shift_id']) ? Shift::query()->find($data['shift_id']) : null;

        return response()->json([
            'data' => [
                'readiness' => $this->readiness->assess($item, $warehouse, $workCenter),
                'estimation' => $this->estimation->estimate(
                    $item,
                    $shift,
                    $data['planned_hours'] ?? null,
                    $data['active_cavities'] ?? null,
                ),
            ],
        ]);
    }
}
