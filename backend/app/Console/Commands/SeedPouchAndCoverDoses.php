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
                // EVERY SPEC COLUMN, not just the carton one. The first version
                // of this counted carton_spec alone and reported "0 product
                // standards carried it" for HM 30 X 49 — which was wrong, and
                // wrong in the direction that makes an error look harmless. The
                // workbook puts that same cover in the POUCH column on two
                // products (750ML KIDNEY, 500ML KIDNEY LONG NECK), because a
                // cover can be either the whole pack or the wrap that goes over
                // a finished box. A spec string is not owned by one column.
                $carrying = DB::table('production_standards')
                    ->whereNull('deleted_at')
                    ->where(fn ($q) => $q
                        ->where('carton_spec', $spec)
                        ->orWhere('tray_spec', $spec)
                        ->orWhere('pouch_spec', $spec))
                    ->count();

                $notes[] = sprintf(
                    '"%s" (%s g) withdrawn — never counted by the factory; %d product standard%s carried it',
                    $spec, $grams, $carrying, $carrying === 1 ? '' : 's',
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
