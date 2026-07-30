<?php

namespace App\Console\Commands;

use App\Modules\Inventory\Models\Item;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills in `items.colour` from the colour the Tally item name already states.
 *
 * ## Why this is reading master data, not inventing it
 *
 * This factory's Tally catalogue names a bottle by size, shape, COLOUR and unit
 * weight: "A.15ml Round Pet Bottle Amber-5gms". The colour is not a guess — it
 * is in the authoritative name, chosen by whoever created the item in Tally.
 * The `colour` COLUMN is simply empty, because the Tally sync has no separate
 * colour field to map from.
 *
 * That empty column is what makes the readiness gate report "No colour — the
 * masterbatch suggestion and the amber/clear scrap item cannot be resolved" on
 * a product whose own name says Amber. It also means the Start Batch dialog
 * asks the supervisor to state a colour the masters could have answered.
 *
 * ## The rules, and why each one is strict
 *
 *  - **Whole words only.** "Clear" must not be found inside another word.
 *  - **Longest match first**, so "Dark Amber" and "Milk White" win over
 *    "Amber" and "White". Without this every Dark Amber bottle would be
 *    recorded as Amber, which picks a different masterbatch.
 *  - **Exactly one colour, or nothing.** A name containing two colour words is
 *    ambiguous and is left alone for a person. Silence is the correct answer to
 *    a name nobody can read confidently.
 *  - **Never overwrite.** An item that already has a colour is skipped, even if
 *    the name disagrees — someone set that value deliberately and this command
 *    does not get to argue with them. Disagreements are REPORTED instead.
 *
 * ## Consequence on the floor, stated because it is a behaviour change
 *
 * Once an item carries a colour, Start Batch stops ASKING for it and shows the
 * master's value. That is what the workflow spec wants ("do not require the
 * supervisor to re-enter it manually"), and it is why the rules above refuse
 * anything doubtful: a wrong colour nobody was asked about is worse than a
 * question nobody likes being asked.
 *
 * Dry run unless --write, like every other import in this project.
 */
class DeriveItemColours extends Command
{
    /**
     * Colour vocabulary, LONGEST FIRST — the ordering is load-bearing, not
     * cosmetic. Taken from the colours this factory's own daily production
     * sheet uses plus those appearing in Tally item names.
     */
    private const COLOURS = [
        'Milk White',
        'Dark Amber',
        'Amber',
        'Clear',
        'Green',
        'Black',
        'White',
        'Blue',
        'Yellow',
        'Gold',
    ];

    protected $signature = 'inventory:derive-item-colours
        {--write : Actually write (default is a dry run)}
        {--only-products : Skip anything that looks like packaging or raw material}';

    protected $description = 'Fill in items.colour from the colour already stated in the Tally item name';

    public function handle(): int
    {
        $candidates = Item::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'sku', 'name', 'colour']);

        $toSet = [];
        $conflicts = [];
        $ambiguous = [];
        $noColour = 0;

        foreach ($candidates as $item) {
            if ($this->option('only-products') && $this->looksLikePackaging((string) $item->name)) {
                continue;
            }

            $matches = $this->coloursIn((string) $item->name);

            if ($matches === []) {
                $noColour++;

                continue;
            }

            if (count($matches) > 1) {
                // Two colour words: unreadable, so left for a person.
                $ambiguous[] = ['item' => $item, 'found' => $matches];

                continue;
            }

            $derived = $matches[0];
            $existing = trim((string) $item->colour);

            if ($existing === '') {
                $toSet[] = ['item' => $item, 'colour' => $derived];

                continue;
            }

            // Already set. Never touched — but a disagreement is worth naming,
            // because one of the two is wrong and only a person can say which.
            if (mb_strtolower($existing) !== mb_strtolower($derived)) {
                $conflicts[] = ['item' => $item, 'existing' => $existing, 'derived' => $derived];
            }
        }

        $this->info($this->option('write') ? 'WRITING' : 'DRY RUN — nothing written');
        $this->newLine();

        $this->table(['count', 'value'], [
            ['active items examined', $candidates->count()],
            ['colour would be set', count($toSet)],
            ['already set, and agreeing', $candidates->count() - count($toSet) - count($conflicts) - count($ambiguous) - $noColour],
            ['already set, but DISAGREEING with the name', count($conflicts)],
            ['ambiguous — two colour words, left alone', count($ambiguous)],
            ['no colour word in the name', $noColour],
        ]);

        if ($toSet !== []) {
            $this->newLine();
            $this->line('Colours to set:');
            foreach ($toSet as $row) {
                $this->line(sprintf('  %-12s <- %s', $row['colour'], $row['item']->name));
            }
        }

        if ($ambiguous !== []) {
            $this->newLine();
            $this->warn('Ambiguous names — left for a person to decide:');
            foreach ($ambiguous as $row) {
                $this->line('  '.$row['item']->name.'  ['.implode(' + ', $row['found']).']');
            }
        }

        if ($conflicts !== []) {
            $this->newLine();
            $this->warn('Existing colour disagrees with the name — NOT changed, please check:');
            foreach ($conflicts as $row) {
                $this->line(sprintf('  %s: stored "%s", name says "%s"', $row['item']->name, $row['existing'], $row['derived']));
            }
        }

        if (! $this->option('write')) {
            $this->newLine();
            $this->line('Re-run with --write to apply.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($toSet) {
            foreach ($toSet as $row) {
                $row['item']->update(['colour' => $row['colour']]);
            }
        });

        $this->newLine();
        $this->info(count($toSet).' item colour(s) set.');

        return self::SUCCESS;
    }

    /**
     * Every colour named in a string, whole-word and longest-first.
     *
     * A longer colour that matches removes its own text before the shorter ones
     * are tried, so "Milk White" is one match rather than "Milk White" plus
     * "White".
     *
     * @return list<string>
     */
    private function coloursIn(string $name): array
    {
        $haystack = $name;
        $found = [];

        foreach (self::COLOURS as $colour) {
            $pattern = '/\b'.preg_quote($colour, '/').'\b/i';
            $pattern = str_replace('\ ', '\s+', $pattern);

            if (preg_match($pattern, $haystack) === 1) {
                $found[] = $colour;
                $haystack = (string) preg_replace($pattern, ' ', $haystack);
            }
        }

        return $found;
    }

    /**
     * Packaging and raw material, which have colours in their names for a
     * different reason — "Master Batch Amber" IS the colourant, not a bottle
     * that happens to be amber.
     */
    private function looksLikePackaging(string $name): bool
    {
        return preg_match(
            '/master box|master carton|\btray\b|\bpad\b|packing tape|polythene|ldpe|\bcup\b|\bcap\b|mist spray|dropper|scrap|master ?batch|polyster|relpet|plastic bags|olefin pouch/i',
            $name,
        ) === 1;
    }
}
