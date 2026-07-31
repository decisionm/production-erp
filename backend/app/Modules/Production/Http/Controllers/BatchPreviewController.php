<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Http\Requests\BatchPreviewRequest;
use App\Modules\Production\Http\Resources\MasterbatchDosingResource;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\BatchEstimationService;
use App\Modules\Production\Services\MasterbatchDosingService;
use App\Modules\Production\Services\ProductionConfigurationService;
use App\Modules\Production\Services\ProductionStandardResolver;
use App\Modules\Production\Services\ProductReadinessService;
use App\Modules\Production\Services\RunMaterialSuggestionService;
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
        private readonly MasterbatchDosingService $masterbatchDosings,
        private readonly RunMaterialSuggestionService $materialSuggestions,
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

        $estimation = $this->estimation->estimate(
            $item,
            $shift,
            $data['planned_hours'] ?? null,
            $data['active_cavities'] ?? $configuration?->default_cavities ?? $standard?->cavities,
            $standard,
            $packaging,
            $configuration,
        );

        // The bottle count the masterbatch kg is quoted against: what the
        // supervisor has actually counted if they sent it (the completion
        // drawer calls this endpoint), otherwise the run's expected pieces so
        // Start Batch can still say what the colour should come to. Echoed
        // back inside each dosing block as `bottles`, so the screen can state
        // the basis instead of showing an unexplained kg.
        $dosingBottles = array_key_exists('quantity_produced', $data) && $data['quantity_produced'] !== null
            ? (int) $data['quantity_produced']
            : $estimation['expected_pieces'];

        // WHICH resin and WHICH masterbatch this run consumes, so the two
        // material boxes arrive already chosen. Quoted against the SAME
        // bottle count as the masterbatch_dosing block below — two bottle
        // bases in one response would be the screen disagreeing with itself.
        // The supervisor's own colour, when they have stated one, outranks the
        // configuration and the item master exactly as it does at Start Batch
        // — so the masterbatch offered here is the one for the colour this run
        // is RECORDED as running, not the one the item master happens to hold.
        $suggestions = $this->materialSuggestions->forRun(
            $item,
            $configuration,
            $standard,
            $dosingBottles,
            $data['colour'] ?? null,
        );

        return response()->json([
            'data' => [
                // Assessed against the SAME resolved standard/packaging the
                // estimation below uses, so the two halves of this response
                // can never contradict each other.
                'readiness' => $this->readiness->assess($item, $warehouse, $workCenter, $standard, $packaging, $configuration),
                'estimation' => $estimation,
                // Masterbatch prefill: grams per bottle from the dosing
                // master × these bottles ÷ 1000, computed in
                // ProductionCalculationEngine so this screen and any future
                // report cannot disagree.
                //
                // A LIST, one entry per masterbatch that has a figure, keyed
                // by its item — NOT a single "the" masterbatch derived from
                // the product's colour. Matching a colour against masterbatch
                // item names is the hardcoded-name-list trap
                // FactoryDayBinService already documents; the screen knows
                // which masterbatch it scanned or picked and matches on the
                // item id. Empty list = no dosing set = prefill nothing, and
                // the field stays blank rather than showing 0.
                //
                // Advisory only. Nothing here is stored: the completion
                // endpoint saves exactly the consumption the supervisor
                // submits, which is the figure Tally receives.
                'masterbatch_dosing' => MasterbatchDosingResource::listForBottles(
                    $this->masterbatchDosings->candidatesFor($item->id),
                    $dosingBottles,
                ),
                // The two pre-selected materials, each with the grams per
                // bottle and a sentence saying where the choice came from.
                //
                // Both carry `item: null` freely — no masterbatch for a Clear
                // bottle, and no material at all where the masters cannot
                // answer without guessing. A null with a reason the screen
                // prints is the point: the alternative that shipped was the
                // wrong-colour masterbatch pre-selected by first name match.
                //
                // The resin block deliberately has NO suggested_kg: the resin
                // total is production kg + rejection kg + lumps (the factory's
                // paper arithmetic, verified 11 rows of 11), and grams ×
                // bottles would be light by the scrap mass every shift. The
                // masterbatch total IS grams × bottles ÷ 1000 — the owner's
                // own rule for the colour — computed in the engine.
                //
                // Advisory. Nothing here is stored: completion saves exactly
                // the lines submitted, and Tally carries those.
                'suggested_resin' => $suggestions['resin'],
                'suggested_masterbatch' => $suggestions['masterbatch'],
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
