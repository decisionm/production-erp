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
 * Relationship to ShiftProductionEntryService::productionMetrics(): that one
 * is the post-completion, workbook-matching figure and deliberately keeps
 * WB2's formula (3600/CT × cavities × hours, no flooring) so it reconciles
 * cell-for-cell with Vincent's sheet. This one is a forward-looking plan and
 * floors the cycle count — a machine cannot complete a fractional shot. The
 * two therefore differ by at most one shot's worth of pieces, which is
 * intentional and documented rather than a discrepancy to chase.
 */
class BatchEstimationService
{
    public function __construct(
        private readonly BomService $boms,
        private readonly ProductionCalculationEngine $engine,
    ) {}

    /**
     * @return array{
     *     planned_hours: ?string, standard_cycle_time: ?string,
     *     standard_cavities: ?int, active_cavities: ?int,
     *     expected_cycles: ?int, expected_pieces: ?int,
     *     expected_kg: ?string, expected_trays: ?int, expected_boxes: ?int,
     *     expected_pouches: ?int, nos_per_tray: ?int, nos_per_box: ?int,
     *     nos_per_pouch: ?int, expected_materials: list<array{
     *         item_id: int, name: string, uom: ?string, quantity: string, is_mass: bool
     *     }>, recipe_source: ?string,
     * }
     */
    public function estimate(
        Item $item,
        ?Shift $shift,
        ?string $plannedHours = null,
        ?int $activeCavities = null,
    ): array {
        $hours = $plannedHours ?? ($shift !== null ? $this->shiftLengthHours($shift) : null);
        $cycleTime = $item->standard_cycle_time !== null ? (string) $item->standard_cycle_time : null;
        $cavities = $activeCavities ?? $item->standard_cavities;

        // One floor implementation, in the engine — duplicating it here is
        // exactly how two screens end up disagreeing about the same shift.
        $pieces = $this->engine->targetPieces($hours, $cycleTime, $cavities);
        $cycles = ($pieces !== null && $cavities !== null && $cavities > 0)
            ? intdiv($pieces, $cavities)
            : null;

        $expectedKg = null;
        if ($pieces !== null && $item->nominal_weight_grams !== null
            && bccomp((string) $item->nominal_weight_grams, '0', 4) === 1) {
            $expectedKg = bcdiv(bcmul((string) $pieces, (string) $item->nominal_weight_grams, 4), '1000', 4);
        }

        return [
            'planned_hours' => $hours,
            'standard_cycle_time' => $cycleTime,
            'standard_cavities' => $item->standard_cavities,
            'active_cavities' => $cavities,
            'expected_cycles' => $cycles,
            'expected_pieces' => $pieces,
            'expected_kg' => $expectedKg,
            'nos_per_tray' => $item->nos_per_tray,
            'nos_per_box' => $item->nos_per_box,
            'nos_per_pouch' => $item->nos_per_pouch,
            // Trays and pouches are packing SUGGESTIONS — how many
            // containers you need, so a part-filled one still counts (ceil).
            'expected_trays' => $this->containers($pieces, $item->nos_per_tray),
            'expected_pouches' => $this->containers($pieces, $item->nos_per_pouch),
            // Boxes are the TARGET the shift is measured against, and the
            // factory's EST BOX column rounds to nearest. Using the packing
            // ceil here would inflate the target and understate efficiency.
            'expected_boxes' => $this->engine->expectedBoxes($pieces, $item->nos_per_box),
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
                    'is_mass' => $this->isMassUom($component->uom),
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

    private function containers(?int $pieces, ?int $per): ?int
    {
        if ($pieces === null || $per === null || $per <= 0) {
            return null;
        }

        // Packing SUGGESTIONS round per config (default ceil — a part-filled
        // container still needs packing), consistent with the completion
        // form's prefills.
        return match (config('production.packing_rounding', 'ceil')) {
            'floor' => intdiv($pieces, $per),
            'round' => (int) round($pieces / $per),
            default => (int) ceil($pieces / $per),
        };
    }

    private function isMassUom(?string $uom): bool
    {
        return in_array(rtrim(strtolower(trim((string) $uom)), '.'), ['kg', 'kgs', 'kilogram', 'kilograms'], true);
    }
}
