<?php

namespace App\Modules\Production\Exports;

use App\Modules\Core\Exports\AbstractExportKind;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What every Production kind shares: the module's permission group (a GET
 * report under module:production needs production.view or .manage — the
 * file needs exactly the same), and how a report FormRequest's rules are
 * borrowed.
 *
 * FC-06 ON THESE FILES: none of the production reports carries a purchase
 * rate, a material cost or a supplier — quantities, weights, hours,
 * pieces, boxes, bands and percentages only (ProductionReportService,
 * ShiftSummaryService — read before adding a column). There is nothing to
 * gate, so columns() is the same for every reader; a report that ever
 * grows a rate must gate the column the way its resource does, never here.
 */
abstract class AbstractProductionExport extends AbstractExportKind
{
    public function module(): string
    {
        return 'production';
    }

    public function permissionAny(): array
    {
        return ['production.view', 'production.manage'];
    }

    /**
     * The report endpoint's own rules, off an instance built FROM the
     * current request — not `new`: ReportRangeRequest's max-range rule is a
     * closure that reads `$this->input('date_from')` off its own request,
     * so a bare instance would silently drop the 92-day cap and the file
     * would accept a range the screen refuses. Off the current request the
     * closure reads the export's body exactly as the report reads its query
     * string. (For the catalogue there is no body; the closure then simply
     * has nothing to measure, as on the report itself.)
     *
     * @template T of FormRequest
     *
     * @param  class-string<T>  $class
     * @return array<string, mixed>
     */
    protected function rulesOf(string $class): array
    {
        return $class::createFrom(app('request'))->rules();
    }
}
