<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Data\TapeMetresPerBox;
use App\Modules\Production\Models\PackingMaterialMapping;
use Illuminate\Support\Collection;

/**
 * Which catalogue item a workbook spec string names — resolved BY EVIDENCE
 * against the real items table, or not resolved at all.
 *
 * Every method here answers with one item or with a reason. There is no third
 * outcome and no "best guess": a wrong carton mapped once becomes a wrong
 * carton on every dispatch of that product, and the cost of a blank the owner
 * fills in through the API is ten seconds. This is the same asymmetry
 * RunMaterialSuggestionService states for masterbatch ("two candidates is not
 * an answer") applied to packing.
 *
 * ## The pool comes first, and it is what makes the match possible at all
 *
 * A bare contains-match across the whole catalogue is useless here: the spec
 * "60ML" appears in BOTH "60 Ml Master Box" and "60 Ml Tray", so every small
 * bottle would resolve to two candidates and the table would seed empty. So
 * the candidates are narrowed by KIND first, on the structural word the
 * factory's own Tally names carry — the same vocabulary
 * ProductionStandardImportService's NON_PRODUCT_WORDS already uses to tell a
 * packing item from a bottle.
 *
 * ## Three matching rules, one per shape of name
 *
 *  1. CARTON and TRAY names are "<size> <qualifier?> <structural words>":
 *     "170 Ml Master Box", "200 Ml Brute Master Box", "500ml Tray". They are
 *     matched by containment first and, failing that, by size with a
 *     qualifier check — see cartonOrTray().
 *  2. FILM names are dimensions plus a per-piece weight:
 *     "LDPE  COVER (28.5x38x120G)". They are matched on the DIMENSIONS, with
 *     the spec's own family prefix (HM / LD) holding the answer to the right
 *     family — see film().
 *  3. TAPE is not matched from a spec at all. Tape is dosed by the box it
 *     seals, so the tape ITEM is a single catalogue choice (the Transparent
 *     one, while only one exists) and the dose comes from TapeMetresPerBox
 *     through the explicit pair list below.
 *
 * ## What this class refuses to do
 *
 * It never invents a rule to force a hit. "30ML ROUND" resolving to
 * "30ml Master Box" is not the word ROUND being dropped as noise — it is the
 * catalogue holding exactly ONE 30 ml carton, so the qualifier has nothing to
 * discriminate between. Where a qualifier DOES discriminate (200 round vs 200
 * brute) rule 1 resolves it by containment before the size rule is ever
 * reached, and a spec that matched neither would come back a miss.
 */
class PackingItemMatcher
{
    /**
     * The structural words that identify each kind's candidates in THIS
     * factory's Tally catalogue, taken from their own July Stock Journals.
     *
     * Deliberately tight. A pool word that is too loose does not produce a
     * wrong mapping (the match rules still have to agree) but it does produce
     * spurious ambiguity, which reads as a miss and costs the owner a click.
     *
     * @var array<string, list<string>>
     */
    private const POOL_WORDS = [
        // "15ml Round Master Box" … "300ml Emcure Master Carton".
        PackingMaterialMapping::KIND_CARTON => ['master box', 'master carton'],
        // "60 Ml Tray", "200 Ml Brute Tray", "500ml Tray".
        PackingMaterialMapping::KIND_TRAY => ['tray'],
        // "LDPE  COVER (28.5x38x120G)", "Hm Polythene Bags -  30.5 x 49 x 200G".
        PackingMaterialMapping::KIND_POUCH_FILM => ['ldpe', 'polythene', 'cover'],
        // "Packing Tape - Transparent", "Packing Tape Green".
        PackingMaterialMapping::KIND_TAPE => ['packing tape'],
    ];

    /**
     * Words that describe the PACKAGE rather than the product inside it.
     * Stripped before two names are compared on their distinguishing parts,
     * so "170 Ml Master Box" and the spec "170ML ROUND" are compared on
     * {} against {round} rather than on the boilerplate they share.
     *
     * @var list<string>
     */
    private const STRUCTURAL_WORDS = [
        'master', 'box', 'boxes', 'carton', 'cartons', 'tray', 'trays', 'pad',
        'packing', 'tape', 'cover', 'bag', 'bags', 'ml', 'cc', 'ltr', 'l', 'nos', 'no',
    ];

    /**
     * Which TapeMetresPerBox row seals which workbook carton — the ONE join
     * this build could not derive and would not guess.
     *
     * The owner's tape table is keyed by box type ("170 ROUND",
     * "500 JLI/R (FULL BOX)"); the standards carry the sheet's carton column
     * ("170ML", "500ML ROUND"). Writing a normaliser to turn one into the
     * other would be inventing a rule nobody stated — "15ML" is the ROUND
     * family only because the catalogue's own carton for it is called
     * "15ml Round Master Box", which is evidence about THIS pair and not a
     * general spelling rule.
     *
     * So the pairs are stated, one line each, checkable by eye against the
     * owner's message and the item list. Keys are the workbook string; the
     * lookup folds case and spacing.
     *
     * FOUR TAPE ROWS ARE DELIBERATELY UNPAIRED — '450 RIB', '500 KIDNEY',
     * '750 KIDNEY' and '500 JLI/R (FULL BOX)'. Every standard of those
     * families carries a poly-bag string in its carton column
     * ("HM 30.5*49", "LD 28.5 X 38"), i.e. bag-direct packing with no carton
     * step (PackingSpecInferences, STILL OPEN). Pairing them would assert a
     * box those products are not packed in.
     *
     * '300ML ROUND' is likewise absent: the tape table has no 300 ROUND row
     * and the catalogue has no 300 round carton — see the seed's misses.
     *
     * @var array<string, string> workbook carton spec => TapeMetresPerBox key
     */
    public const TAPE_BOX_TYPE_FOR_CARTON_SPEC = [
        '15ML' => '15 ROUND',            // catalogue: "15ml Round Master Box"
        '30ML' => '30 ROUND',            // catalogue: "30ml Master Box" (the only 30 ml carton)
        '30ML ROUND' => '30 ROUND',      // same carton, the sheet's other spelling
        '60ML' => '60 ROUND',            // catalogue: "60 Ml Master Box"
        '100ML' => '100 ROUND',          // catalogue: "100 Ml Master Box"
        '100ML ROUND' => '100 ROUND',    // same carton, the sheet's other spelling
        '170ML' => '170 ROUND',          // catalogue: "170 Ml Master Box"
        '170ML ROUND' => '170 ROUND',    // same carton, the sheet's other spelling
        '200ML ROUND' => '200 ROUND',    // catalogue: "200 Ml Round Master Box"
        '200ML BRUTE' => '200 BRUTE',    // catalogue: "200 Ml Brute Master Box"
        '300ML EMCURE' => '300 EMCURE',  // catalogue: "300ml Emcure Master Carton"
        '500ML ROUND' => '500 ROUND',    // catalogue: "500ml Round Master Box"
    ];

    /**
     * The candidate pool for one kind: live items whose name carries that
     * kind's structural word.
     *
     * @param  Collection<int, object{id: int, name: string}>  $items
     * @return Collection<int, object{id: int, name: string}>
     */
    public function pool(string $kind, Collection $items): Collection
    {
        $words = self::POOL_WORDS[$kind] ?? [];

        return $items->filter(function (object $item) use ($words): bool {
            $name = $this->fold((string) $item->name);

            foreach ($words as $word) {
                if (str_contains($name, $word)) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /**
     * The item one spec names, or the reason it names none.
     *
     * @param  Collection<int, object{id: int, name: string}>  $items  the live catalogue
     * @return array{item: ?object, grams_per_piece: ?string, reason: string}
     */
    public function match(string $kind, string $spec, Collection $items): array
    {
        $pool = $this->pool($kind, $items);

        if ($pool->isEmpty()) {
            return $this->miss("the catalogue holds no {$kind} item at all");
        }

        return match ($kind) {
            PackingMaterialMapping::KIND_POUCH_FILM => $this->film($spec, $pool),
            PackingMaterialMapping::KIND_TAPE => $this->tape($pool),
            default => $this->cartonOrTray($spec, $pool),
        };
    }

    /**
     * The metres one carton spec seals with, or null when this build has no
     * evidence pairing that spec to a row of the owner's tape table.
     */
    public function tapeMetresFor(string $cartonSpec): ?string
    {
        foreach (self::TAPE_BOX_TYPE_FOR_CARTON_SPEC as $spec => $boxType) {
            if ($this->fold($spec) === $this->fold($cartonSpec)) {
                return TapeMetresPerBox::METRES[$boxType] ?? null;
            }
        }

        return null;
    }

    /**
     * CARTON and TRAY.
     *
     * Pass 1 — containment. The spec, stripped to its letters and digits, has
     * to appear inside the item's. "200ML ROUND" finds "200 Ml Round Master
     * Box" and cannot find "200 Ml Brute Master Box", which is exactly the
     * discrimination the qualifier exists for. Two hits is a miss: "500ML"
     * matches three tray rows in this catalogue ("500ml Tray", "500ML IFF
     * Tray", "500ML Tray IFF") and picking one of them is the owner's call.
     *
     * Pass 2 — size, only when pass 1 found nothing. "170ML ROUND" cannot be
     * contained in "170 Ml Master Box" because Tally never spelled ROUND on
     * that box, so the candidates are narrowed to items of the same size
     * whose own qualifiers the spec also carries. That subset test is what
     * keeps "300ML ROUND" a miss: the only 300 carton is the EMCURE one, and
     * a spec that never says EMCURE has no business claiming it.
     *
     * @param  Collection<int, object{id: int, name: string}>  $pool
     * @return array{item: ?object, grams_per_piece: ?string, reason: string}
     */
    private function cartonOrTray(string $spec, Collection $pool): array
    {
        $squashed = $this->squash($spec);

        if ($squashed === '') {
            return $this->miss('the spec is blank once punctuation is stripped');
        }

        $contains = $pool->filter(fn (object $item) => str_contains($this->squash((string) $item->name), $squashed))->values();

        if ($contains->count() === 1) {
            return $this->chosen($contains->first(), $pool, 'the catalogue name contains the spec verbatim');
        }

        if ($contains->count() > 1) {
            return $this->miss($this->ambiguity($contains));
        }

        $size = $this->size($spec);

        if ($size === null) {
            return $this->miss('no catalogue name contains it, and the spec states no size to match on');
        }

        $specQualifiers = $this->qualifiers($spec);

        $bySize = $pool->filter(function (object $item) use ($size, $specQualifiers): bool {
            if ($this->size((string) $item->name) !== $size) {
                return false;
            }

            // The item may say LESS than the spec (the catalogue's 170 box
            // simply never spells ROUND) but never MORE: an EMCURE carton is
            // not what a spec reading "300ML ROUND" asked for.
            return array_diff($this->qualifiers((string) $item->name), $specQualifiers) === [];
        })->values();

        if ($bySize->count() === 1) {
            return $this->chosen(
                $bySize->first(),
                $pool,
                "the only {$size} item in the catalogue whose own description the spec also carries",
            );
        }

        return $this->miss($bySize->count() > 1
            ? $this->ambiguity($bySize)
            : "no catalogue item of size {$size} matches it");
    }

    /**
     * FILM. Matched on the dimensions the item name states, never on the
     * spec's prose.
     *
     * The spec is a dimension string with a family prefix — "LD 28.5 X 38",
     * "HM 30 X 49" — and the item name states the same dimensions plus the
     * weight of one piece: "LDPE  COVER (28.5x38x120G)" is 28.5 by 38 at 120
     * grams each. So the dimensions ARE the join, and the grams come free
     * with it.
     *
     * The family prefix is load-bearing and not decoration. "HM 30 X 49"
     * shares its dimensions with the LDPE cover "LDPE  COVER (30x49x120G)"
     * while plainly naming the HM polythene family; without the prefix check
     * this method would map an HM spec onto an LDPE item. With it, that spec
     * comes back a MISS — the only HM item in the catalogue is 30.5 x 49, not
     * 30 x 49, and a 0.5 cm difference is the factory's question to settle,
     * not this software's.
     *
     * @param  Collection<int, object{id: int, name: string}>  $pool
     * @return array{item: ?object, grams_per_piece: ?string, reason: string}
     */
    private function film(string $spec, Collection $pool): array
    {
        $dimensions = $this->dimensions($spec);

        if ($dimensions === []) {
            return $this->miss('the spec states no film dimensions to match on');
        }

        $family = $this->familyPrefix($spec);

        $candidates = $pool->filter(function (object $item) use ($dimensions, $family): bool {
            if ($family !== null && ! str_starts_with($this->fold((string) $item->name), $family)) {
                return false;
            }

            return $this->dimensions((string) $item->name) === $dimensions;
        })->values();

        if ($candidates->count() > 1) {
            return $this->ambiguityMiss($candidates);
        }

        if ($candidates->isEmpty()) {
            $sized = implode(' x ', $dimensions);
            $named = $family === null ? '' : ' in the '.strtoupper($family).' family';

            return $this->miss("no film item{$named} measures {$sized}");
        }

        $item = $candidates->first();
        $grams = $this->gramsPerPiece((string) $item->name);

        $result = $this->chosen($item, $pool, 'the film item states the same dimensions');

        if ($result['item'] === null) {
            return $result;
        }

        if ($grams === null) {
            return $this->miss(
                "\"{$item->name}\" matches the dimensions but its name states no per-piece weight, "
                .'so the kg a carton takes cannot be worked out from it',
            );
        }

        $result['grams_per_piece'] = $grams;
        $result['reason'] .= " and carries {$grams} g per piece in its own name";

        return $result;
    }

    /**
     * TAPE. Not matched from a spec — the spec on a tape row is the CARTON,
     * and every carton this factory packs is sealed from the same catalogue.
     *
     * Which cartons take Green tape rather than Transparent is explicitly
     * unanswered (owner, 31 Jul), so this seeds the Transparent item and only
     * when the catalogue holds exactly one of them. Green is a change a person
     * makes per carton through the API, which is precisely the shape of an
     * answer that has not been given yet.
     *
     * @param  Collection<int, object{id: int, name: string}>  $pool
     * @return array{item: ?object, grams_per_piece: ?string, reason: string}
     */
    private function tape(Collection $pool): array
    {
        $transparent = $pool
            ->filter(fn (object $item) => str_contains($this->fold((string) $item->name), 'transparent'))
            ->values();

        if ($transparent->count() === 1) {
            return [
                'item' => $transparent->first(),
                'grams_per_piece' => null,
                'reason' => 'the only Transparent packing tape in the catalogue; which cartons seal with '
                    .'Green instead is still open with the factory, so this row is the default and is editable',
            ];
        }

        return $this->miss($transparent->isEmpty()
            ? 'the catalogue holds no Transparent packing tape'
            : $this->ambiguity($transparent));
    }

    /**
     * A chosen item, unless the catalogue holds a TWIN of it.
     *
     * "500ML IFF Tray" and "500ML Tray IFF" are the same words in a different
     * order — one physical tray entered twice in Tally. Containment picks the
     * first happily, and picking one would post a real shift's consumption
     * against whichever row happened to sort first. Two rows naming the same
     * material is a master-data question, and master-data questions belong to
     * the owner.
     *
     * @param  Collection<int, object{id: int, name: string}>  $pool
     * @return array{item: ?object, grams_per_piece: ?string, reason: string}
     */
    private function chosen(object $item, Collection $pool, string $reason): array
    {
        $signature = $this->signature((string) $item->name);

        $twins = $pool->filter(fn (object $other) => (int) $other->id !== (int) $item->id
            && $this->signature((string) $other->name) === $signature)->values();

        if ($twins->isNotEmpty()) {
            return $this->miss(sprintf(
                '"%s" and "%s" name the same material in the catalogue — which row a shift consumes is a '
                .'master-data question for the factory, not a pick this seed may make',
                $item->name,
                $twins->first()->name,
            ));
        }

        return ['item' => $item, 'grams_per_piece' => null, 'reason' => $reason];
    }

    /** @return array{item: null, grams_per_piece: null, reason: string} */
    private function miss(string $reason): array
    {
        return ['item' => null, 'grams_per_piece' => null, 'reason' => $reason];
    }

    /**
     * @param  Collection<int, object{id: int, name: string}>  $candidates
     * @return array{item: null, grams_per_piece: null, reason: string}
     */
    private function ambiguityMiss(Collection $candidates): array
    {
        return $this->miss($this->ambiguity($candidates));
    }

    /** @param Collection<int, object{id: int, name: string}> $candidates */
    private function ambiguity(Collection $candidates): string
    {
        return sprintf(
            '%d catalogue items match it (%s) — one of them is the answer and this seed may not pick which',
            $candidates->count(),
            $candidates->map(fn (object $item) => '"'.$item->name.'"')->implode(', '),
        );
    }

    /** Lowercased, '*' and '×' read as 'x', whitespace collapsed. */
    private function fold(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(['*', '×'], 'x', $value);

        return preg_replace('/\s+/', ' ', $value) ?? '';
    }

    /** fold(), with every character that is not a letter or a digit removed. */
    private function squash(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', $this->fold($value)) ?? '';
    }

    /**
     * The leading size of a spec or a packing item name — '170ML' and
     * "170 Ml Master Box" both give '170'. Null when the name does not open
     * with a number ("LDPE  COVER …", "Packing Tape - Transparent").
     */
    private function size(string $value): ?string
    {
        return preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*(?:ml|cc|ltr|l)?\b/', $this->fold($value), $matches) === 1
            ? $this->trimNumber($matches[1])
            : null;
    }

    /**
     * What distinguishes this name beyond its size and the packaging
     * boilerplate: 'round', 'brute', 'emcure', 'iff'.
     *
     * @return list<string>
     */
    private function qualifiers(string $value): array
    {
        $tokens = preg_split('/[^a-z0-9.]+/', $this->fold($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $qualifiers = array_values(array_filter($tokens, function (string $token): bool {
            if (in_array($token, self::STRUCTURAL_WORDS, true)) {
                return false;
            }

            // A number, with or without its unit glued on ('170', '15ml').
            return preg_match('/^[0-9]+(?:\.[0-9]+)?(?:ml|cc|ltr|l|g)?$/', $token) !== 1;
        }));

        sort($qualifiers);

        return $qualifiers;
    }

    /**
     * The dimension figures a film name or spec states, EXCLUDING the
     * per-piece weight: "LDPE  COVER (28.5x38x120G)" is 28.5 by 38, at 120 g.
     * The weight is told apart by its own unit letter, which every film item
     * in this catalogue carries.
     *
     * @return list<string>
     */
    private function dimensions(string $value): array
    {
        preg_match_all('/([0-9]+(?:\.[0-9]+)?)\s*(g)?/', $this->fold($value), $matches, PREG_SET_ORDER);

        $dimensions = [];

        foreach ($matches as $match) {
            if (($match[2] ?? '') !== '') {
                continue;
            }
            $dimensions[] = $this->trimNumber($match[1]);
        }

        return $dimensions;
    }

    /** The grams one piece of this film weighs, from its own name ("…x120G" = 120). */
    private function gramsPerPiece(string $name): ?string
    {
        return preg_match('/([0-9]+(?:\.[0-9]+)?)\s*g\b/', $this->fold($name), $matches) === 1
            ? $this->trimNumber($matches[1])
            : null;
    }

    /**
     * The family a film spec opens with — 'hm', 'ld' — or null when it opens
     * with a dimension instead ("750*610").
     */
    private function familyPrefix(string $spec): ?string
    {
        return preg_match('/^([a-z]{2,4})\b/', $this->fold($spec), $matches) === 1 ? $matches[1] : null;
    }

    /**
     * A name's words, sorted — so two catalogue rows spelling one material in
     * a different order collapse to the same string.
     */
    private function signature(string $name): string
    {
        $tokens = preg_split('/[^a-z0-9.]+/', $this->fold($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($tokens);

        return implode(' ', $tokens);
    }

    /** '28.50' and '28.5' are one dimension; '30.0' and '30' are one size. */
    private function trimNumber(string $number): string
    {
        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }
}
