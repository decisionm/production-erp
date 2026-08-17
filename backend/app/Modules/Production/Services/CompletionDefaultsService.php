<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\ShiftProductionEntry;

/**
 * What the completion drawer opens WITH, read off the entry (Phase 5.5,
 * WS-B): the pack counts it is pre-filled with, and the configuration gaps
 * the run was started against. Two pure reads, no writes, so the entry
 * resource can carry both on every list without a second code path.
 *
 * `packingDefaults` — PackQuantityResolver::forEntry, the ONE reader of pack
 * quantities (P5-04), so the drawer pre-fills exactly what the metrics
 * measure against: the counts the supervisor typed once there are any (the
 * amend drawer opens on what was recorded), else the run's frozen packaging
 * row, else the item master, each figure labelled with its rung. Nothing is
 * invented: a figure no rung holds is null.
 *
 * `configurationGaps` — the missing-vocabulary of ProductVariantService, for
 * the standard and packaging THIS run froze (runStatus). A snapshot written
 * at Start under config_snapshot['configuration_gaps'] outranks the live
 * computation, so history never restates itself once a master is fixed;
 * until Start writes one, the answer is computed from the frozen ids at read
 * time and the payload says so (`source`: 'snapshot' | 'live').
 */
class CompletionDefaultsService
{
    public const GAPS_SOURCE_SNAPSHOT = 'snapshot';

    public const GAPS_SOURCE_LIVE = 'live';

    public function __construct(
        private readonly PackQuantityResolver $packQuantities,
        private readonly ProductVariantService $variants,
    ) {}

    /**
     * @return array{
     *     nos_per_box: ?int, nos_per_tray: ?int, trays_per_box: ?int,
     *     nos_per_pouch: ?int, pouches_per_box: ?int,
     *     source: string, sources: array<string, string>,
     * }
     */
    public function packingDefaults(ShiftProductionEntry $entry): array
    {
        return $this->packQuantities->forEntry($entry)->toArray();
    }

    /**
     * @return array{complete: bool, missing: list<string>, source: 'snapshot'|'live'}
     */
    public function configurationGaps(ShiftProductionEntry $entry): array
    {
        $snapshot = $entry->config_snapshot['configuration_gaps'] ?? null;

        if (is_array($snapshot) && array_key_exists('missing', $snapshot) && is_array($snapshot['missing'])) {
            $missing = array_values(array_filter($snapshot['missing'], 'is_string'));

            return [
                'complete' => $missing === [],
                'missing' => $missing,
                'source' => self::GAPS_SOURCE_SNAPSHOT,
            ];
        }

        // The frozen ids, not the item's current variants: a standard or a
        // packaging edited since the run still answers for THIS run by id.
        // loadMissing keeps whatever the list already eager-loaded.
        $entry->loadMissing(['productionStandard', 'standardPackaging', 'item']);

        $status = $this->variants->runStatus(
            $entry->productionStandard,
            $entry->standardPackaging,
            $entry->item,
        );

        return [
            'complete' => $status['missing'] === [],
            'missing' => $status['missing'],
            'source' => self::GAPS_SOURCE_LIVE,
        ];
    }
}
