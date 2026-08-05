<?php

namespace App\Console\Commands;

use App\Modules\Production\Services\PackingMaterialMappingService;
use Illuminate\Console\Command;

/**
 * Map the workbook's packing spec strings onto real Tally items — again, and as
 * often as the catalogue changes.
 *
 * WHY A COMMAND WHEN A MIGRATION ALREADY DID THIS. The seed shipped as a data
 * migration (2026_08_01_090002) and ran once, on 1 August — the same day the
 * Tally masters arrived. A migration runs exactly once and is then recorded as
 * done forever, so if the catalogue it matched against was still thin when it
 * fired, every spec it could not prove stayed unproven with no way to retry
 * short of another migration.
 *
 * That is what the floor hit on 5 August: the Complete Batch screen reported
 * "Carton spec '100 ML CARTON' has no packing-material mapping yet" for a
 * factory whose Tally holds "100 Ml Master Box" and "100 Ml Tray" — items the
 * matcher would resolve today. The rule was never wrong; it simply ran too
 * early and could not be asked twice.
 *
 * The consequence is not cosmetic. With no mapping the completion form arrives
 * blank, the supervisor records no cartons or trays, and the voucher carries
 * resin alone — so Tally keeps showing full stock of boxes the factory has
 * already used, drifting quietly until someone counts the shelf.
 *
 * IDEMPOTENT AND NON-DESTRUCTIVE, which is what makes re-running safe: a spec
 * already mapped is left exactly as it is, including one a person has edited or
 * withdrawn. Nothing here overwrites a human answer with a computed one.
 *
 * A MISS IS STILL THE DELIVERABLE. The matcher refuses ambiguity by design —
 * "500ML" names three trays in this catalogue, and five of the workbook's
 * pouch-film strings are millimetre dimensions no film item carries. Those are
 * questions for the factory, answered in the app, and a plausible guess printed
 * here would hide them. So the misses are listed with their reasons rather than
 * summarised away.
 */
class SeedPackingMaterialMappings extends Command
{
    protected $signature = 'production:seed-packing-mappings {--write : Apply the mappings. Without it, nothing is written.}';

    protected $description = 'Map workbook packing specs (carton, tray, pouch film, tape) onto Tally items, by evidence only';

    public function handle(PackingMaterialMappingService $service): int
    {
        $write = (bool) $this->option('write');

        if (! $write) {
            // The dry run cannot call seedFromCatalogue() — it writes. So it
            // reports what a real run would face and stops, rather than
            // pretending to a preview it cannot honestly produce.
            $this->warn('DRY RUN — nothing written. Re-run with --write to apply.');
            $this->line('');
            $this->line('This will map every unmapped carton / tray / pouch-film / tape spec');
            $this->line('found on the production standards, using only names the catalogue proves.');
            $this->line('Specs already mapped — including any a person has edited — are left alone.');

            return self::SUCCESS;
        }

        $result = $service->seedFromCatalogue();

        $this->table(
            ['outcome', 'count'],
            [
                ['mapped now', count($result['seeded'])],
                ['left for the factory to answer', count($result['missed'])],
                ['already mapped, untouched', count($result['kept'])],
            ],
        );

        if ($result['seeded'] !== []) {
            $this->line('');
            $this->info('Mapped:');
            foreach ($result['seeded'] as $row) {
                $this->line(sprintf('  %s "%s" -> "%s" (%s)',
                    $row['kind'] ?? '?', $row['spec'] ?? '?', $row['item'] ?? '?', $row['reason'] ?? ''));
            }
        }

        if ($result['missed'] !== []) {
            $this->line('');
            // Printed in full, never truncated: an unanswered spec is a blank
            // field on a supervisor's screen tomorrow morning.
            $this->warn('Left for the factory — each of these is a question, not a failure:');
            foreach ($result['missed'] as $row) {
                $this->line(sprintf('  %s "%s" — %s',
                    $row['kind'] ?? '?', $row['spec'] ?? '?', $row['reason'] ?? ''));
            }
        }

        return self::SUCCESS;
    }
}
