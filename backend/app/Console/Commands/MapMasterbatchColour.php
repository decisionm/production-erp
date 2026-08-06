<?php

namespace App\Console\Commands;

use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\FactorySetting;
use App\Modules\Production\Services\RunMaterialSuggestionService;
use Illuminate\Console\Command;

/**
 * Say which masterbatch a colour uses, as data.
 *
 * Their masters hold more than one colourant for several colours — two ambers
 * ("Master Batch Amber", "Master Batch Pet Amber"), four whites, two blacks,
 * two greens, two yellows. resolveMasterbatchItem deliberately refuses to
 * choose between them: it returns no item, the row offers both, and the
 * supervisor names the one that went in. That is the right default, and it is
 * also a question the floor answers again on every single batch.
 *
 * ONE ROW OF DATA ENDS IT. The `masterbatch_colour_map` factory setting maps a
 * colour to an item id, and a mapped colour resolves straight to that item with
 * no ambiguity note. The owner gave the first answer (06-Aug): "Master Batch
 * Amber is the standard".
 *
 * WHY A COMMAND rather than a migration or a seeder: this is live master data
 * answering a factory question, the same shape as the colour derivation and the
 * pouch doses. Dry by default. It MERGES rather than replaces, so answering
 * white next month cannot silently drop the answer for amber.
 *
 * The item is named, never guessed — matched on its exact name, whitespace
 * normalised, and the command refuses rather than picking a near miss. Mapping
 * a colour to the wrong colourant books the wrong material to every voucher of
 * that colour, which is the exact failure the ambiguity guard exists to prevent.
 */
class MapMasterbatchColour extends Command
{
    protected $signature = 'production:map-masterbatch-colour
        {--colour= : The colour exactly as items.colour spells it, e.g. Amber}
        {--item= : The masterbatch item name exactly as Tally spells it}
        {--forget= : Remove a colour from the map instead}
        {--write : Apply it. Without this nothing is written.}';

    protected $description = 'Map a bottle colour to the masterbatch the factory uses for it';

    public function handle(): int
    {
        $write = (bool) $this->option('write');

        if (! $write) {
            $this->warn('DRY RUN — nothing written. Re-run with --write to apply.');
            $this->line('');
        }

        $setting = FactorySetting::query()
            ->where('key', RunMaterialSuggestionService::COLOUR_MAP_KEY)
            ->first();

        $map = [];

        if ($setting !== null) {
            $decoded = json_decode((string) $setting->value, true);
            $map = is_array($decoded) ? $decoded : [];
        }

        $this->line('Current map:');
        $this->showMap($map);
        $this->line('');

        $forget = trim((string) $this->option('forget'));

        if ($forget !== '') {
            if (! array_key_exists($forget, $map)) {
                $this->error("\"{$forget}\" is not in the map.");

                return self::FAILURE;
            }

            unset($map[$forget]);
            $this->line("Removing \"{$forget}\".");

            return $this->save($setting, $map, $write);
        }

        $colour = trim((string) $this->option('colour'));
        $itemName = trim((string) $this->option('item'));

        if ($colour === '' || $itemName === '') {
            $this->error('Give both --colour and --item (or --forget).');

            return self::FAILURE;
        }

        // EXACT NAME, whitespace-tolerant only. Their Tally names carry double
        // spaces, so runs of whitespace are collapsed on both sides — but no
        // character is dropped and no word is made optional. A near miss is
        // refused rather than resolved: this figure decides which material every
        // voucher of this colour issues.
        $squash = fn (string $name): string => mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $name)));

        $item = Item::query()
            ->where('is_active', true)
            ->get()
            ->first(fn (Item $candidate) => $squash((string) $candidate->name) === $squash($itemName));

        if ($item === null) {
            $this->error("No active item is named \"{$itemName}\". Nothing written.");

            $near = Item::query()
                ->where('is_active', true)
                ->where('name', 'like', '%'.explode(' ', $itemName)[0].'%')
                ->pluck('name')
                ->take(10);

            if ($near->isNotEmpty()) {
                $this->line('');
                $this->line('Did you mean one of these?');

                foreach ($near as $name) {
                    $this->line('  '.$name);
                }
            }

            return self::FAILURE;
        }

        // A colourant with no colour of its own would not be offered by the
        // dropdown this map exists to settle, so it is almost certainly the
        // wrong item — say so rather than writing a mapping nothing can use.
        if (trim((string) $item->colour) === '') {
            $this->warn("\"{$item->name}\" carries no colour of its own. Run inventory:derive-item-colours --only-masterbatch first.");
        }

        $existing = $map[$colour] ?? null;

        if ($existing !== null && (int) $existing !== (int) $item->id) {
            $this->warn(sprintf(
                '"%s" is already mapped to item #%s — this replaces it with #%d (%s).',
                $colour, $existing, $item->id, $item->name,
            ));
        }

        $map[$colour] = (int) $item->id;

        $this->line(sprintf('  %-10s -> %s (#%d)', $colour, $item->name, $item->id));

        return $this->save($setting, $map, $write);
    }

    /** @param  array<string, mixed>  $map */
    private function save(?FactorySetting $setting, array $map, bool $write): int
    {
        $this->line('');
        $this->line('Map after this change:');
        $this->showMap($map);

        if (! $write) {
            return self::SUCCESS;
        }

        $value = json_encode($map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($setting === null) {
            FactorySetting::query()->create([
                'key' => RunMaterialSuggestionService::COLOUR_MAP_KEY,
                'value' => $value,
                'data_type' => 'json',
                'scope' => 'production',
                'label' => 'Masterbatch by colour',
                'description' => 'Which masterbatch each bottle colour uses, when the item masters hold more than one candidate.',
                'is_active' => true,
            ]);
        } else {
            $setting->forceFill(['value' => $value, 'is_active' => true])->save();
        }

        $this->line('');
        $this->info('Written.');

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $map */
    private function showMap(array $map): void
    {
        if ($map === []) {
            $this->line('  (empty)');

            return;
        }

        foreach ($map as $colour => $itemId) {
            $name = Item::query()->withTrashed()->find($itemId)?->name ?? 'unknown item';
            $this->line(sprintf('  %-10s -> %s (#%s)', $colour, $name, $itemId));
        }
    }
}
