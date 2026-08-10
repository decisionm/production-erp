<?php

namespace App\Console\Commands;

use App\Modules\Production\Models\ProductionConfiguration;
use App\Modules\Production\Services\ProductionConfigurationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Collapses DUPLICATE approved machine-product configurations — the exact
 * shape ProductionConfigurationService::overlappingApproved() reports: same
 * product, same machine, same mould, same colour, effective windows that
 * meet. The live pairs this exists for are #49/#50 (item 339 on ASB-1) and
 * #57/#58 (item 538), where two approved rows both apply to every run and
 * the tiebreak picks one silently.
 *
 * ## What it does, precisely
 *
 * For each duplicate group it keeps the row the RESOLVER would pick — the
 * same effective_from → approved_at → id ordering resolve() uses, so the
 * figure in force does not change by even one run — and retires the rest
 * via the service's deactivate() (status → inactive, effective_to stamped).
 * Nothing is deleted: a retired row remains history, exactly as the
 * overlap-invariant tests pin.
 *
 * ## What it deliberately does NOT touch
 *
 * A general row overlapped by a mould/colour-QUALIFIED row is the designed
 * override, not a duplicate, and never appears in overlappingApproved()'s
 * exact-key groups. Draft and inactive rows are never considered.
 *
 * Dry run unless --write, like every other data command in this project —
 * and on the live instance this goes through the manual workflow, dry run
 * read FIRST (AGENTS.md hard line).
 */
class DedupeProductionConfigurations extends Command
{
    protected $signature = 'production:dedupe-configurations
        {--write : Actually retire the losing rows (default is a dry run)}
        {--ids= : Only touch groups whose ids are ALL in this comma-separated list, e.g. --ids=49,50}';

    protected $description = 'Retire duplicate approved machine-product configurations, keeping the row the resolver already uses';

    public function handle(ProductionConfigurationService $configurations): int
    {
        $onlyIds = $this->parseIds();
        if ($onlyIds === false) {
            return self::FAILURE;
        }

        $groups = $configurations->overlappingApproved();

        if ($groups === []) {
            $this->info('No overlapping approved configurations. Nothing to do.');

            return self::SUCCESS;
        }

        $rows = [];
        $plan = [];
        $skipped = 0;

        foreach ($groups as $group) {
            // --ids is a positive claim about a WHOLE group: retiring one row
            // of a group while leaving an unnamed sibling live would "fix"
            // the overlap by accident of the filter, not by decision.
            if ($onlyIds !== null && array_diff($group['configuration_ids'], $onlyIds) !== []) {
                $skipped++;

                continue;
            }

            $members = ProductionConfiguration::query()
                ->with(['item', 'workCenter'])
                ->findMany($group['configuration_ids'])
                ->sort(fn (ProductionConfiguration $a, ProductionConfiguration $b) => $this->resolutionOrder($a, $b))
                ->values();

            $keeper = $members->first();
            $losers = $members->slice(1)->values();
            $plan[] = ['keeper' => $keeper, 'losers' => $losers];

            foreach ($members as $member) {
                $rows[] = [
                    $member->item?->name ?? "item {$member->item_id}",
                    $member->workCenter?->code ?? "wc {$member->work_center_id}",
                    "#{$member->id}",
                    (string) $member->default_cycle_time,
                    (string) $member->default_cavities,
                    $member->id === $keeper->id ? 'KEEP (resolver already uses this row)' : 'retire',
                ];
            }
        }

        if ($plan === []) {
            $this->info("All {$skipped} overlapping group(s) fall outside --ids. Nothing to do.");

            return self::SUCCESS;
        }

        $this->info($this->option('write') ? 'WRITING' : 'DRY RUN — nothing written');
        $this->newLine();
        $this->table(['product', 'machine', 'config', 'CT', 'cavities', 'action'], $rows);

        if ($skipped > 0) {
            $this->warn("{$skipped} overlapping group(s) left untouched (outside --ids).");
        }

        if (! $this->option('write')) {
            $this->line('Re-run with --write after reading the plan above.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($configurations, $plan) {
            foreach ($plan as $group) {
                foreach ($group['losers'] as $loser) {
                    $configurations->deactivate($loser);
                }
            }
        });

        $retired = array_sum(array_map(fn ($group) => $group['losers']->count(), $plan));
        $this->info("Retired {$retired} duplicate configuration(s). The resolver's picks are unchanged.");

        return self::SUCCESS;
    }

    /**
     * The resolver's own tiebreak, in PHP: effective_from desc, approved_at
     * desc, id desc — with SQL DESC's null-sorts-last semantics, so the
     * keeper is byte-for-byte the row resolve() returns today. Specificity
     * ordering is irrelevant here: an exact-key group ties on mould and
     * colour by construction.
     */
    private function resolutionOrder(ProductionConfiguration $a, ProductionConfiguration $b): int
    {
        return $this->descNullsLast((string) $a->effective_from ?: null, (string) $b->effective_from ?: null)
            ?: $this->descNullsLast((string) $a->approved_at ?: null, (string) $b->approved_at ?: null)
            ?: ($b->id <=> $a->id);
    }

    private function descNullsLast(?string $a, ?string $b): int
    {
        if ($a === $b) {
            return 0;
        }
        if ($a === null) {
            return 1;
        }
        if ($b === null) {
            return -1;
        }

        return strcmp($b, $a);
    }

    /** @return list<int>|null|false null = no filter; false = unparseable. */
    private function parseIds(): array|null|false
    {
        $raw = trim((string) $this->option('ids'));
        if ($raw === '') {
            return null;
        }

        $ids = [];
        foreach (explode(',', $raw) as $piece) {
            $piece = trim($piece);
            if ($piece === '' || ! ctype_digit($piece)) {
                $this->error("--ids must be a comma-separated list of configuration ids, got \"{$piece}\".");

                return false;
            }
            $ids[] = (int) $piece;
        }

        return $ids;
    }
}
