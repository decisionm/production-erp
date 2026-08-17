<?php

namespace App\Modules\Production\Exports;

use App\Modules\Core\Exports\ExportBlockedException;
use App\Modules\Production\Http\Requests\TraceabilityReportRequest;
use App\Modules\Production\Services\ProductionReportService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;

/**
 * Lot → bag → machine/segment as a file — GET /production/reports/traceability,
 * downloaded: the SAME filters (TraceabilityReportRequest's rules) and the
 * SAME report (ProductionReportService::traceabilityReport), flattened the
 * way the screen's drill-down reads: one row per deepest visible level —
 * a lot with no bags is one row, a bag never loaded is one row, a bag
 * loaded to N machine/segment destinations is N rows — lots in the
 * report's order, bags and destinations in theirs. Each row is the
 * report's own lot / bag / fed arrays side by side; the columns dot into
 * them and every figure is the report's.
 *
 * DELIBERATE DIVERGENCE FROM THE ROUTE: with the flag off the report's own
 * route answers 404 (EnsureTraceabilityEnabled), while this kind is
 * catalogued BLOCKED and answers 409 with the reason — the Center has no
 * "hidden" state and a stated reason beats a route that pretends not to
 * exist. Do not "fix" one to match the other.
 *
 * The report exists only with production.traceability_enabled on (its
 * route answers 404 otherwise — EnsureTraceabilityEnabled); with the flag
 * off this kind is BLOCKED with the reason, never a file for a screen that
 * does not exist.
 */
class TraceabilityReportExport extends AbstractProductionExport
{
    public const DISABLED_REASON = 'Traceability is not enabled on this deployment (PROD_TRACEABILITY) — the traceability report does not exist here.';

    public function __construct(private readonly ProductionReportService $reports) {}

    public function key(): string
    {
        return 'traceability_report';
    }

    public function label(): string
    {
        return 'Traceability report (lot → bag → machine)';
    }

    public function filterRules(): array
    {
        return $this->rulesOf(TraceabilityReportRequest::class);
    }

    public function status(): string
    {
        return $this->enabled() ? self::STATUS_AVAILABLE : self::STATUS_BLOCKED;
    }

    public function blockedReason(): ?string
    {
        return $this->enabled() ? null : self::DISABLED_REASON;
    }

    public function columns(?Authenticatable $reader): array
    {
        return [
            'lot_id' => 'lot.id',
            'supplier_lot_no' => 'lot.supplier_lot_no',
            'item_sku' => 'lot.item.sku',
            'item_name' => 'lot.item.name',
            'received_date' => 'lot.received_date',
            'bag_count' => 'lot.bag_count',
            'total_received_kg' => 'lot.total_received_kg',
            'bag_id' => 'bag.id',
            'bag_barcode' => 'bag.barcode',
            'bag_status' => 'bag.status',
            'bag_original_kg' => 'bag.original_kg',
            'bag_remaining_kg' => 'bag.remaining_kg',
            'machine' => 'fed.machine.code',
            'machine_name' => 'fed.machine.name',
            'batch_number' => 'fed.segment.batch_number',
            'loaded_kg' => 'fed.loaded_kg',
            'loads' => 'fed.loads',
        ];
    }

    public function rows(array $filters, ?Authenticatable $reader): iterable
    {
        if (! $this->enabled()) {
            throw new ExportBlockedException($this, self::DISABLED_REASON);
        }

        yield from $this->flatten($this->report($filters)['lots']);
    }

    /** Computed once here and once for rows(): the range is capped at 92 days and the flattened count is only known from the report. */
    public function count(array $filters, ?Authenticatable $reader): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $count = 0;
        foreach ($this->flatten($this->report($filters)['lots']) as $ignored) {
            $count++;
        }

        return $count;
    }

    private function enabled(): bool
    {
        return (bool) config('production.traceability_enabled');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{date_from: string, date_to: string, lots: array<int, array<string, mixed>>}
     */
    /** @var array{key: string, report: array<string, mixed>}|null the last report, so count() and rows() in one run compute it once */
    private ?array $memo = null;

    private function report(array $filters): array
    {
        $key = json_encode($filters);
        if ($this->memo !== null && $this->memo['key'] === $key) {
            return $this->memo['report'];
        }

        $report = $this->reports->traceabilityReport(
            isset($filters['lot_id']) ? (int) $filters['lot_id'] : null,
            isset($filters['item_id']) ? (int) $filters['item_id'] : null,
            (string) $filters['date_from'],
            (string) $filters['date_to'],
        );
        $this->memo = ['key' => $key, 'report' => $report];

        return $report;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lots  the report's lots, each with bags[], each bag with fed[]
     * @return iterable<int, array{lot: array<string, mixed>, bag: ?array<string, mixed>, fed: ?array<string, mixed>}>
     */
    private function flatten(array $lots): iterable
    {
        foreach ($lots as $lot) {
            $bags = $lot['bags'] ?? [];
            $lotOnly = Arr::except($lot, ['bags']);

            if ($bags === []) {
                yield ['lot' => $lotOnly, 'bag' => null, 'fed' => null];

                continue;
            }

            foreach ($bags as $bag) {
                $fed = $bag['fed'] ?? [];
                $bagOnly = Arr::except($bag, ['fed']);

                if ($fed === []) {
                    yield ['lot' => $lotOnly, 'bag' => $bagOnly, 'fed' => null];

                    continue;
                }

                foreach ($fed as $destination) {
                    yield ['lot' => $lotOnly, 'bag' => $bagOnly, 'fed' => $destination];
                }
            }
        }
    }
}
