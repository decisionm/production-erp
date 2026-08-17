<?php

namespace App\Modules\Production\Exports;

use App\Modules\Production\Http\Requests\ReconciliationReportRequest;
use App\Modules\Production\Services\ProductionReportService;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Material reconciliation as a file — GET /production/reports/reconciliation,
 * downloaded: the SAME filters (ReconciliationReportRequest's rules, the
 * 92-day cap included) and the SAME rows in the SAME order
 * (ProductionReportService::reconciliationReport — worst unaccounted
 * material first, entries that cannot reconcile last), one row per
 * completed entry. Every figure is the report's own.
 */
class ReconciliationReportExport extends AbstractProductionExport
{
    public function __construct(private readonly ProductionReportService $reports) {}

    public function key(): string
    {
        return 'reconciliation_report';
    }

    public function label(): string
    {
        return 'Material reconciliation report';
    }

    public function filterRules(): array
    {
        return $this->rulesOf(ReconciliationReportRequest::class);
    }

    public function columns(?Authenticatable $reader): array
    {
        return [
            'entry_id' => 'entry_id',
            'batch_number' => 'batch_number',
            'production_date' => 'production_date',
            'shift' => 'shift.name',
            'machine' => 'work_center.code',
            'machine_name' => 'work_center.name',
            'item_sku' => 'item.sku',
            'item_name' => 'item.name',
            'issued_kg' => 'issued_kg',
            'good_production_kg' => 'good_production_kg',
            'confirmed_rejection_kg' => 'confirmed_rejection_kg',
            'lumps_kg' => 'lumps_kg',
            'reconciliation_unaccounted_kg' => 'reconciliation_unaccounted_kg',
            'unaccounted_band' => 'unaccounted_band',
            'variance_pct' => 'variance_pct',
            'variance_band' => 'variance_band',
        ];
    }

    public function rows(array $filters, ?Authenticatable $reader): iterable
    {
        yield from $this->report($filters)['rows'];
    }

    /** Computed once here and once for rows(): the range is capped at 92 days and there is no cheaper count that is the same query. */
    public function count(array $filters, ?Authenticatable $reader): int
    {
        return count($this->report($filters)['rows']);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{date_from: string, date_to: string, rows: array<int, array<string, mixed>>}
     */
    private function report(array $filters): array
    {
        return $this->reports->reconciliationReport(
            (string) $filters['date_from'],
            (string) $filters['date_to'],
            isset($filters['shift_id']) ? (int) $filters['shift_id'] : null,
        );
    }
}
