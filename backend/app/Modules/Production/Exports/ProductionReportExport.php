<?php

namespace App\Modules\Production\Exports;

use App\Modules\Production\Http\Requests\ProductionReportRequest;
use App\Modules\Production\Services\ProductionReportService;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The daily production sheet as a file — GET /production/reports/production,
 * downloaded: the SAME filters (ProductionReportRequest's rules) and the
 * SAME rows in the SAME order (ProductionReportService::productionReport,
 * machine then id), one row per completed entry, then — exactly as the
 * screen pins it under the table when there are rows — the "Day total" row
 * with the report's own totals (plain column sums; the day efficiency is
 * the service's ratio of sums at the piece grain, never recomputed here).
 * The `row` column tells the two apart: `entry` / `day_total`.
 *
 * Every figure is the report's own, keyed as the report keys it — nothing
 * is derived on the way out.
 */
class ProductionReportExport extends AbstractProductionExport
{
    public function __construct(private readonly ProductionReportService $reports) {}

    public function key(): string
    {
        return 'production_report';
    }

    public function label(): string
    {
        return 'Production report (daily sheet)';
    }

    public function filterRules(): array
    {
        return $this->rulesOf(ProductionReportRequest::class);
    }

    public function columns(?Authenticatable $reader): array
    {
        return [
            'row' => 'row',
            'entry_id' => 'entry_id',
            'batch_number' => 'batch_number',
            'production_date' => 'production_date',
            'shift' => 'shift.name',
            'machine' => 'work_center.code',
            'machine_name' => 'work_center.name',
            'item_sku' => 'item.sku',
            'item_name' => 'item.name',
            'running_hours' => 'running_hours',
            'expected_pieces' => 'expected_pieces',
            'expected_boxes' => 'expected_boxes',
            'actual_boxes' => 'actual_boxes',
            'actual_pieces' => 'actual_pieces',
            'good_production_kg' => 'good_production_kg',
            'rejection_kg_production' => 'rejection_kg_production',
            'rejection_kg_qc' => 'rejection_kg_qc',
            'lumps_kg' => 'lumps_kg',
            'efficiency_pct' => 'efficiency_pct',
            'efficiency_band' => 'efficiency_band',
        ];
    }

    public function rows(array $filters, ?Authenticatable $reader): iterable
    {
        $report = $this->report($filters);

        foreach ($report['rows'] as $row) {
            yield ['row' => 'entry'] + $row;
        }

        // The pinned totals row exists on screen only when there are rows.
        if ($report['rows'] !== []) {
            yield ['row' => 'day_total', 'production_date' => $report['date']] + $report['totals'];
        }
    }

    /** The report is computed once here and once for rows(): it is bounded to one date and there is no cheaper count that is the same query. */
    public function count(array $filters, ?Authenticatable $reader): int
    {
        $rows = count($this->report($filters)['rows']);

        return $rows === 0 ? 0 : $rows + 1;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{date: string, rows: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    private function report(array $filters): array
    {
        return $this->reports->productionReport(
            (string) $filters['date'],
            isset($filters['shift_id']) ? (int) $filters['shift_id'] : null,
            isset($filters['work_center_id']) ? (int) $filters['work_center_id'] : null,
        );
    }
}
