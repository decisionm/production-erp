<?php

namespace App\Modules\Payroll\Services;

use Illuminate\Database\Eloquent\Builder;

/**
 * The list grammar the two Payroll lists share (runs, payslips): the one
 * LIKE escape and the page-size clamp. The same shape as Procurement's
 * ProcurementDocumentQuery, kept inside this module rather than reached
 * across to Sales for — a payroll list has no business depending on a
 * sales service for three lines of SQL.
 *
 * Nothing here decides WHAT is filterable — ListPayrollRunsRequest and
 * ListPayslipsRequest do (validation lives in the FormRequest); this class
 * only knows how.
 */
class PayrollListQuery
{
    public const PER_PAGE_DEFAULT = 20;

    public const PER_PAGE_MAX = 100;

    /** Case-insensitive contains-match; the typed `%` and `_` are characters ('!' is the escape). */
    public function whereLike(Builder $query, string $column, string $term): void
    {
        $grammar = $query->getQuery()->getGrammar();

        $query->whereRaw(
            'lower('.$grammar->wrap($query->qualifyColumn($column)).") like ? escape '!'",
            [$this->needle($term)],
        );
    }

    /** `%term%`, lower-cased and escaped for whereLike(). */
    public function needle(string $term): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($term))).'%';
    }

    /** The validated per_page, or the default; the request already bounded it. */
    public function perPage(array $filters): int
    {
        $raw = $filters['per_page'] ?? null;

        return is_numeric($raw) ? max(1, min(self::PER_PAGE_MAX, (int) $raw)) : self::PER_PAGE_DEFAULT;
    }
}
