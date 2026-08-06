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

    /**
     * How a COVER in the pouch column is counted.
     *
     * Not a carton and not a tray, so there is no kind whose basis it can
     * borrow: a cover holds a stated number of BOTTLES. The workbook says how
     * many in its nos_per_pouch column — 145 for 400ML ROUND, 110 and 161 for
     * the two kidney bottles, 83 for 500ML ROUND / IFF, 120 for 450ML RIBBED —
     * and the drawer already carries that count in `no_of_pouches`.
     *
     * 'per_pouch' is the wire's word and the frontend already reads it (its
     * basis matcher tests for "pouch"), so the count follows without the
     * frontend needing to know what a cover is.
     *
     * The unit pair is the pouch's own: the sheet gives covers per KILOGRAM
     * (11, 25, 20, 15) so the factor is grams a piece and the quantity is kg,
     * exactly as for a pouch. Only the count differs.
     */
    private const COVER_BASIS = [
        'basis' => 'per_pouch',
        'quantity_basis' => 'covers',
        'unit' => 'kg',
        'factor_unit' => 'g',
    ];

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
     *     reason: string, label: ?string,
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

        if ($film !== null && $this->isBag($film)) {
            // A COVER IN THE POUCH COLUMN IS NOT A POUCH, and it must not be
            // counted like one.
            //
            // The workbook's pouch column holds two different things. 67 rows
            // name a poly-olefin pouch (750*610, 780*610, 835*610) and 6 name an
            // HM or LD COVER — the owner's own distinction (06-Aug): "if it is
            // pocu 750*610, 835*610 and 780*610 ... if it is single packaging
            // conver like HM and Ld, we have the calcuatio".
            //
            // They are counted differently and the sheet says so. A pouch goes
            // over a TRAY, so five trays take five pouches. A cover holds a
            // stated number of BOTTLES — the workbook's nos_per_pouch column:
            // 145 for 400ML ROUND, 110 for 750ML KIDNEY, 161 for 500ML KIDNEY
            // LONG NECK, 83 for 500ML ROUND / IFF, 120 for 450ML RIBBED.
            //
            // Dosing a cover per tray would have been badly wrong rather than
            // slightly: 90ML RIB packs 10 trays to a box, so a per-tray cover
            // books ten covers where the box takes about one. And it is not
            // one-per-box either — 400ML ROUND is 240 bottles a box over 145 to
            // a cover, which is 1.66 covers, not 1.
            //
            // The weights are the counted ones from the same sheet as the
            // pouches (11, 25, 20 and 15 to the kilogram).
            $entries[] = $this->entry(
                PackingMaterialMapping::KIND_POUCH_FILM,
                $film,
                $standard,
                'pouch_spec',
                basis: self::COVER_BASIS,
                label: 'Cover',
            );
        } elseif ($film !== null) {
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

        // THE TWO STANDING LINES: the final carton and the polymer cover over it.
        //
        // The owner has asked for these four times — "one big box carton, one
        // more big carton has to come" (05-Aug), "one final box for all the
        // batches completion need to be add in consumption" and "still final 1
        // carton box and polymer cover mising" (06-Aug).
        //
        // They are NOT specs on a product, which is why they took so long to
        // place: the workbook has no column for either and the 38 Tally journals
        // never name one. They are standing facts about how this factory ships,
        // so they are held once, for every product, under STANDING_SPEC.
        //
        // GATED ON THERE BEING A CARTON, and that is a judgement worth stating:
        // 17 workbook rows pack straight into an HM or LD bag with no box at all,
        // and the factory's own rule for those is that the bag is the whole pack
        // ("when HM, no need to use the tray or pouch and other packing
        // material"). An outer box over a product that has no box, and a cover
        // over that, would be two invented lines on a live voucher. If the floor
        // says bags get them too, the gate is one condition.
        if ($carton !== null) {
            foreach ([PackingMaterialMapping::KIND_FINAL_CARTON, PackingMaterialMapping::KIND_POLYMER_COVER] as $standing) {
                $entries[] = $this->entry(
                    $standing,
                    PackingMaterialMapping::STANDING_SPEC,
                    $standard,
                    'carton_spec',
                    label: $standing === PackingMaterialMapping::KIND_FINAL_CARTON ? 'Final carton' : 'Polymer cover',
                );
            }
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
     *
     * @param  ?array{basis: string, quantity_basis: string, unit: string, factor_unit: string}  $basis
     * @return array{
     *     kind: string, spec: string, item: ?array{id: int, name: string},
     *     basis: string, quantity_basis: string, factor: ?string,
     *     unit: string, factor_unit: string, submit_as_stock: bool,
     *     reason: string, label: ?string,
     * }
     */
    private function entry(
        string $kind,
        string $spec,
        ProductionStandard $standard,
        string $column,
        ?string $basisKind = null,
        ?array $basis = null,
        ?string $label = null,
    ): array {
        // $basisKind re-bases the quantity onto another kind's container while
        // leaving the material and its unit alone. Used for one case: a pouch on
        // a tray-packed product is counted per TRAY, not per carton.
        //
        // $basis replaces the whole thing, for the cover in the pouch column —
        // its count is neither a carton nor a tray but a stated number of
        // bottles, so there is no kind to borrow a basis from.
        $basis ??= PackingMaterialMapping::KIND_BASIS[$kind];

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
            // THE WORD THIS ROW IS CALLED ON SCREEN, when the kind alone cannot
            // say it. The pouch column holds both pouches and covers, and the
            // owner distinguishes them explicitly — so the row that names a
            // cover says "Cover". Sent from here rather than worked out again in
            // the frontend: the rule for what is a cover (isBag) lives in this
            // class, and a second copy of it on the other side is a second thing
            // to get wrong.
            'label' => $label,
            'factor' => $filing['factor'],
            'unit' => $filing['unit'],
            'factor_unit' => $filing['factor_unit'],
            'submit_as_stock' => $filing['submit_as_stock'],
            'reason' => $this->reason($kind, $spec, $mapping, $filing, $label).$this->inferredNote($standard, $column),
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
            // ONE CARTON IS ONE CARTON WHETHER OR NOT A MAPPING SAYS SO.
            //
            // The dose for carton and tray is a property of the KIND, not of the
            // mapping row: PackingMaterialMapping::factor() already returns '1'
            // for both, and it has no column it could return anything else from.
            // Reading it only off the mapping meant an unmapped row came back
            // with a null factor — so once the completion screen let a supervisor
            // CHOOSE the carton, the quantity beside their choice stayed blank
            // and the line submitted nothing.
            //
            // A row that names a material and counts none of it is the worst of
            // the three states: the mapped row posts, the empty row visibly
            // posts nothing, and this one looks answered while posting nothing.
            // The owner's own words for the rule (05-Aug): "they need drop down
            // in the carton but the number is 1, tray also as per the standard".
            //
            // Film and tape are untouched, and must be: film's factor is grams
            // per piece and tape's is metres per box, and neither has a
            // structural default — a guessed dose is a wrong weight on a
            // voucher, which is exactly what a null is protecting against.
            'factor' => $mapping?->factor() ?? (in_array($kind, [
                PackingMaterialMapping::KIND_CARTON,
                PackingMaterialMapping::KIND_TRAY,
                // One outer box is one outer box, mapped or not — the same reason
                // a carton's factor is 1 whether or not a mapping says so. The
                // POLYMER COVER is deliberately absent: it is quoted in kilograms
                // off a grams figure, and defaulting that to 1 would read "1 Kg
                // of cover" on the floor and issue it.
                PackingMaterialMapping::KIND_FINAL_CARTON,
            ], true) ? '1' : null),
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
    private function reason(string $kind, string $spec, ?PackingMaterialMapping $mapping, array $filing, ?string $override = null): string
    {
        // $override is the caller's own word for the row, used for the one case
        // the kind cannot name: an HM/LD COVER sitting in the pouch column is a
        // cover, not a pouch, and the unresolved-row sentence has to ask for the
        // right thing ("Cover \"LD 30 X 49\" — choose the material").
        $label = $override ?? match ($kind) {
            PackingMaterialMapping::KIND_CARTON => 'Carton',
            PackingMaterialMapping::KIND_TRAY => 'Tray',
            PackingMaterialMapping::KIND_POUCH_FILM => 'Pouch',
            PackingMaterialMapping::KIND_FINAL_CARTON => 'Final carton',
            PackingMaterialMapping::KIND_POLYMER_COVER => 'Polymer cover',
            default => 'Tape',
        };

        // SHORT ENOUGH TO BE READ. The owner, seeing this screen on the floor
        // (05-Aug): "why so many English notes, will they really read them, this
        // does not look like a good production application."
        //
        // He is right, and the old sentence proved it: "Carton spec '100 ML
        // CARTON' has no packing-material mapping yet, so nothing is prefilled.
        // Set one on the packing-materials master to have this line arrive
        // filled in." Thirty words telling a supervisor mid-shift to go and
        // administer master data they have no access to. Nobody reads it, and
        // reading it would not help.
        //
        // A line with no item needs a PICKER, not a paragraph — the spec is
        // named so the operator can recognise which line is asking, and the
        // choosing happens in the control beside it.
        if ($mapping === null) {
            return "{$label} \"{$spec}\" — choose the material";
        }

        $item = (string) $mapping->item?->name;

        // The arithmetic used to be spelled out in prose ("cartons x 120 g /
        // 1000 = kg"). The screen already shows the factor, the count and the
        // result in adjacent columns, so the sentence restated three numbers the
        // operator can see. What is left is the dose, which is the one thing the
        // columns do not say on their own.
        return match ($kind) {
            PackingMaterialMapping::KIND_CARTON, PackingMaterialMapping::KIND_TRAY => $item,
            PackingMaterialMapping::KIND_POUCH_FILM, PackingMaterialMapping::KIND_POLYMER_COVER => $mapping->factor() === null
                ? "{$item} — per-piece weight not set"
                : "{$item} · {$mapping->factor()} g each",
            PackingMaterialMapping::KIND_FINAL_CARTON => $item,
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
            return "{$item} — metres per box not set";
        }

        // ALL THREE BRANCHES ARE ONE LINE NOW.
        //
        // The unanswered branch ran to 62 words, and the owner quoted the whole
        // thing back (06-Aug) with three words of his own: "this note not
        // necessary". He is right twice over — it was the longest sentence on a
        // screen a supervisor reads mid-shift, and the screen ALREADY prints
        // "not posted to stock or Tally" as its own warning line directly above
        // it, so most of those words were the same fact a second time.
        //
        // What could not be dropped is the unit: this line is metres against an
        // item Tally counts in Nos, and a figure whose unit is not stated is how
        // 229 m got filed as 229 Nos on a live voucher once already. So the
        // metres are named, and the reason they are not posted is the warning
        // line's job, not this sentence's.
        if ($filing['metres_per_unit'] !== null) {
            return "{$item} · {$filing['factor']} nos per box";
        }

        if ($filing['submit_as_stock']) {
            return "{$item} · {$metres} m per box";
        }

        return "{$item} · {$metres} m per box — metres, and Tally counts it in Nos";
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
