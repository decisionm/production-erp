<?php

namespace App\Console\Commands;

use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\PackingMaterialMapping;
use Illuminate\Console\Command;

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
        [['HM 30 X 49', 'HM 30*49'], 20, 'Hm Polythene Bags -  30 x 49 x 200G'],
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
    private const UNANSWERED = ['710x610', '710*610', '710 X 610'];

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

    public function handle(): int
    {
        $write = (bool) $this->option('write');

        if (! $write) {
            $this->warn('DRY RUN — nothing written. Re-run with --write to apply.');
            $this->line('');
        }

        $set = 0;
        $kept = 0;
        $missing = [];

        foreach ([
            [self::POUCHES, PackingMaterialMapping::KIND_POUCH_FILM],
            [self::COVERS, PackingMaterialMapping::KIND_CARTON],
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

                    // A figure a person has already set outranks one seeded from
                    // a photograph — including a row they withdrew on purpose.
                    if ($existing !== null) {
                        $kept++;

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
        $this->table(['set', 'already answered', 'item missing'], [[$set, $kept, count($missing)]]);

        foreach ($missing as $note) {
            $this->warn('  '.$note);
        }

        foreach (self::UNANSWERED as $spec) {
            $this->warn("  \"{$spec}\" has no counted figure on the dose sheet — still needs the factory.");
        }

        return self::SUCCESS;
    }
}
