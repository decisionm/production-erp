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
 * ## `submit_as_stock` — and why the tape line is display-only today
 *
 * Every entry says, in one boolean, whether the quantity the screen computes
 * from it may be filed as a STOCK MOVEMENT — a consumption line on the
 * completion, which becomes an issue against a warehouse and a line on a live
 * Tally voucher.
 *
 * It is `false` for exactly one case: a TAPE line whose factor is metres while
 * the Tally item it points at is not counted in a length. TapeMetresPerBox has
 * carried that question unanswered since the figures arrived — Tally counts
 * "Packing Tape - Transparent" in Nos, and whether one No is a metre or a
 * whole roll is the factory's to say. Before this flag existed the completion
 * screen filed the metres as an ordinary consumption line, so 100 cartons of
 * 170ML issued 229 "Nos" of tape and posted it to the live books: not a
 * rounding error, a different number about a different thing. Now the line is
 * shown with its metres and its arithmetic, marked as not posted, and left out
 * of the payload — the honest answer until the factory gives the real one.
 *
 * `true` everywhere else, INCLUDING for carton, tray and film, which is not an
 * oversight. Their units already agree with what the screen computes, and this
 * factory's item master reads "NOS" even for the film Tally moves in Kgs — a
 * general "does items.uom match" test would silently drop the film line from
 * every completion, which is the same defect wearing a different hat. See
 * PackingMaterialMapping::LENGTH_UOM_VARIANTS.
 *
 * When the factory answers, the mapping's own data flips the flag with no
 * deploy: `metres_per_unit` (metres in one Tally unit — a 65 m roll → 65)
 * makes the factor metres_per_box ÷ metres_per_unit in Nos, states the
 * conversion in the reason, and turns the flag true; alternatively the tape
 * item's unit is corrected to a length in the masters and the metres post as
 * they stand. The SCREEN OBEYS THE FLAG — it does not re-derive the rule, so
 * there is one place this can be got wrong instead of two.
 *
 * ## Advisory only
 *
 * Nothing here is written to an entry and nothing in the completion path
 * calls this service. completeBatch stores exactly the material_consumptions
 * rows it was sent and the Tally voucher carries those. A packing suggestion
 * that could reach a stored figure would be a recipe pretending to be a
 * measurement — the thing the owner refused for masterbatch, and it does not
 * become acceptable because the material is cardboard.
 *
 * `submit_as_stock` does not break that rule, it enforces it in the one
 * direction that matters: a false can only ever REMOVE a line from what the
 * supervisor sends. It never adds one, never fills one in, and never overrides
 * a figure a person typed.
 */
class PackingMaterialSuggestionService
{
    /**
     * Decimal places kept when metres-per-box is divided into a per-No factor.
     *
     * Eight, not four, and the difference is visible on the factory's own
     * numbers: 2.2900 m ÷ 65 m per roll is 0.03523076…, and quoting that at
     * 4dp (0.0352) puts 100 cartons at 3.52 Nos instead of 3.5231 — the
     * rounding of a FACTOR is multiplied by every carton in the shift, which
     * is why the factor carries more places than the quantity ever shows. The
     * screen rounds the product to 4dp, as it does for film and resin.
     *
     * Nothing rounds to whole units. A tape roll is genuinely consumed part
     * way, and rounding 3.5231 up to 4 rolls would issue half a roll that
     * never left the store.
     */
    private const CONVERSION_SCALE = 8;

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
     *     unit: string, factor_unit: string, submit_as_stock: bool,
     *     reason: string,
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

        // A BAG-PACKED PRODUCT HAS ONE PACKING MATERIAL AND NOTHING ELSE.
        //
        // The factory's own words (05-Aug): "when HM, no need to use the tray or
        // pouch and other packing material." Their sheet proves it without
        // being asked — all 17 rows whose carton column holds an HM or LD bag
        // carry no tray spec and no film spec at all.
        //
        // Returning here rather than filtering below is the point: a bag is not
        // a carton with parts missing, it is a different way to pack, and every
        // line after this one is dosed PER CARTON. There is no carton, so a
        // tray line, a film line and a tape line would each be a real material
        // quoted against a container that does not exist — and tape's own miss
        // said so out loud ("no metres-per-box row paired to this carton").
        if ($this->isBag($carton)) {
            return $entries;
        }

        if ($tray !== null) {
            $entries[] = $this->entry(PackingMaterialMapping::KIND_TRAY, $tray, $standard, 'tray_spec');
        }

        if ($film !== null) {
            // ONE POUCH PER TRAY, not one per carton.
            //
            // The factory (05-Aug): "along with the tray, the pouch also needs
            // to calculate — five trays, five pouches." Their arithmetic backs
            // it in all 55 tray rows: bottles/tray × trays/box = bottles/box, so
            // a box of 810 is five trays of 162, and each tray is covered.
            //
            // Dosing this per carton — which is what it did — quoted ONE film
            // for a box that really consumes five or six. Not a rounding
            // difference: an under-count of five-sixths on every tray-packed
            // shift, invisible in Tally until somebody counted the film shelf.
            $entries[] = $this->entry(
                PackingMaterialMapping::KIND_POUCH_FILM,
                $film,
                $standard,
                'pouch_spec',
                $tray !== null ? PackingMaterialMapping::KIND_TRAY : null,
            );
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
     * Is this spec a poly bag rather than a carton?
     *
     * Judged on the VALUE, never on which column it sits in. The workbook uses
     * one CARTON column for two different things — a carton size ("100ML") and
     * a bag size ("HM 30.5*49", "LD 28.5 X 38") — and one row files a bag under
     * TRAY, so the column cannot be trusted to say what kind of thing it names.
     *
     * HM and LD are the factory's own prefixes for the two bag families their
     * Tally carries: "Hm Polythene Bags - 30.5 x 49 x 200G" and
     * "LDPE COVER (28.5x38x120G)".
     */
    private function isBag(?string $spec): bool
    {
        if ($spec === null) {
            return false;
        }

        return (bool) preg_match('/^\s*(hm|ld)\b/i', $spec)
            || (bool) preg_match('/^\s*ld\d/i', $spec);   // "LD28.5 X 39", written closed up
    }

    /**
     * One material, resolved or explained.
     *
     * @return array{
     *     kind: string, spec: string, item: ?array{id: int, name: string},
     *     basis: string, quantity_basis: string, factor: ?string,
     *     unit: string, factor_unit: string, submit_as_stock: bool,
     *     reason: string,
     * }
     */
    private function entry(string $kind, string $spec, ProductionStandard $standard, string $column, ?string $basisKind = null): array
    {
        // $basisKind re-bases the quantity onto another kind's container while
        // leaving the material and its unit alone. Used for one case: a film on
        // a tray-packed product is counted per TRAY, not per carton.
        $basis = PackingMaterialMapping::KIND_BASIS[$kind];

        if ($basisKind !== null) {
            $rebased = PackingMaterialMapping::KIND_BASIS[$basisKind];
            $basis['basis'] = $rebased['basis'];
            $basis['quantity_basis'] = $rebased['quantity_basis'];
        }
        $mapping = $this->mappings->resolve($kind, $spec);
        $filing = $this->filing($kind, $mapping, $basis);

        return [
            'kind' => $kind,
            'spec' => $spec,
            'item' => $mapping === null ? null : [
                'id' => (int) $mapping->item_id,
                'name' => (string) $mapping->item?->name,
            ],
            'basis' => $basis['basis'],
            'quantity_basis' => $basis['quantity_basis'],
            'factor' => $filing['factor'],
            'unit' => $filing['unit'],
            'factor_unit' => $filing['factor_unit'],
            'submit_as_stock' => $filing['submit_as_stock'],
            'reason' => $this->reason($kind, $spec, $mapping, $filing).$this->inferredNote($standard, $column),
        ];
    }

    /**
     * WHAT UNIT this line's quantity is in, and whether it may be filed as a
     * stock movement — the one place that decision is made.
     *
     * Three of the four kinds pass straight through: their factor and their
     * unit are the kind's own and always postable. Only tape can come back
     * false, and only because its metres and its Tally item can disagree about
     * what is being counted — see the class docblock.
     *
     * @param  array{basis: string, quantity_basis: string, unit: string, factor_unit: string}  $basis
     * @return array{factor: ?string, unit: string, factor_unit: string, submit_as_stock: bool, metres_per_unit: ?string}
     */
    private function filing(string $kind, ?PackingMaterialMapping $mapping, array $basis): array
    {
        $plain = [
            'factor' => $mapping?->factor(),
            'unit' => $basis['unit'],
            'factor_unit' => $basis['factor_unit'],
            // True even for a row with no mapping and no factor: this flag
            // answers "may this material's quantity be posted", not "is there
            // a quantity". An item-less row is already excluded from the
            // payload by having no item, and saying false here would blur two
            // different silences into one.
            'submit_as_stock' => true,
            'metres_per_unit' => null,
        ];

        if ($kind !== PackingMaterialMapping::KIND_TAPE) {
            return $plain;
        }

        $metres = $mapping?->factor();

        // No mapping, or a mapping with no metres figure: there is no length
        // to file and no conversion to state. False, because the open unit
        // question is unanswered either way and a screen that read true here
        // would be claiming a settlement that has not happened.
        if ($mapping === null || $metres === null) {
            return [...$plain, 'factor' => $metres, 'submit_as_stock' => false];
        }

        // The factory corrected the item's unit instead of giving a
        // conversion: the metres ARE the Tally quantity, unconverted.
        if ($mapping->itemCountsInLength()) {
            return [...$plain, 'factor' => $metres, 'submit_as_stock' => true];
        }

        $metresPerUnit = $mapping->metresPerUnit();

        // Still open — the honest branch, and the one live today.
        if ($metresPerUnit === null) {
            return [...$plain, 'factor' => $metres, 'submit_as_stock' => false];
        }

        // Answered: metres per box ÷ metres per Tally unit = units per box.
        return [
            'factor' => $this->perUnitFactor($metres, $metresPerUnit),
            'unit' => 'nos',
            'factor_unit' => 'nos',
            'submit_as_stock' => true,
            'metres_per_unit' => $metresPerUnit,
        ];
    }

    /**
     * Metres per box ÷ metres per Tally unit, as an exact decimal string.
     *
     * bcdiv, not float division: this figure is multiplied by every carton in
     * the shift and then posted, and the whole mapping is held as decimal
     * strings for that reason. Trailing zeros come off so a clean conversion
     * reads "1" beside the cartons rather than "1.00000000".
     */
    private function perUnitFactor(string $metresPerBox, string $metresPerUnit): string
    {
        $factor = bcdiv($metresPerBox, $metresPerUnit, self::CONVERSION_SCALE);

        if (str_contains($factor, '.')) {
            $factor = rtrim(rtrim($factor, '0'), '.');
        }

        return $factor === '' ? '0' : $factor;
    }

    /**
     * The sentence the screen prints next to the line. It always names the
     * SPEC, because the spec is what a person has to look up when the
     * suggestion is wrong or absent.
     *
     * @param  array{factor: ?string, unit: string, factor_unit: string, submit_as_stock: bool, metres_per_unit: ?string}  $filing
     */
    private function reason(string $kind, string $spec, ?PackingMaterialMapping $mapping, array $filing): string
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
            default => $this->tapeReason($label, $spec, $item, $mapping, $filing),
        };
    }

    /**
     * The tape sentence — four of them, because tape is the one material whose
     * unit question is still open, and the screen has to say which of the four
     * situations this row is in.
     *
     * @param  array{factor: ?string, unit: string, factor_unit: string, submit_as_stock: bool, metres_per_unit: ?string}  $filing
     */
    private function tapeReason(string $label, string $spec, string $item, PackingMaterialMapping $mapping, array $filing): string
    {
        $metres = $mapping->factor();

        if ($metres === null) {
            return "{$label} \"{$spec}\" is \"{$item}\", but no metres-per-box figure is set, so no length can be quoted.";
        }

        $head = "{$label} \"{$spec}\" is \"{$item}\" at {$metres} m per box (owner, 31 Jul 2026) — ";

        // Answered by a conversion: the arithmetic is spelled out in full,
        // both figures and both units, because a factor of 0.03523076 beside a
        // carton count is meaningless to the floor without the division that
        // produced it.
        if ($filing['metres_per_unit'] !== null) {
            return $head."one Nos of it is {$filing['metres_per_unit']} m, so "
                ."{$metres} ÷ {$filing['metres_per_unit']} = {$filing['factor']} Nos per box and cartons × that. "
                .'Posted in Nos, the unit Tally counts this tape in.';
        }

        // Answered by the item's own unit instead: nothing to convert.
        if ($filing['submit_as_stock']) {
            return $head.'cartons × that. "'.$item.'" is counted in '.trim((string) $mapping->item?->uom)
                .', so the metres are the Tally quantity as they stand.';
        }

        // Unanswered — the live case. The sentence says what is NOT happening,
        // because a figure on screen that nobody told the supervisor was
        // withheld from the voucher is worse than no figure at all.
        return $head.'cartons × that. Tally counts tape in Nos and whether a "No" is a metre or a strip is still '
            .'open with the factory, so this is METRES, not a Tally quantity: the line is shown for the record and '
            .'NOT posted to stock or Tally. Set metres per unit on this mapping — or correct the item\'s unit to a '
            .'length — and it will be counted.';
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
