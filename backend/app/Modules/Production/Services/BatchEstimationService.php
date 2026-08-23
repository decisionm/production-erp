<?php

namespace App\Modules\Production\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\Shift;
use Illuminate\Support\Carbon;

/**
 * The Start Batch estimation card: what this run SHOULD produce and consume,
 * computed from the product's versioned standards before a single bottle is
 * blown. Pure computation — no writes, no side effects.
 *
 * Every figure is null when its inputs are missing. Nothing here invents a
 * number: a guessed expectation is worse than a blank, because the whole
 * point of showing it up front is that the supervisor can compare it against
 * what they know the machine does and object before starting.
 *
 * Relationship to ShiftProductionEntryService::productionMetrics(): ONE
 * formula (P5.5-03, production_v3_unified). Both read
 * ProductionCalculationEngine::targetPieces() — cycles floored before
 * cavities multiply, because a machine cannot complete a fractional shot.
 * This estimate feeds it the PLANNED hours, since no downtime is known
 * before the run (`downtime_netted: false` says so on the response); the
 * completion metrics feed it the running hours net of the downtime logged
 * at completion. For a run with no downtime the two agree to the piece and
 * to the box. (Before this phase the metrics inlined WB2's unfloored
 * formula and the two could disagree by a whole box for one unchanged run
 * — ExpectedOutputDivergenceTest; entries stamped before the change keep
 * that figure, see LegacyEntryMetrics.)
 */
class BatchEstimationService
{
    public function __construct(
        private readonly BomService $boms,
        private readonly ProductionCalculationEngine $engine,
        // THE ONE reader of pack quantities (Phase 5, P5-04) — the same
        // packaging → item precedence the run will be measured against
        // after completion, so the preview and the metrics can never quote
        // two different pack sizes for one run.
        private readonly PackQuantityResolver $packQuantities,
    ) {}

    /**
     * @return array{
     *     calculation_version: string, downtime_netted: bool,
     *     planned_hours: ?string, standard_cycle_time: ?string,
     *     standard_cavities: ?int, active_cavities: ?int,
     *     expected_cycles: ?int, expected_pieces: ?int,
     *     expected_kg: ?string, expected_trays: ?int, expected_boxes: ?int,
     *     expected_pouches: ?int, nos_per_tray: ?int, nos_per_box: ?int,
     *     nos_per_pouch: ?int, pack_quantity_source: string, expected_materials: list<array{
     *         item_id: int, name: string, uom: ?string, quantity: string, is_mass: bool
     *     }>, recipe_source: ?string,
     * }
     */
    public function estimate(
        Item $item,
        ?Shift $shift,
        ?string $plannedHours = null,
        ?int $activeCavities = null,
        ?object $standard = null,
        ?object $packaging = null,
        ?object $configuration = null,
    ): array {
        $hours = $plannedHours ?? ($shift !== null ? $this->shiftLengthHours($shift) : null);
        // Precedence: the APPROVED machine configuration, then the factory
        // product standard, then the item master — the same order startBatch
        // snapshots (config_snapshot lines). This estimate is what the
        // supervisor reads before confirming, so it must be computed from the
        // figures the run will actually use; a preview quoting the standard's
        // cycle time while the batch runs the machine's own is the screen
        // disagreeing with the gate.
        $cycleTime = $configuration?->default_cycle_time !== null
            ? (string) $configuration->default_cycle_time
            : ($standard?->cycle_time !== null
                ? (string) $standard->cycle_time
                : ($item->standard_cycle_time !== null ? (string) $item->standard_cycle_time : null));
        $cavities = $activeCavities ?? $configuration?->default_cavities ?? $standard?->cavities ?? $item->standard_cavities;

        // One floor implementation, in the engine — duplicating it here is
        // exactly how two screens end up disagreeing about the same shift.
        // The SAME call UnifiedEntryMetrics makes after completion (through
        // the engine's targets()), under the version this preview names.
        $pieces = $this->engine->targetPieces($hours, $cycleTime, $cavities, ProductionCalculationEngine::VERSION_UNIFIED);
        $cycles = ($pieces !== null && $cavities !== null && $cavities > 0)
            ? intdiv($pieces, $cavities)
            : null;

        $expectedKg = null;
        $unitWeight = $configuration?->unit_weight_grams ?? $standard?->unit_weight_grams ?? $item->nominal_weight_grams;
        if ($pieces !== null && $unitWeight !== null && bccomp((string) $unitWeight, '0', 4) === 1) {
            $expectedKg = bcdiv(bcmul((string) $pieces, (string) $unitWeight, 4), '1000', 4);
        }

        // Pack counts through the ONE resolver (P5-04): the chosen packaging
        // row, then the item master — per figure, which is exactly the
        // `packaging ?? item` this read before, now shared with the metric
        // reader so the two can never disagree about a run's pack size.
        $pack = $this->packQuantities->forSelection($packaging, $item);

        return [
            // Which formula set produced expected_* — the stamp the batch
            // will carry at Start, so the preview and the completed entry
            // can be read against the same name.
            'calculation_version' => ProductionCalculationEngine::VERSION_UNIFIED,
            // Planned hours straight in: no downtime is known before the
            // run. The completion metrics net what was logged and say true.
            'downtime_netted' => false,
            'planned_hours' => $hours,
            'standard_cycle_time' => $cycleTime,
            'standard_cavities' => $item->standard_cavities,
            'active_cavities' => $cavities,
            'expected_cycles' => $cycles,
            'expected_pieces' => $pieces,
            'expected_kg' => $expectedKg,
            'nos_per_tray' => $pack->nos_per_tray,
            'nos_per_box' => $pack->nos_per_box,
            'nos_per_pouch' => $pack->nos_per_pouch,
            'pack_quantity_source' => $pack->source,
            'packaging_mode' => $packaging?->mode,
            // Trays and pouches are packing SUGGESTIONS — how many
            // containers you need, so a part-filled one still counts (ceil).
            // Through the engine's ONE implementation, which the completion
            // metrics' expected_pouches reads too.
            'expected_trays' => $this->engine->packingContainers($pieces, $pack->nos_per_tray),
            'expected_pouches' => $this->engine->packingContainers($pieces, $pack->nos_per_pouch),
            // Boxes are the TARGET the shift is measured against, and the
            // factory's EST BOX column rounds to nearest. Using the packing
            // ceil here would inflate the target and understate efficiency.
            'expected_boxes' => $this->engine->expectedBoxes($pieces, $pack->nos_per_box),
            ...$this->expectedMaterials($item, $pieces),
        ];
    }

    /**
     * Expected consumption per the product's active recipe — resin,
     * masterbatch AND the Nos-unit consumables (caps, labels, cartons),
     * each in its own unit. The whole point of the recipe is that the
     * consumables are estimated alongside the resin instead of being
     * remembered by the supervisor.
     *
     * Falls back to the item weight for a mass-only estimate when no recipe
     * exists — labelled as such, so nobody mistakes a single resin figure
     * for a full consumable list.
     *
     * @return array{expected_materials: list<array{item_id: int, name: string, uom: ?string, quantity: string, is_mass: bool}>, recipe_source: ?string}
     */
    private function expectedMaterials(Item $item, ?int $pieces): array
    {
        $bom = $this->boms->activeFor($item->id);

        if ($bom !== null && $bom->lines->isNotEmpty()) {
            $bom->loadMissing(['lines.component' => fn ($query) => $query->withTrashed()]);

            $materials = [];
            foreach ($bom->lines as $line) {
                $component = $line->component;
                if ($component === null) {
                    continue;
                }

                $materials[] = [
                    'item_id' => $component->id,
                    'name' => $component->name,
                    'uom' => $component->uom,
                    'quantity' => $pieces !== null
                        ? bcmul((string) $pieces, (string) $line->quantity_per, 4)
                        : '0',
                    'is_mass' => Item::isKgUom($component->uom),
                ];
            }

            return ['expected_materials' => $materials, 'recipe_source' => 'bom'];
        }

        return ['expected_materials' => [], 'recipe_source' => null];
    }

    /**
     * Planned hours from the shift's clock. Overnight shifts (start > end)
     * wrap past midnight — the same convention Shift::productionDateFor()
     * encodes for dates.
     */
    public function shiftLengthHours(Shift $shift): ?string
    {
        if ($shift->start_time === null || $shift->end_time === null) {
            return null;
        }

        $start = Carbon::parse($shift->start_time);
        $end = Carbon::parse($shift->end_time);

        if ($end->lessThanOrEqualTo($start)) {
            $end = $end->addDay();
        }

        return bcdiv((string) $start->diffInMinutes($end), '60', 4);
    }
}
