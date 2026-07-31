<?php

use App\Modules\Production\Models\MasterbatchDosing;
use Illuminate\Database\Migrations\Migration;

/**
 * Amber masterbatch: 0.25 → 0.32 g per bottle, on the owner's instruction.
 *
 * The 0.25 was the figure the owner quoted from memory this morning. Checked
 * against the factory's own July Tally export, the books said otherwise:
 * 100 kg of Master Batch Amber consumed across 313,746 amber bottles over the
 * four days that could be measured — 0.319 g per bottle, a fifth more than
 * the quoted figure. Shown the numbers, the owner ruled: "if July books say
 * 0.32, fix that, but they can change any time."
 *
 * So 0.3200, with the provenance in the note, and the "change any time" part
 * is already true by construction — the dosing is editable master data
 * through the API, and this migration deliberately refuses to overwrite a
 * value someone has already changed by hand: it only moves a row still
 * standing at the seeded 0.2500. Running after a manual correction is a
 * no-op, not a regression to 0.32.
 */
return new class extends Migration
{
    public function up(): void
    {
        MasterbatchDosing::query()
            ->where('grams_per_bottle', '0.2500')
            ->whereHas('masterbatchItem', function ($query) {
                $query->whereRaw("LOWER(REPLACE(name, ' ', '')) LIKE ?", ['%masterbatch%amber%'])
                    ->orWhereRaw("LOWER(REPLACE(name, ' ', '')) LIKE ?", ['%amber%masterbatch%']);
            })
            ->get()
            ->each(function (MasterbatchDosing $dosing) {
                $dosing->update([
                    'grams_per_bottle' => '0.3200',
                    'note' => 'July books: 100 kg over 313,746 amber bottles ≈ 0.32 g/bottle. '
                        .'Owner (31 Jul 2026): "if July books say 0.32, fix that, but they can change any time." '
                        .'Editable on the app any time.',
                ]);
            });
    }

    public function down(): void
    {
        // Deliberately empty: rolling back a factory figure by migration would
        // overwrite whatever a person has set since. Corrections go forward,
        // through the API.
    }
};
