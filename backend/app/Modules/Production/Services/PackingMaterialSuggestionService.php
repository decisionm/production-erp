<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\PackingMaterialMapping;
use App\Modules\Production\Models\ProductionStandard;

/**
 * WHICH packing materials a run consumes, and how much of each PER CONTAINER
 * — so the completion screen arrives with the carton, tray, film and tape
 * lines already chosen instead of four dropdowns the supervisor has to guess
 * at.
 *
 * The owner's request, verbatim (31 Jul): "along with Resin and master batch,
 * all other packing consumption also need to calculate, which carton box and
 * tray film pouch and tape under packing consumption." This is the answer to
 * it, built the same way RunMaterialSuggestionService answers the resin and
 * masterbatch half.
 *
 * ## Factors, never totals
 *
 * Every entry carries a FACTOR and what to multiply it by — never a quantity.
 * The carton and tray counts are being typed as the supervisor reads this:
 * the completion drawer recomputes them from its packing lines on every
 * keystroke, and a total quoted here would be a figure computed against a
 * count that has already changed. It would also make this endpoint useless at
 * Start Batch, where no carton has been packed yet and the supervisor still
 * wants to know WHICH carton the run takes.
 *
 * So: cartons × 1 = boxes, trays × 1 = trays, cartons × grams ÷ 1000 = kg of
 * film, cartons × metres = metres of tape. The screen owns the multiplication
 * because the screen owns the counts.
 *
 * ## Both units are stated, because they differ for film
 *
 * `factor_unit` is what the factor is quoted in; `unit` is what the resulting
 * quantity is in. They are the same for cartons, trays and tape, and they
 * differ for film: the item name states GRAMS per piece ("…x120G") while
 * Tally moves KILOGRAMS. Leaving that ÷1000 to be discovered in a prose
 * reason is how a screen ends up suggesting 120 kg of film for one carton.
 *
 * ## A null item is a real answer
 *
 * Five of the seven pouch-film strings in this factory's workbook
 * ("750*610", "835 X 610" …) name no catalogue item at all, and whether the
 * film a carton actually takes is driven by those millimetre strings or by
 * the carton column's own HM/LD dimension is a question only the factory can
 * settle. Those entries come back with `item: null` and a reason naming the
 * spec — which puts the question on the owner's screen, where it can be
 * answered, instead of hiding it behind a plausible-looking guess.
 *
 * ## Advisory only
 *
 * Nothing here is written to an entry and nothing in the completion path
 * calls this service. completeBatch stores exactly the material_consumptions
 * rows it was sent and the Tally voucher carries those. A packing suggestion
 * that could reach a stored figure would be a recipe pretending to be a
 * measurement — the thing the owner refused for masterbatch, and it does not
 * become acceptable because the material is cardboard.
 */
class PackingMaterialSuggestionService
{
    public function __construct(private readonly PackingMaterialMappingService $mappings) {}

    /**
     * The packing materials this standard's run consumes — one entry per
     * applicable material, in packing order: the box, the inner tray, the
     * film that wraps the carton's contents, and the tape that seals it.
     *
     * An entry appears only for a spec the standard actually states. A blank
     * carton column is not a missing mapping, it is a product the workbook
     * says nothing about, and inventing a row for it would put a question on
     * the screen that the factory never asked.
     *
     * @return list<array{
     *     kind: string, spec: string, item: ?array{id: int, name: string},
     *     basis: string, quantity_basis: string, factor: ?string,
     *     unit: string, factor_unit: string, reason: string,
     * }>
     */
    public function forStandard(?ProductionStandard $standard): array
    {
        if ($standard === null) {
            return [];
        }

        $carton = $this->spec($standard->carton_spec);
        $tray = $this->spec($standard->tray_spec);
        $film = $this->spec($standard->pouch_spec);

        $entries = [];

        if ($carton !== null) {
            $entries[] = $this->entry(PackingMaterialMapping::KIND_CARTON, $carton, $standard, 'carton_spec');
        }

        if ($tray !== null) {
            $entries[] = $this->entry(PackingMaterialMapping::KIND_TRAY, $tray, $standard, 'tray_spec');
        }

        if ($film !== null) {
            $entries[] = $this->entry(PackingMaterialMapping::KIND_POUCH_FILM, $film, $standard, 'pouch_spec');
        }

        // Tape last and keyed off the CARTON: tape is dosed by the box it
        // seals, so a product with no carton spec has nothing to seal and no
        // tape line — not a blank one.
        if ($carton !== null) {
            $entries[] = $this->entry(PackingMaterialMapping::KIND_TAPE, $carton, $standard, 'carton_spec');
        }

        return $entries;
    }

    /**
     * One material, resolved or explained.
     *
     * @return array{
     *     kind: string, spec: string, item: ?array{id: int, name: string},
     *     basis: string, quantity_basis: string, factor: ?string,
     *     unit: string, factor_unit: string, reason: string,
     * }
     */
    private function entry(string $kind, string $spec, ProductionStandard $standard, string $column): array
    {
        $basis = PackingMaterialMapping::KIND_BASIS[$kind];
        $mapping = $this->mappings->resolve($kind, $spec);

        return [
            'kind' => $kind,
            'spec' => $spec,
            'item' => $mapping === null ? null : [
                'id' => (int) $mapping->item_id,
                'name' => (string) $mapping->item?->name,
            ],
            'basis' => $basis['basis'],
            'quantity_basis' => $basis['quantity_basis'],
            'factor' => $mapping?->factor(),
            'unit' => $basis['unit'],
            'factor_unit' => $basis['factor_unit'],
            'reason' => $this->reason($kind, $spec, $mapping).$this->inferredNote($standard, $column),
        ];
    }

    /**
     * The sentence the screen prints next to the line. It always names the
     * SPEC, because the spec is what a person has to look up when the
     * suggestion is wrong or absent.
     */
    private function reason(string $kind, string $spec, ?PackingMaterialMapping $mapping): string
    {
        $label = match ($kind) {
            PackingMaterialMapping::KIND_CARTON => 'Carton spec',
            PackingMaterialMapping::KIND_TRAY => 'Tray spec',
            PackingMaterialMapping::KIND_POUCH_FILM => 'Pouch film spec',
            default => 'Tape for carton spec',
        };

        if ($mapping === null) {
            return "{$label} \"{$spec}\" has no packing-material mapping yet, so nothing is prefilled. "
                .'Set one on the packing-materials master to have this line arrive filled in.';
        }

        $item = (string) $mapping->item?->name;

        return match ($kind) {
            PackingMaterialMapping::KIND_CARTON => "{$label} \"{$spec}\" is \"{$item}\" — one box per carton packed.",
            PackingMaterialMapping::KIND_TRAY => "{$label} \"{$spec}\" is \"{$item}\" — one tray per tray packed.",
            PackingMaterialMapping::KIND_POUCH_FILM => $mapping->factor() === null
                ? "{$label} \"{$spec}\" is \"{$item}\", but its per-piece weight is not set, so no kg can be quoted."
                : "{$label} \"{$spec}\" is \"{$item}\" — one film wraps one carton's contents at "
                    ."{$mapping->factor()} g each, so cartons × {$mapping->factor()} g ÷ 1000 = kg.",
            default => $mapping->factor() === null
                ? "{$label} \"{$spec}\" is \"{$item}\", but no metres-per-box figure is set, so no length can be quoted."
                : "{$label} \"{$spec}\" is \"{$item}\" at {$mapping->factor()} m per box (owner, 31 Jul 2026) — "
                    .'cartons × that. Tally counts tape in Nos and whether a "No" is a metre or a strip is still '
                    .'open with the factory, so this is METRES, not a Tally quantity.',
        };
    }

    /**
     * The provenance sentence, when this cell was inferred rather than
     * stated.
     *
     * An inferred spec is usable — PackingSpecInferences marks its fills, it
     * does not quarantine them — but the supervisor has to be told, because
     * an inferred carton is the one place a wrong box reaches a real
     * dispatch. The row number is the row the value was taken FROM, which is
     * what a person would check against the sheet.
     */
    private function inferredNote(ProductionStandard $standard, string $column): string
    {
        $all = $standard->spec_provenance;
        $provenance = is_array($all) ? ($all[$column] ?? null) : null;

        if (! is_array($provenance) || ($provenance['inferred'] ?? false) !== true) {
            return '';
        }

        $row = (string) ($provenance['from_source_reference'] ?? '');
        $from = (string) ($provenance['from_product'] ?? '');

        return ' Note: spec inferred from row '.$row
            .($from === '' ? '' : " ({$from})")
            .', not stated on this row of the workbook.';
    }

    /** A spec the standard actually states, or null. */
    private function spec(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
