<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Http\Requests\BatchPreviewRequest;
use App\Modules\Production\Http\Resources\MasterbatchDosingResource;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\BatchEstimationService;
use App\Modules\Production\Services\MasterbatchDosingService;
use App\Modules\Production\Services\PackingMaterialSuggestionService;
use App\Modules\Production\Services\ProductionConfigurationService;
use App\Modules\Production\Services\ProductionStandardResolver;
use App\Modules\Production\Services\ProductReadinessService;
use App\Modules\Production\Services\ProductVariantService;
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
        private readonly PackingMaterialSuggestionService $packingSuggestions,
        private readonly ProductVariantService $variantStatus,
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
                // The other four consumables the owner asked for (31 Jul):
                // "which carton box and tray film pouch and tape under
                // packing consumption". A LIST, one entry per material the
                // resolved standard's specs call for, in packing order.
                //
                // FACTORS, never totals, and deliberately quoted against NO
                // bottle count: cartons and trays are being typed as this is
                // read (the drawer recomputes them on every keystroke), and a
                // total quoted here would be computed against a count that
                // has already moved. It is also what lets Start Batch — where
                // nothing has been packed yet — still say WHICH carton the
                // run takes. The screen multiplies by its own counts.
                //
                // `item: null` travels freely, with a reason naming the spec:
                // five of this workbook's pouch-film strings name no
                // catalogue item, and putting that question on the screen is
                // the point. The alternative is a plausible-looking guess
                // that reaches a real dispatch.
                //
                // Advisory. Nothing here is stored: completion saves exactly
                // the lines submitted, and Tally carries those.
                'suggested_packing' => $this->packingSuggestions->forStandard($standard),
                // Named so the screen can SAY the machine's own approved
                // figures are in use, rather than leaving the supervisor to
                // wonder why the numbers differ from the standards card.
                'configuration' => $configuration === null ? null : [
                    'id' => $configuration->id,
                    'default_cycle_time' => $configuration->default_cycle_time,
                    'default_cavities' => $configuration->default_cavities,
                    'unit_weight_grams' => $configuration->unit_weight_grams,
                    'colour' => $configuration->colour,
                    // The mould this configuration runs (Phase 5.5, P5.5-01),
                    // so Start Batch can SAY it rather than ask or leave it
                    // unsaid. Read from the same resolved row whose figures
                    // are quoted above (`mold` is eager-loaded by resolve());
                    // null when the configuration names no mould.
                    'mould' => $configuration->mold === null ? null : [
                        'id' => $configuration->mold->id,
                        'code' => $configuration->mold->code,
                        'name' => $configuration->mold->name,
                    ],
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
                    // What this variant is still missing, in the words the
                    // standards workspace and the variants endpoint use
                    // (ProductVariantService, P5-02/P5-06) — additive, so
                    // the picker can SAY "incomplete: counts missing"
                    // instead of a bare disabled radio. Advisory here:
                    // which option is offered or disabled is unchanged.
                    'configuration_status' => $this->variantStatus->standardStatus($v, $item),
                    'packagings' => $v->packagings->map(fn ($p) => [
                        'id' => $p->id, 'mode' => $p->mode, 'label' => $p->label(),
                        'nos_per_pouch' => $p->nos_per_pouch, 'pouches_per_box' => $p->pouches_per_box,
                        'nos_per_tray' => $p->nos_per_tray, 'trays_per_box' => $p->trays_per_box,
                        'nos_per_box' => $p->nos_per_box, 'is_default' => $p->is_default,
                        // A half-stated workbook row is shown but not
                        // runnable — the picker disables it, and the
                        // resolver above refuses it either way.
                        'is_complete' => $p->isComplete(),
                        // The packing's own Tally identity (DEC-20260810-003),
                        // so the picker can say WHICH Tally item this choice
                        // posts as — null means "the product's own item", and
                        // the screen says so rather than guessing. sku and
                        // guid ride beside id and name (additive, P5-02).
                        'tally_item' => $p->item_id === null ? null : [
                            'id' => (int) $p->item_id,
                            'sku' => (string) $p->tallyItem?->sku,
                            'name' => (string) $p->tallyItem?->name,
                            'guid' => $p->tallyItem?->tally_stock_item_guid,
                        ],
                        // The same status the variants endpoint gives this
                        // row — state, missing[], ambiguity — so the Shift
                        // Floor's words and the workspace's are one set.
                        // `is_complete` above stays the runnable flag the
                        // picker disables on; this adds the reason.
                        'configuration_status' => $this->variantStatus->packagingStatus($p, $v, $item),
                    ])->values(),
                ])->values(),
                'packaging' => $packaging === null ? null : [
                    'id' => $packaging->id, 'mode' => $packaging->mode, 'label' => $packaging->label(),
                    'nos_per_box' => $packaging->nos_per_box,
                ],
                // THE RUN'S OWN VERDICT (Phase 5.5 fix loop, P5.5-06): what a
                // batch started from this preview, as it stands, would be
                // missing — the SAME rule startBatch freezes into the entry
                // (ProductVariantService::runStatus for the resolved standard
                // and packaging), so the modal and Completed Today can never
                // contradict each other about one run. The per-variant
                // `configuration_status` above is the STANDARD's union over
                // every packaging and stays as it was; reading it for the run
                // warned a tray run on a complete tray row about the sibling
                // pouch's missing counts.
                //
                // `grain` says whose verdict it is: 'run' once a packaging is
                // resolved (or there is no standard to speak of — the run
                // freezes exactly this), 'standard' while the packaging is
                // still to be chosen (the standard's union; the run's may be
                // narrower). Null while the STANDARD is still to be chosen —
                // that question comes first, and no gap is named for a
                // standard this run may not use.
                'configuration_status' => $this->runConfigurationStatus($item, $variants->count(), $standard, $packaging),
                // The resolved configuration goes in too: without it the
                // preview told a machine WITH approved settings that it had
                // none.
                // Active cavities passed through, so the preview's cavity
                // warning is judged on what this run will actually use — the
                // same figure the estimation above is built from, and the same
                // one startBatch records against the batch. Without it the
                // preview would warn off the mould's figure and disagree with
                // the batch it is previewing.
                'warnings' => [
                    ...$this->standards->warningsFor(
                        $standard,
                        $packaging,
                        $item->id,
                        $workCenter?->id,
                        $data['active_cavities'] ?? $configuration?->default_cavities ?? $standard?->cavities,
                    ),
                    // A duplicate approved machine setting also applying to
                    // this run: the resolver's pick is deterministic, but a
                    // pick nobody was told about is still a silent choice —
                    // this names it (and its cure) on the screen that shows
                    // the figures it decided.
                    ...array_filter([
                        $workCenter !== null
                            ? $this->configurations->overlapWarningFor($workCenter->id, $item->id)
                            : null,
                    ]),
                ],
            ],
        ]);
    }

    /**
     * @return ?array{state: string, missing: list<string>, grain: 'run'|'standard'}
     */
    private function runConfigurationStatus(Item $item, int $variantCount, ?ProductionStandard $standard, ?ProductionStandardPackaging $packaging): ?array
    {
        if ($standard === null && $variantCount > 1) {
            return null;
        }

        return [
            ...$this->variantStatus->runStatus($standard, $packaging, $item),
            'grain' => $packaging !== null || $standard === null ? 'run' : 'standard',
        ];
    }
}
