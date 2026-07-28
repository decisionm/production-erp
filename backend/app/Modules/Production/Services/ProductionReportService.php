<?php

namespace App\Modules\Production\Services;

use App\Modules\Inventory\Services\TraceabilityService;
use App\Modules\Production\Models\DayBinMovement;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\DayBinMovementType;
use App\Modules\Production\Models\ShiftProductionEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read-only reporting over completed shift production entries (reports
 * wave). Pure aggregation — no writes, no schema, no Tally. Every per-entry
 * figure is computed by ShiftProductionEntryService (productionMetrics /
 * consumptionVariance); this service only queries, iterates, and sums —
 * the formulas live in exactly one place (SHIFT-REDESIGN-FORMULAS.md).
 *
 * Aggregation rule (dictionary row 24, WB2 totals row): day/period
 * efficiency is Σ actual boxes / Σ expected boxes × 100 — a RATIO OF SUMS,
 * never the average of the row percentages. Rows whose expected_boxes is
 * null (standards not captured) contribute to NEITHER sum: they can't join
 * the denominator (no expectation exists) and letting their actual boxes
 * into the numerator would inflate the ratio against everyone else's
 * expectations. Their actuals still appear in the plain column totals —
 * only the efficiency ratio excludes them.
 */
class ProductionReportService
{
    /**
     * Reports are unpaginated computations per entry — a bounded window
     * keeps them honest list endpoints. ~3 months, enforced with a clear
     * 422 by the report FormRequests.
     */
    public const MAX_RANGE_DAYS = 92;

    public function __construct(
        private readonly ShiftProductionEntryService $entries,
        private readonly TraceabilityService $traceability,
    ) {}

    /**
     * The daily production sheet: one row per completed entry with the
     * expected/actual/efficiency block, plus ratio-of-sums totals.
     *
     * @return array{date: string, rows: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function productionReport(string $date, ?int $shiftId = null, ?int $workCenterId = null): array
    {
        $entries = $this->completedEntries(fn (Builder $query) => $query
            ->whereDate('production_date', $date)
            ->when($shiftId, fn (Builder $inner) => $inner->where('shift_id', $shiftId))
            ->when($workCenterId, fn (Builder $inner) => $inner->where('work_center_id', $workCenterId)));

        $rows = [];
        $totals = [
            'entries' => $entries->count(),
            'expected_boxes' => null,
            'actual_boxes' => null,
            'actual_pieces' => '0',
            'good_production_kg' => '0.0000',
            'rejection_kg_production' => '0.0000',
            'rejection_kg_qc' => '0.0000',
            'lumps_kg' => '0.0000',
        ];

        // The ratio-of-sums accumulators (see class docblock): only rows
        // with a non-null expectation feed either side.
        $eligibleActualBoxes = 0;
        $eligibleExpectedBoxes = 0;

        foreach ($entries as $entry) {
            $metrics = $this->entries->productionMetrics($entry);

            // Row keys reuse the ProductionMetrics key names VERBATIM (the
            // frontend types are written against them) — never rename on
            // the way out.
            $rows[] = $this->identity($entry) + [
                'running_hours' => $entry->running_hours !== null ? (string) $entry->running_hours : null,
                'expected_pieces' => $metrics['expected_pieces'],
                'expected_boxes' => $metrics['expected_boxes'],
                'actual_boxes' => $metrics['actual_boxes'],
                'actual_pieces' => $metrics['actual_pieces'],
                'good_production_kg' => $metrics['good_production_kg'],
                'rejection_kg_production' => $metrics['rejection_kg_production'],
                'rejection_kg_qc' => $metrics['rejection_kg_qc'],
                'lumps_kg' => $metrics['lumps_kg'],
                'efficiency_pct' => $metrics['efficiency_pct'],
                'efficiency_band' => $metrics['efficiency_band'],
            ];

            if ($metrics['expected_boxes'] !== null) {
                $totals['expected_boxes'] = ($totals['expected_boxes'] ?? 0) + $metrics['expected_boxes'];
                $eligibleExpectedBoxes += $metrics['expected_boxes'];
                // Expected without actual still weighs the denominator —
                // "expected to produce, nothing recorded" drags the ratio,
                // it doesn't vanish from it.
                $eligibleActualBoxes += $metrics['actual_boxes'] ?? 0;
            }

            if ($metrics['actual_boxes'] !== null) {
                $totals['actual_boxes'] = ($totals['actual_boxes'] ?? 0) + $metrics['actual_boxes'];
            }

            // Pieces are counts — 0dp; the kg figures keep the engine's 4dp.
            $totals['actual_pieces'] = bcadd($totals['actual_pieces'], $metrics['actual_pieces'] ?? '0', 0);
            foreach ([
                'good_production_kg' => $metrics['good_production_kg'],
                'rejection_kg_production' => $metrics['rejection_kg_production'],
                'rejection_kg_qc' => $metrics['rejection_kg_qc'],
                'lumps_kg' => $metrics['lumps_kg'],
            ] as $key => $value) {
                if ($value !== null) {
                    $totals[$key] = bcadd($totals[$key], $value, 4);
                }
            }
        }

        // Σ actual / Σ expected × 100, 1dp — null (not 0, not 100) when no
        // row carried an expectation: no denominator means no ratio. Same
        // key name as the per-row metric — the totals row is the same
        // figure at a coarser grain, not a different concept.
        $totals['efficiency_pct'] = $eligibleExpectedBoxes > 0
            ? round($eligibleActualBoxes / $eligibleExpectedBoxes * 100, 1)
            : null;

        return ['date' => $date, 'rows' => $rows, 'totals' => $totals];
    }

    /**
     * Material reconciliation per completed entry over a date range,
     * ordered worst unaccounted-material first (largest |unaccounted_kg|;
     * entries that can't reconcile — null — sink to the bottom).
     *
     * @return array{date_from: string, date_to: string, rows: array<int, array<string, mixed>>}
     */
    public function reconciliationReport(string $dateFrom, string $dateTo, ?int $shiftId = null): array
    {
        $entries = $this->completedEntries(fn (Builder $query) => $query
            ->whereDate('production_date', '>=', $dateFrom)
            ->whereDate('production_date', '<=', $dateTo)
            ->when($shiftId, fn (Builder $inner) => $inner->where('shift_id', $shiftId)));

        $rows = [];
        foreach ($entries as $entry) {
            $metrics = $this->entries->productionMetrics($entry);
            $variance = $this->entries->consumptionVariance($entry);

            // Same rule as productionReport(): ProductionMetrics key names
            // verbatim, never renamed on the way out.
            $rows[] = $this->identity($entry) + [
                'issued_kg' => $metrics['issued_kg'],
                'good_production_kg' => $metrics['good_production_kg'],
                'confirmed_rejection_kg' => $metrics['confirmed_rejection_kg'],
                'lumps_kg' => $metrics['lumps_kg'],
                'reconciliation_unaccounted_kg' => $metrics['reconciliation_unaccounted_kg'],
                'unaccounted_band' => $metrics['unaccounted_band'],
                'variance_pct' => $variance['variance_pct'],
                'variance_band' => $variance['variance_band'],
            ];
        }

        usort($rows, function (array $a, array $b): int {
            // Worst first; null (not reconcilable) is not "perfect", it is
            // unknown — it sorts after every real figure.
            $left = $a['reconciliation_unaccounted_kg'] !== null ? abs((float) $a['reconciliation_unaccounted_kg']) : -1.0;
            $right = $b['reconciliation_unaccounted_kg'] !== null ? abs((float) $b['reconciliation_unaccounted_kg']) : -1.0;

            return $right <=> $left ?: $a['entry_id'] <=> $b['entry_id'];
        });

        return ['date_from' => $dateFrom, 'date_to' => $dateTo, 'rows' => $rows];
    }

    /**
     * Lot → bag → machine/segment chain (Phase 6): every lot received in
     * the window with its bags (status, remaining) and, per bag, the
     * machines and batch segments it fed via day-bin load movements. Only
     * reachable when production.traceability_enabled is on (route-gated) —
     * with the flag off the report does not exist.
     *
     * Bag/lot data comes through Inventory's TraceabilityService (their
     * module); the feed map comes from this module's day_bin_movements.
     *
     * @return array{date_from: string, date_to: string, lots: array<int, array<string, mixed>>}
     */
    public function traceabilityReport(?int $lotId, ?int $itemId, string $dateFrom, string $dateTo): array
    {
        $lots = $this->traceability->lotsForReport($lotId, $itemId, $dateFrom, $dateTo);

        $bagIds = $lots->flatMap(fn ($lot) => $lot->bags->pluck('id'))->all();

        // One query for every load row of every bag in the report — grouped
        // in PHP, never per-bag queries.
        $feeds = $bagIds === [] ? collect() : DayBinMovement::query()
            ->whereIn('material_bag_id', $bagIds)
            ->where('type', DayBinMovementType::Load->value)
            ->with(['workCenter', 'shiftProductionEntry'])
            ->orderBy('id')
            ->get()
            ->groupBy('material_bag_id');

        $lotRows = [];
        foreach ($lots as $lot) {
            $bags = [];
            foreach ($lot->bags as $bag) {
                $bags[] = [
                    'id' => $bag->id,
                    'barcode' => $bag->barcode,
                    'status' => $bag->status?->value,
                    'original_kg' => $bag->original_kg,
                    'remaining_kg' => $bag->remaining_kg,
                    'fed' => $this->feedDestinations($feeds->get($bag->id) ?? collect()),
                ];
            }

            $lotRows[] = [
                'id' => $lot->id,
                'supplier_lot_no' => $lot->supplier_lot_no,
                'received_date' => $lot->received_date?->toDateString(),
                'item' => [
                    'id' => $lot->item?->id,
                    'sku' => $lot->item?->sku,
                    'name' => $lot->item?->name,
                ],
                'bag_count' => $lot->bag_count,
                'total_received_kg' => $lot->total_received_kg,
                'bags' => $bags,
            ];
        }

        return ['date_from' => $dateFrom, 'date_to' => $dateTo, 'lots' => $lotRows];
    }

    /**
     * Collapse one bag's load movements into distinct machine/segment
     * destinations, each with its summed kg. Segment is null for a load
     * recorded outside any batch window (the machine still shows).
     *
     * @param  \Illuminate\Support\Collection<int, DayBinMovement>  $movements
     * @return array<int, array<string, mixed>>
     */
    private function feedDestinations($movements): array
    {
        $destinations = [];
        foreach ($movements as $movement) {
            $key = $movement->work_center_id.':'.($movement->shift_production_entry_id ?? 0);

            if (! isset($destinations[$key])) {
                $destinations[$key] = [
                    'machine' => [
                        'id' => $movement->workCenter?->id,
                        'code' => $movement->workCenter?->code,
                        'name' => $movement->workCenter?->name,
                    ],
                    'segment' => $movement->shiftProductionEntry !== null ? [
                        'id' => $movement->shiftProductionEntry->id,
                        'batch_number' => $movement->shiftProductionEntry->batch_number,
                    ] : null,
                    'loaded_kg' => '0.0000',
                    'loads' => 0,
                ];
            }

            $destinations[$key]['loaded_kg'] = bcadd($destinations[$key]['loaded_kg'], (string) $movement->quantity_kg, 4);
            $destinations[$key]['loads']++;
        }

        return array_values($destinations);
    }

    /**
     * Completed entries with everything productionMetrics AND
     * consumptionVariance touch eager-loaded (paginate()'s list is the
     * reference) — these are list endpoints over potentially hundreds of
     * entries, and per-row lazy loads would be an N+1 storm.
     *
     * @param  callable(Builder): Builder  $scope
     * @return Collection<int, ShiftProductionEntry>
     */
    private function completedEntries(callable $scope): Collection
    {
        return ShiftProductionEntry::query()
            ->with(['shift', 'workCenter', 'item', 'materialConsumptions', 'scraps'])
            ->where('batch_status', BatchStatus::Completed->value)
            ->tap(fn (Builder $query) => $scope($query))
            ->orderBy('work_center_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * The identity columns every report row shares.
     *
     * @return array<string, mixed>
     */
    private function identity(ShiftProductionEntry $entry): array
    {
        return [
            'entry_id' => $entry->id,
            'batch_number' => $entry->batch_number,
            'production_date' => $entry->production_date?->toDateString(),
            'work_center' => [
                'id' => $entry->workCenter?->id,
                'code' => $entry->workCenter?->code,
                'name' => $entry->workCenter?->name,
            ],
            'shift' => [
                'id' => $entry->shift?->id,
                'name' => $entry->shift?->name,
            ],
            'item' => [
                'id' => $entry->item?->id,
                'sku' => $entry->item?->sku,
                'name' => $entry->item?->name,
            ],
        ];
    }
}
