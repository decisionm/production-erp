<?php

namespace App\Console\Commands;

use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\PackingMaterialMapping;
use Illuminate\Console\Command;

/**
 * Name the final carton and the polymer cover — once, for the whole factory.
 *
 * Those two lines appear on every boxed batch and are held under
 * PackingMaterialMapping::STANDING_SPEC rather than per product, because neither
 * is a property of a bottle: the workbook has no column for either and the 38
 * Tally Stock Journals never name one.
 *
 * Until an item is named the rows show, say what they are, and post nothing — a
 * question on the screen rather than a guess on a voucher. This is how the answer
 * gets recorded, and there is no admin page for the packing masters yet, which is
 * why it is a command.
 *
 * The item is matched on its EXACT name, whitespace normalised because their Tally
 * names carry double spaces. A near miss is refused and the near-misses printed:
 * naming the wrong outer box issues the wrong material on every voucher of every
 * product, which is a bigger error than a blank line.
 */
class NameStandingPackingMaterial extends Command
{
    protected $signature = 'production:name-standing-packing
        {--kind= : final_carton or polymer_cover}
        {--item= : The item name exactly as Tally spells it}
        {--grams= : Grams ONE piece weighs — required for polymer_cover, ignored for final_carton}
        {--write : Apply it. Without this nothing is written.}';

    protected $description = 'Say which Tally item is the final carton, and which is the polymer cover';

    public function handle(): int
    {
        $write = (bool) $this->option('write');
        $kind = trim((string) $this->option('kind'));
        $itemName = trim((string) $this->option('item'));
        $grams = trim((string) $this->option('grams'));

        $allowed = [PackingMaterialMapping::KIND_FINAL_CARTON, PackingMaterialMapping::KIND_POLYMER_COVER];

        if (! in_array($kind, $allowed, true)) {
            $this->error('--kind must be one of: '.implode(', ', $allowed));

            return self::FAILURE;
        }

        if ($itemName === '') {
            $this->error('Give --item, the Tally item name.');

            return self::FAILURE;
        }

        // A COVER WITHOUT A WEIGHT CANNOT POST. Its quantity is kilograms derived
        // from grams a piece, so a mapping with no grams would name a material and
        // still compute nothing — the silent blank that cost the pouch and the
        // masterbatch a day each. Refused up front instead.
        if ($kind === PackingMaterialMapping::KIND_POLYMER_COVER && $grams === '') {
            $this->error('A polymer cover needs --grams (what ONE cover weighs). Without it the line would post nothing.');
            $this->line('  The counted sheet gives covers per kilogram: 11 -> 90.9091 g, 25 -> 40 g, 20 -> 50 g, 15 -> 66.6667 g.');

            return self::FAILURE;
        }

        if ($grams !== '' && (! is_numeric($grams) || bccomp($grams, '0', 4) !== 1)) {
            $this->error('--grams must be a positive number.');

            return self::FAILURE;
        }

        if (! $write) {
            $this->warn('DRY RUN — nothing written. Re-run with --write to apply.');
            $this->line('');
        }

        $squash = fn (string $name): string => mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $name)));

        $item = Item::query()
            ->where('is_active', true)
            ->get()
            ->first(fn (Item $candidate) => $squash((string) $candidate->name) === $squash($itemName));

        if ($item === null) {
            $this->error("No active item is named \"{$itemName}\". Nothing written.");

            $word = explode(' ', $itemName)[0];
            $near = Item::query()->where('is_active', true)->where('name', 'like', '%'.$word.'%')->pluck('name')->take(15);

            if ($near->isNotEmpty()) {
                $this->line('');
                $this->line("Active items containing \"{$word}\":");

                foreach ($near as $name) {
                    $this->line('  '.$name);
                }
            }

            return self::FAILURE;
        }

        $existing = PackingMaterialMapping::query()
            ->where('spec_kind', $kind)
            ->where('spec_value', PackingMaterialMapping::STANDING_SPEC)
            ->first();

        if ($existing !== null) {
            $this->warn(sprintf(
                'Already set to "%s"%s — this replaces it.',
                (string) $existing->item?->name,
                $existing->grams_per_piece === null ? '' : ' at '.$existing->grams_per_piece.' g',
            ));
        }

        $this->line(sprintf(
            '  %-14s -> %s%s',
            $kind,
            $item->name,
            $grams === '' ? '' : ' · '.$grams.' g each',
        ));

        if ($item->uom !== null) {
            $this->line('  Tally counts it in '.$item->uom.'.');
        }

        if ($write) {
            PackingMaterialMapping::query()->updateOrCreate(
                ['spec_kind' => $kind, 'spec_value' => PackingMaterialMapping::STANDING_SPEC],
                [
                    'item_id' => $item->id,
                    'grams_per_piece' => $grams === '' ? null : $grams,
                    'note' => 'Named by the factory as the standing '.str_replace('_', ' ', $kind).'.',
                    'set_at' => now(),
                ],
            );

            $this->line('');
            $this->info('Written. It will appear on every boxed batch.');
        }

        return self::SUCCESS;
    }
}
