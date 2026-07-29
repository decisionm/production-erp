<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Http\Requests\BatchPreviewRequest;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\BatchEstimationService;
use App\Modules\Production\Services\ProductionStandardResolver;
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
        private readonly ProductionStandardResolver $standards,
    ) {}

    public function __invoke(BatchPreviewRequest $request): JsonResponse
    {
        $data = $request->validated();

        $item = Item::query()->findOrFail($data['item_id']);
        $warehouse = isset($data['warehouse_id']) ? Warehouse::query()->find($data['warehouse_id']) : null;
        $workCenter = isset($data['work_center_id']) ? WorkCenter::query()->find($data['work_center_id']) : null;
        $shift = isset($data['shift_id']) ? Shift::query()->find($data['shift_id']) : null;

        // Product standards from the factory master. Offered on every active
        // machine (watch mode) until machine-product mappings are approved.
        $variants = $this->standards->variantsFor($item->id);
        $standard = $this->standards->resolve($item->id, $data['production_standard_id'] ?? null);
        $packaging = $this->standards->resolvePackaging($standard, $data['production_standard_packaging_id'] ?? null);

        return response()->json([
            'data' => [
                'readiness' => $this->readiness->assess($item, $warehouse, $workCenter),
                'estimation' => $this->estimation->estimate(
                    $item,
                    $shift,
                    $data['planned_hours'] ?? null,
                    $data['active_cavities'] ?? $standard?->cavities,
                    $standard,
                    $packaging,
                ),
                'standard' => $standard === null ? null : [
                    'id' => $standard->id,
                    'label' => $standard->variantLabel(),
                    'cavities' => $standard->cavities,
                    'unit_weight_grams' => $standard->unit_weight_grams,
                    'cycle_time' => $standard->cycle_time,
                    'status' => $standard->status,
                    'unresolved_reason' => $standard->unresolved_reason,
                ],
                // Only a real choice is surfaced; one variant means the SPA
                // never shows a picker.
                'variants' => $variants->map(fn ($v) => [
                    'id' => $v->id,
                    'label' => $v->variantLabel(),
                    'cavities' => $v->cavities,
                    'unit_weight_grams' => $v->unit_weight_grams,
                    'cycle_time' => $v->cycle_time,
                    'status' => $v->status,
                    'packagings' => $v->packagings->map(fn ($p) => [
                        'id' => $p->id, 'mode' => $p->mode, 'label' => $p->label(),
                        'nos_per_pouch' => $p->nos_per_pouch, 'pouches_per_box' => $p->pouches_per_box,
                        'nos_per_tray' => $p->nos_per_tray, 'trays_per_box' => $p->trays_per_box,
                        'nos_per_box' => $p->nos_per_box, 'is_default' => $p->is_default,
                    ])->values(),
                ])->values(),
                'packaging' => $packaging === null ? null : [
                    'id' => $packaging->id, 'mode' => $packaging->mode, 'label' => $packaging->label(),
                    'nos_per_box' => $packaging->nos_per_box,
                ],
                'warnings' => $this->standards->warningsFor($standard, $packaging, $item->id),
            ],
        ]);
    }
}
