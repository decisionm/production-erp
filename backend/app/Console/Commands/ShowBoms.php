<?php

namespace App\Console\Commands;

use App\Modules\Production\Models\Bom;
use Illuminate\Console\Command;

/**
 * One read-only answer to "which Bills of Material exist on LIVE, and what
 * does each one actually say?".
 *
 * WHY THIS EXISTS AT ALL. A BOM here is not a consumption instruction — no
 * Tally voucher reads one (grep TallySync: zero references). Its single
 * live job is in ShiftProductionEntryService: the kg-bearing lines of the
 * ACTIVE BOM are summed into a kg-per-unit figure, and that figure is the
 * norm a completed shift's real output is compared against. So a BOM with
 * no kg line silently provides NO norm, and a product with no active BOM
 * provides none either — in both cases the comparison is simply absent
 * rather than wrong, which is exactly the kind of quiet gap nobody notices
 * from a screen. This command names them.
 *
 * WHY A COMMAND RATHER THAN A LOOK AT THE SCREEN. The Bills of Material
 * page answers "what is there"; it does not answer "which of these carry a
 * mass line". More importantly, dev fixtures are not live-shaped (the
 * 09-Aug shift-rail defect came from trusting them), and AGENTS.md requires
 * live data to be counted on the live instance. This is the evidence-grade
 * read.
 *
 * Strictly read-only by construction: SELECTs only, no options that write,
 * no state touched — safe against the live database at any hour. It creates
 * nothing, so it needs no dry run and no confirmation gate.
 */
class ShowBoms extends Command
{
    protected $signature = 'boms:show';

    protected $description = 'Read-only: every Bill of Material, its lines, and which ones carry no kg line (so provide no unit-weight norm).';

    /** Flag for an ACTIVE BOM whose lines contain no mass component. */
    public const string NO_NORM_FLAG = 'NO-KG-LINE-PROVIDES-NO-WEIGHT-NORM';

    /**
     * MIRRORS ShiftProductionEntryService::isMassUom() DELIBERATELY, and not
     * Item::hasKgUom(), because the two do not agree and only one of them
     * defines the norm this report is about.
     *
     *   isMassUom()  strips a trailing dot, then matches
     *                kg | kgs | kilogram | kilograms
     *   hasKgUom()   matches the literal set kg | kg. | kgs | kgs.
     *
     * So an item whose Tally UOM reads "Kilograms" DOES contribute to the
     * live norm and does NOT satisfy hasKgUom(). Reporting through the model
     * helper would print "no norm" for a BOM that in fact has one — the one
     * failure a report like this must never have.
     *
     * That divergence is a real latent defect in its own right (scopeKgUom
     * and every caller of hasKgUom share it), but it belongs to the modules
     * that own those helpers, not to a read-only report — fixing it here
     * would mean this command and the shift floor disagreeing during the
     * window before that fix ships. Raised separately; mirrored faithfully
     * meanwhile.
     */
    private static function countsTowardNorm(?string $uom): bool
    {
        return in_array(
            rtrim(strtolower(trim((string) $uom)), '.'),
            ['kg', 'kgs', 'kilogram', 'kilograms'],
            true,
        );
    }

    public function handle(): int
    {
        // withTrashed on the finished item and the components: a soft-deleted
        // master still carries the UOM this report reads, and a BOM pointing
        // at a retired item is precisely the kind of row worth seeing rather
        // than silently dropping. Mirrors the batch path, which loads
        // components withTrashed for the same reason.
        $boms = Bom::query()
            ->with([
                'item' => fn ($q) => $q->withTrashed(),
                'lines.component' => fn ($q) => $q->withTrashed(),
            ])
            ->orderBy('item_id')
            ->orderBy('version')
            ->get();

        if ($boms->isEmpty()) {
            $this->warn('No Bills of Material in this database.');
            $this->newLine();
            $this->info('VERDICT: 0 BOMs. Every product therefore runs with NO unit-weight norm — the shift comparison is absent, not wrong.');

            return self::SUCCESS;
        }

        $active = 0;
        $flagged = 0;

        foreach ($boms as $bom) {
            $isActive = (bool) $bom->is_active;
            if ($isActive) {
                $active++;
            }

            $product = trim((string) ($bom->item?->name ?? '')) ?: "item #{$bom->item_id}";
            $sku = trim((string) ($bom->item?->sku ?? '')) ?: '-';

            $kgPerUnit = '0';
            $hasKgLine = false;
            foreach ($bom->lines as $line) {
                if (self::countsTowardNorm($line->component?->uom)) {
                    $hasKgLine = true;
                    $kgPerUnit = bcadd($kgPerUnit, (string) $line->quantity_per, 4);
                }
            }

            $noNorm = $isActive && ! $hasKgLine;
            if ($noNorm) {
                $flagged++;
            }

            // One fact per line, deliberately. The first draft packed all of
            // this into a single line and it ran past the width a GitHub step
            // summary shows without wrapping — which is the only place anyone
            // will ever read this output.
            $this->line(sprintf('%s [%s]', $product, $sku));
            $this->line(sprintf(
                '    bom="%s" v%s  %s  lines=%d',
                (string) $bom->name,
                (string) $bom->version,
                $isActive ? 'ACTIVE' : 'inactive',
                $bom->lines->count(),
            ));
            $this->line(sprintf('    kg/unit = %s', $hasKgLine ? $kgPerUnit : '(none)'));

            if ($noNorm) {
                $this->line('    !! '.self::NO_NORM_FLAG);
            }

            foreach ($bom->lines as $line) {
                $component = trim((string) ($line->component?->name ?? '')) ?: "item #{$line->component_item_id}";
                $uom = trim((string) ($line->component?->uom ?? '')) ?: '?';
                $this->line(sprintf(
                    '    - %s  qty_per=%s %s%s',
                    $component,
                    (string) $line->quantity_per,
                    $uom,
                    self::countsTowardNorm($line->component?->uom) ? '  (counts toward the norm)' : '',
                ));
            }
        }

        $this->newLine();
        $this->line(sprintf('%d BOM(s); %d active, %d inactive.', $boms->count(), $active, $boms->count() - $active));

        if ($flagged === 0) {
            $this->info('VERDICT: every ACTIVE BOM carries at least one kg line, so each provides a unit-weight norm to the shift comparison.');
        } else {
            $this->warn(sprintf(
                'VERDICT: %d ACTIVE BOM(s) carry no kg line — flagged above. Each provides NO unit-weight norm, so shifts on those products are compared against nothing. That is a missing check, not a wrong number.',
                $flagged,
            ));
        }

        return self::SUCCESS;
    }
}
