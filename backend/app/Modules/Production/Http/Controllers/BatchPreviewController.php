<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Http\Requests\BatchPreviewRequest;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\BatchEstimationService;
use App\Modules\Production\Services\ProductionConfigurationService;
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
        private readonly ProductionConfigurationService $configurations,
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

        // The approved machine–product configuration, once a machine is named.
        // Same precedence as startBatch's snapshot: configuration → standard →
        // item. Without this the preview quoted the standard's figures while
        // the batch ran the machine's own — the screen disagreeing with the
        // gate, which is the one thing a preview must never do.
        $configuration = $workCenter !== null
            ? $this->configurations->resolve($workCenter->id, $item->id)
            : null;

        return response()->json([
            'data' => [
                // Assessed against the SAME resolved standard/packaging the
                // estimation below uses, so the two halves of this response
                // can never contradict each other.
                'readiness' => $this->readiness->assess($item, $warehouse, $workCenter, $standard, $packaging, $configuration),
                'estimation' => $this->estimation->estimate(
                    $item,
                    $shift,
                    $data['planned_hours'] ?? null,
                    $data['active_cavities'] ?? $configuration?->default_cavities ?? $standard?->cavities,
                    $standard,
                    $packaging,
                    $configuration,
                ),
                // Named so the screen can SAY the machine's own approved
                // figures are in use, rather than leaving the supervisor to
                // wonder why the numbers differ from the standards card.
                'configuration' => $configuration === null ? null : [
                    'id' => $configuration->id,
                    'default_cycle_time' => $configuration->default_cycle_time,
                    'default_cavities' => $configuration->default_cavities,
                    'unit_weight_grams' => $configuration->unit_weight_grams,
                    'colour' => $configuration->colour,
                ],
                'standard' => $standard === null ? null : [
                    'id' => $standard->id,
                    'label' => $standard->variantLabel(),
                    'cavities' => $standard->cavities,
                    'unit_weight_grams' => $standard->unit_weight_grams,
                    'cycle_time' => $standard->cycle_time,
                    'status' => $standard->status,
                    'unresolved_reason' => $standard->unresolved_reason,
                    // Packaging-material specs from the master's three
                    // right-hand columns. Reference only — free text like
                    // "750*610" (a film in mm), never a count, so nothing
                    // downstream computes from them.
                    'carton_spec' => $standard->carton_spec,
                    'tray_spec' => $standard->tray_spec,
                    'pouch_spec' => $standard->pouch_spec,
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
                // The resolved configuration goes in too: without it the
                // preview told a machine WITH approved settings that it had
                // none.
                'warnings' => $this->standards->warningsFor($standard, $packaging, $item->id, $workCenter?->id),
            ],
        ]);
    }
}
