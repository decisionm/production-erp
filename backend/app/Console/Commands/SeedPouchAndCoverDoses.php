<?php

namespace App\Console\Commands;

use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\PackingMaterialMapping;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * How much a pouch or a cover weighs, from the factory's own counted figures.
 *
 * The floor weighed a kilogram of each size and counted what was in it, and the
 * owner photographed the sheet (06-Aug). That count is the measurement; the
 * "per pouch" column beside it on the same sheet is derived from it and rounded
 * to two decimal places, which is not usable:
 *
 *   750 x 610 — 1 kg is 71 pouches, so one pouch is 0.0141 kg.
 *   The sheet rounds that to 0.01, and 1,000 pouches then read 10 kg
 *   instead of 14.08 — a 29% under-count, on a line that posts to Tally.
 *
 * So the COUNT is stored and the weight derived: grams per piece = 1000 / nos
 * per kg, at four decimal places. Nothing here rounds a factory measurement
 * before multiplying it by a shift's worth of pouches.
 *
 * WHY A COMMAND, dry by default, non-destructive: this writes master data on a
 * running factory, the same shape as the colour derivation and the packing seed.
 * A spec a person has already answered is left exactly as it is — a measured
 * figure typed by the floor outranks one seeded from a photograph.
 */
class SeedPouchAndCoverDoses extends Command
{
    protected $signature = 'production:seed-pouch-doses {--write : Apply the doses. Without it, nothing is written.}';

    protected $description = "Set pouch and cover weights from the factory's counted nos-per-kg figures";

    /**
     * spec spellings => [nos in one kg, the exact Tally item].
     *
     * THE SPELLINGS ARE THE WORKBOOK'S, not tidied. One size is written four
     * ways across its 103 rows ("750*610", "750 X 610", "750X 610") and the
     * mapping table is keyed on the literal spec string, so every spelling in
     * use needs its own row or the product carrying it silently gets no dose.
     *
     * @var list<array{0: list<string>, 1: int, 2: string}>
     */
    private const POUCHES = [
        [['750*610', '750 X 610', '750X 610', '750 x 610'], 71, 'Poly Olefin Pouch'],
        [['780*610', '780 X 610', '780X 610'], 59, 'Poly Olefin Pouch'],
        [['835*610', '835 X 610', '835X 610'], 56, 'Poly Olefin Pouch'],
    ];

    /**
     * The bags. Same shape, and they live under the CARTON kind because that is
     * the column the workbook writes them in — a bag-packed product has no
     * carton, and the bag IS its whole pack.
     *
     * "LD 28.5 X 38" and "LD28.5 X 39" are the SAME cover. The dose sheet writes
     * 39 and the workbook writes 38; the owner confirmed 38 (06-Aug). Both
     * spellings are mapped, because both appear in live standards and a product
     * carrying the loser would otherwise get nothing.
     *
     * @var list<array{0: list<string>, 1: int, 2: string}>
     */
    private const COVERS = [
        [['HM 30.5*49', 'HM 30.5 X 49', 'HM 30.5X49'], 11, 'Hm Polythene Bags -  30.5 x 49 x 200G'],
        [['LD 28.5 X 38', 'LD28.5 X 39', 'LD 28.5 X 39', 'LD28.5 X 38'], 25, 'LDPE  COVER (28.5x38x120G)'],
        [['LD 30 X 49', 'LD 30X49'], 20, 'LDPE  COVER (30x49x120G)'],
    ];

    /**
     * A size in live standards that the dose sheet does not cover.
     *
     * Reported every run rather than guessed at. It is one interpolation away
     * from 750 and 780 and that is exactly why it is not made: a film weight is
     * multiplied by every pouch in a shift and posted, and "about right" is a
     * wrong number with a confident face.
     *
     * @var list<string>
     */
    private const UNANSWERED = [
        '710x610', '710*610', '710 X 610',
        // HM 30 X 49 IS ON THIS LIST BECAUSE I PUT A WRONG NUMBER ON IT.
        //
        // The dose sheet's four cover rows are HM 30.5x49 = 11, LD 28.5x39 = 25,
        // LD 30x49 = 20 and LD 30.5x39 = 15. There is no HM 30 x 49 on it. The
        // first version of this command took the LD 30x49 count of 20 and applied
        // it to the HM bag of the same dimensions, on the unstated assumption that
        // width and height decide what a bag weighs.
        //
        // They do not — the GAUGE does, and the item names carry it: the HM bags
        // are 200G and the LDPE covers 120G. The sheet says so itself, on rows
        // measured by the factory: an HM 30.5x49 is 90.9 g and an LD 30x49 is
        // 50 g at all but identical area. Nearly twice the weight, for the ratio
        // 200:120. So 50 g for an HM 30x49 was not a rounding error, it was a
        // fabricated measurement — roughly 40% under — and it went live for half
        // an hour before I caught it.
        //
        // It is NOT replaced with an interpolated ~83 g. That would be the same
        // mistake with better arithmetic. The factory weighed a kilogram of every
        // other size; they can weigh this one.
        'HM 30 X 49', 'HM 30*49', 'HM 30X49',
    ];

    /**
     * Which production_standards column a mapping kind is looked up through.
     *
     * Mirrors PackingMaterialSuggestionService::forStandard(), which resolves
     * carton_spec as carton, tray_spec as tray and pouch_spec as pouch_film. A
     * dose is only ever reachable through its own kind's column, so any report
     * about what a dose REACHED has to read this and not guess.
     *
     * @var array<string, string>
     */
    private const SPEC_COLUMN = [
        PackingMaterialMapping::KIND_CARTON => 'carton_spec',
        PackingMaterialMapping::KIND_TRAY => 'tray_spec',
        PackingMaterialMapping::KIND_POUCH_FILM => 'pouch_spec',
    ];

    /**
     * Doses this command wrote and must take back.
     *
     * A seeded figure that turns out to be wrong cannot be left to rot in a live
     * table: it is prefilled onto a packing line and posted to Tally. So the
     * command withdraws its own bad rows, and the predicate is deliberately
     * narrow — kind, spec, the exact note this command writes, AND the exact
     * grams. Change any one of those, by editing the figure in the app or by
     * answering it properly, and the row no longer matches and is left alone.
     * The command only ever deletes a row it can prove is its own untouched
     * output.
     *
     * @var list<array{0: string, 1: list<string>, 2: string, 3: string}>
     */
    private const WITHDRAW = [
        [PackingMaterialMapping::KIND_CARTON, ['HM 30 X 49', 'HM 30*49'], '50.0000', 'Factory count, 06-Aug: 1 kg = 20 nos.'],
    ];

    /**
     * A size the factory DID count that has no item in their Tally.
     *
     * LD 30.5 x 39 is 15 to the kilogram on the dose sheet. Their packing
     * catalogue holds LDPE covers in 28.5x38, 29x40, 29x48, 30x49 and 20x33 —
     * no 30.5x39. So there is nothing to map the count to, and reporting it is
     * the only honest handling: either the item exists under a name I have not
     * seen, or the sheet's 30.5 is the 30 x 49 row written twice.
     *
     * @var array<string, int>
     */
    private const COUNTED_WITHOUT_ITEM = ['LD 30.5 X 39' => 15];

    /**
     * Round a decimal string to 4dp, half up, without touching a float.
     *
     * bcmath has no rounding of its own, so this adds a half at the fifth place
     * and lets bcadd's own truncation land on the rounded value — the standard
     * trick, kept in one place because getting it subtly wrong is invisible.
     */
    private function round4(string $value): string
    {
        return bcadd($value, '0.00005', 4);
    }

    /** Collapse runs of whitespace so a double space cannot defeat a name match. */
    private function squash(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $name)));
    }

    /**
     * Delete the doses listed in WITHDRAW, and say what was relying on them.
     *
     * HARD delete, not soft. Nothing stores a mapping's id — a completion copies
     * the quantity it filed onto its own consumption line — so removing the row
     * changes what the NEXT screen suggests and rewrites no history. A soft
     * delete would be worse than useless here: the "already answered" check
     * below reads withTrashed on purpose, so a trashed row would silently block
     * the factory's real figure from ever landing.
     *
     * @return list<string>
     */
    private function withdraw(bool $write): array
    {
        $notes = [];

        foreach (self::WITHDRAW as [$kind, $specs, $grams, $note]) {
            foreach ($specs as $spec) {
                $row = PackingMaterialMapping::query()
                    ->where('spec_kind', $kind)
                    ->where('spec_value', $spec)
                    ->where('note', $note)
                    ->first();

                if ($row === null || bccomp((string) $row->grams_per_piece, $grams, 4) !== 0) {
                    continue;
                }

                // How much this actually reached. A spec no product carries was
                // an error in a table; one that products carry was an error on
                // the floor's screen, and the difference belongs in the output
                // rather than in an assumption.
                //
                // SCOPED TO THIS MAPPING'S OWN KIND, because a dose is only ever
                // reachable through the column that looks that kind up:
                // forStandard() resolves carton_spec as carton, tray_spec as
                // tray and pouch_spec as pouch_film, and the mapping key is
                // (spec_kind, spec_value).
                //
                // A previous version widened this to all three columns to "be
                // safe" and made the report WRONG in the other direction. The
                // withdrawn HM 30 X 49 row is a CARTON mapping; that cover
                // appears in the workbook's pouch column twice and its carton
                // column not at all, so widening turned a correct "0 products
                // carried it" into "2" — telling an operator a fabricated,
                // 40%-under figure had reached two live products when it had
                // reached none. An over-report on a withdrawal notice is not the
                // harmless direction; it is a false alarm about live vouchers.
                $column = self::SPEC_COLUMN[$kind];

                $carrying = DB::table('production_standards')
                    ->whereNull('deleted_at')
                    ->where($column, $spec)
                    ->count();

                // The same spec under a DIFFERENT kind is worth saying out loud
                // rather than folding into the count: those products never used
                // this dose, but they do carry the size, so they are the ones
                // left with a blank line until the factory weighs it.
                $elsewhere = DB::table('production_standards')
                    ->whereNull('deleted_at')
                    ->where(fn ($q) => $q
                        ->where('carton_spec', $spec)
                        ->orWhere('tray_spec', $spec)
                        ->orWhere('pouch_spec', $spec))
                    ->count() - $carrying;

                $notes[] = sprintf(
                    '"%s" (%s g) withdrawn as a %s dose — never counted by the factory; %d product standard%s used it%s',
                    $spec,
                    $grams,
                    $kind,
                    $carrying,
                    $carrying === 1 ? '' : 's',
                    $elsewhere > 0
                        ? sprintf(' (%d more carry the same size in another column and never used this dose)', $elsewhere)
                        : '',
                );

                if ($write) {
                    $row->forceDelete();
                }
            }
        }

        return $notes;
    }

    public function handle(): int
    {
        $write = (bool) $this->option('write');

        if (! $write) {
            $this->warn('DRY RUN — nothing written. Re-run with --write to apply.');
            $this->line('');
        }

        $withdrawn = $this->withdraw($write);

        $set = 0;
        $kept = 0;
        $missing = [];

        // THE COVERS ARE SEEDED UNDER BOTH KINDS, because the workbook writes
        // them in two different columns and the mapping is keyed on the column's
        // kind.
        //
        // 17 rows put a cover in the CARTON column — those products pack straight
        // into the bag and it is the whole pack. 6 rows put one in the POUCH
        // column, where it is the cover that goes over a finished box: 400ML
        // ROUND, 90ML RIB, 500ML ROUND / IFF, 450ML RIBBED and the two kidney
        // bottles.
        //
        // Seeded under carton only, those 6 rows looked up kind 'pouch_film',
        // found nothing, and arrived on the floor as a cover line with no weight
        // and no kilograms — the same silent blank the masterbatch row had. The
        // weight of an LD 30 x 49 does not depend on which column names it.
        foreach ([
            [self::POUCHES, PackingMaterialMapping::KIND_POUCH_FILM],
            [self::COVERS, PackingMaterialMapping::KIND_CARTON],
            [self::COVERS, PackingMaterialMapping::KIND_POUCH_FILM],
        ] as [$table, $kind]) {
            foreach ($table as [$specs, $nosPerKg, $itemName]) {
                // WHITESPACE-TOLERANT, because their Tally names carry double
                // spaces — "LDPE  COVER (28.5x38x120G)", "Hm Polythene Bags -  30.5
                // x 49 x 200G" — and one of them transcribed with a single space
                // would silently match nothing and leave a whole size unset. Still
                // exact on content: only runs of spaces are normalised, never a
                // character dropped or a word made optional.
                $item = Item::query()->where('is_active', true)->get()
                    ->first(fn (Item $candidate) => $this->squash((string) $candidate->name) === $this->squash($itemName));

                if ($item === null) {
                    $missing[] = "\"{$itemName}\" is not an active item — ".implode(', ', $specs).' left unset';

                    continue;
                }

                // 1000 / nos-per-kg, ROUNDED to 4dp rather than truncated.
                //
                // bcdiv truncates, and the difference is real on two of these
                // seven figures: 1000/59 truncates to 16.9491 where it rounds to
                // 16.9492, and 1000/11 to 90.9090 where it rounds to 90.9091.
                // Small per piece, and this figure is multiplied by every pouch in
                // a shift — always downward, never up. A dose is the wrong place
                // to accept a one-directional error.
                $grams = $this->round4(bcdiv('1000', (string) $nosPerKg, 8));

                foreach ($specs as $spec) {
                    $existing = PackingMaterialMapping::query()
                        ->withTrashed()
                        ->where('spec_kind', $kind)
                        ->where('spec_value', $spec)
                        ->first();

                    // A FIGURE a person has already set outranks one seeded from a
                    // photograph — including a row they withdrew on purpose.
                    //
                    // A ROW IS NOT A FIGURE, though, and that distinction cost the
                    // cover its weight. The catalogue seed creates a mapping for
                    // every spec it finds in a live standard, so 'LD 28.5 X 38'
                    // and 'LD 30 X 49' already had pouch_film rows — naming the
                    // right cover and carrying no grams at all. Skipping them as
                    // "already answered" left four products (400ML ROUND, 90ML
                    // RIB, 500ML ROUND / IFF, 450ML RIBBED) with a cover line that
                    // resolved an item and still computed nothing.
                    //
                    // So a row with no dose gets the counted one, and nothing else
                    // about it is touched — not the item, not the note anyone
                    // wrote. Filling a blank is not overruling an answer.
                    // Trashed counts as answered whatever its dose: a row somebody
                    // withdrew in the app is a decision not to prefill this spec,
                    // and re-filling it would overrule them silently.
                    if ($existing !== null && ($existing->grams_per_piece !== null || $existing->trashed())) {
                        $kept++;

                        continue;
                    }

                    if ($existing !== null) {
                        $this->line(sprintf(
                            '  %-11s %-16s -> %-38s 1 kg = %2d, so %s g each  (filled a blank dose)',
                            $kind, $spec, $item->name, $nosPerKg, $grams,
                        ));

                        if ($write) {
                            $existing->forceFill(['grams_per_piece' => $grams])->save();
                        }

                        $set++;

                        continue;
                    }

                    $this->line(sprintf(
                        '  %-11s %-16s -> %-38s 1 kg = %2d, so %s g each',
                        $kind, $spec, $item->name, $nosPerKg, $grams,
                    ));

                    if ($write) {
                        PackingMaterialMapping::query()->create([
                            'spec_kind' => $kind,
                            'spec_value' => $spec,
                            'item_id' => $item->id,
                            'grams_per_piece' => $grams,
                            'note' => "Factory count, 06-Aug: 1 kg = {$nosPerKg} nos.",
                            'set_at' => now(),
                        ]);
                    }

                    $set++;
                }
            }
        }

        $this->line('');
        $this->table(
            ['set', 'already answered', 'item missing', 'withdrawn'],
            [[$set, $kept, count($missing), count($withdrawn)]],
        );

        foreach ($missing as $note) {
            $this->warn('  '.$note);
        }

        foreach ($withdrawn as $note) {
            $this->error('  '.$note);
        }

        foreach (self::UNANSWERED as $spec) {
            $this->warn("  \"{$spec}\" has no counted figure on the dose sheet — still needs the factory.");
        }

        foreach (self::COUNTED_WITHOUT_ITEM as $spec => $nosPerKg) {
            $this->warn("  \"{$spec}\" was counted (1 kg = {$nosPerKg} nos) but no item of that size exists in Tally — ask which item it is.");
        }

        return self::SUCCESS;
    }
}
