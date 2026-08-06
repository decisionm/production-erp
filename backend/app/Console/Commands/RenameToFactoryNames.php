<?php

namespace App\Console\Commands;

use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Console\Command;

/**
 * Call the machines and the shifts what the factory calls them.
 *
 * The floor's own paperwork — the handwritten PRODUCTION REPORT, the IDLE TIME
 * REPORT and the mould-change log — writes its machines as ASB-1 to ASB-10 and
 * its shifts as A, B and C. This database says MC-01 to MC-10, and Morning,
 * Afternoon and Night. A supervisor holding the paper and looking at the screen
 * is reading two different vocabularies for one factory, and the manager's
 * complaint about the app not looking like a production tool is partly just this.
 *
 * ONLY THE CODE CHANGES ON A MACHINE, not its name. This deployment already
 * distinguishes the two deliberately — "the floor calls machines by code and the
 * office by name" — and the screens render "Machine 3 (MC-03)". Changing only the
 * code gives "Machine 3 (ASB-3)", which reads correctly to both readers. Renaming
 * the name as well would delete the office's vocabulary to add the floor's.
 *
 * BATCH NUMBERS ARE UNAFFECTED, which is worth stating because it looks like the
 * obvious hazard. generateBatchNumber() extracts the first run of digits from the
 * code and left-pads it to two: MC-01 gives "01" and ASB-1 gives "1" padded to
 * "01" — the same tag. MC-10 and ASB-10 both give "10". So 20260805-M01-001 is
 * minted identically before and after, and every existing batch number is a
 * stored string that nothing recomputes.
 *
 * A COMMAND, NOT A MIGRATION, and dry by default. This writes live master data,
 * which this project holds must be a deliberate act rather than a deploy side
 * effect — same shape as the colour derivation and the packing-mapping seed. It
 * is also re-runnable, which a migration is not, for the day the factory adds a
 * machine.
 *
 * NON-DESTRUCTIVE. A code or name that is not one of the exact values below is
 * left alone and reported: the factory may have corrected it themselves, and a
 * rename that overwrites a human's answer with a computed one is the failure this
 * whole class of command exists to avoid.
 */
class RenameToFactoryNames extends Command
{
    protected $signature = 'production:rename-to-factory-names {--write : Apply the renames. Without it, nothing is written.}';

    protected $description = 'Rename machines to ASB-n and shifts to A/B/C — the names on the factory\'s own paperwork';

    /**
     * Shift start time => the letter the paperwork uses.
     *
     * KEYED ON START TIME, not on the current name, because the start time is
     * the shift's real identity and a name is what we are about to change. It
     * also makes the assumption auditable rather than implicit: A is the day's
     * FIRST shift, B the second, C the third. That ordering is near-universal on
     * an Indian factory floor and matches Morning/Afternoon/Night exactly, but it
     * is an assumption — so it is written here where someone can disagree with
     * it, not buried in a string comparison.
     *
     * @var array<string, string>
     */
    private const SHIFT_LETTER = [
        '06:00' => 'A',
        '14:00' => 'B',
        '22:00' => 'C',
    ];

    public function handle(): int
    {
        $write = (bool) $this->option('write');

        if (! $write) {
            $this->warn('DRY RUN — nothing written. Re-run with --write to apply.');
            $this->line('');
        }

        $machines = $this->machines($write);
        $shifts = $this->shifts($write);

        $this->line('');
        $this->table(['what', 'renamed', 'already right', 'left alone'], [
            ['machines', $machines['renamed'], $machines['kept'], count($machines['skipped'])],
            ['shifts', $shifts['renamed'], $shifts['kept'], count($shifts['skipped'])],
        ]);

        foreach ([...$machines['skipped'], ...$shifts['skipped']] as $note) {
            $this->warn('  left alone: '.$note);
        }

        return self::SUCCESS;
    }

    /** @return array{renamed: int, kept: int, skipped: list<string>} */
    private function machines(bool $write): array
    {
        $renamed = 0;
        $kept = 0;
        $skipped = [];

        foreach (WorkCenter::query()->orderBy('id')->get() as $machine) {
            $code = trim((string) $machine->code);

            if (preg_match('/^ASB-\d+$/i', $code)) {
                $kept++;

                continue;
            }

            // Exactly MC- followed by digits, and nothing else. The five
            // demo work centres this database also holds — INJ-01, BLOW-01,
            // EBM-01, LABEL-01, PACK-01 — are not this factory's machines and
            // are deliberately not touched. They are a separate question about
            // whether they should be selectable at all.
            if (! preg_match('/^MC-0*(\d+)$/i', $code, $m)) {
                $skipped[] = "machine \"{$code}\" — not an MC-n code, so not ours to rename";

                continue;
            }

            // ASB-1, not ASB-01: the paperwork writes single digits bare.
            $target = 'ASB-'.((int) $m[1]);

            if (WorkCenter::query()->where('code', $target)->whereKeyNot($machine->id)->exists()) {
                $skipped[] = "machine \"{$code}\" — \"{$target}\" already belongs to another machine";

                continue;
            }

            $this->line(sprintf('  %-8s -> %-8s (%s)', $code, $target, $machine->name));

            if ($write) {
                $machine->forceFill(['code' => $target])->save();
            }

            $renamed++;
        }

        return ['renamed' => $renamed, 'kept' => $kept, 'skipped' => $skipped];
    }

    /** @return array{renamed: int, kept: int, skipped: list<string>} */
    private function shifts(bool $write): array
    {
        $renamed = 0;
        $kept = 0;
        $skipped = [];

        foreach (Shift::query()->orderBy('start_time')->get() as $shift) {
            $name = trim((string) $shift->name);
            // Stored as a time; compared on HH:MM so a seconds component or a
            // full timestamp does not defeat the lookup.
            $start = substr((string) $shift->start_time, 0, 5);
            $letter = self::SHIFT_LETTER[$start] ?? null;

            if ($letter !== null && $name === $letter) {
                $kept++;

                continue;
            }

            if ($letter === null) {
                $skipped[] = "shift \"{$name}\" starts at {$start}, which is not one of the factory's three";

                continue;
            }

            // Only the three names this deployment shipped with. Anything else
            // is the factory's own wording and stays.
            if (! in_array($name, ['Morning', 'Afternoon', 'Night'], true)) {
                $skipped[] = "shift \"{$name}\" ({$start}) is not a default name — leaving the factory's own";

                continue;
            }

            $this->line(sprintf('  %-10s -> %-10s (starts %s)', $name, $letter, $start));

            if ($write) {
                $shift->forceFill(['name' => $letter])->save();
            }

            $renamed++;
        }

        return ['renamed' => $renamed, 'kept' => $kept, 'skipped' => $skipped];
    }
}
