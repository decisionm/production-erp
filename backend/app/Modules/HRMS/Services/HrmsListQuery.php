<?php

namespace App\Modules\HRMS\Services;

use App\Modules\Sales\Services\SalesDocumentQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * The filter grammar the three HRMS lists share — employees, leave
 * requests, attendance. What "matching an employee" means is decided ONCE
 * here, so typing "Stores" on the attendance page finds the same people the
 * employee page finds for it, and the leave list cannot drift from either.
 *
 * It is DELIBERATELY the Sales grammar underneath and not a third LIKE
 * escape (Procurement made the same call, ProcurementDocumentQuery): the
 * one thing every list must agree on is that a typed `%` or `_` is a
 * character, and there is exactly one place that knows how.
 *
 * Nothing here decides WHAT is filterable — the List*Request per endpoint
 * does; this class only knows how.
 */
class HrmsListQuery
{
    public const PER_PAGE_DEFAULT = 20;

    /**
     * The list pages ask for 1..100. The EMPLOYEE index alone also serves
     * every employee picker in the app (`listAllEmployees`, per_page=1000 —
     * leave, attendance, payroll, maintenance, quality, shift entry), so its
     * ceiling stays where that contract already is.
     */
    public const PER_PAGE_MAX = 100;

    public const PER_PAGE_MAX_EMPLOYEES = 1000;

    public function __construct(private readonly SalesDocumentQuery $grammar) {}

    /**
     * The one employee clause every list's `q` shares: code, name,
     * department or designation contains the term, case-insensitively.
     * Applied to an `employees` builder — the list's own, or the relation's
     * through whereHas.
     */
    public function whereEmployeeMatches(Builder $employees, string $term): void
    {
        $employees->where(function (Builder $any) use ($term) {
            $this->grammar->whereLike($any, 'employee_code', $term);
            $any->orWhere(fn (Builder $name) => $this->grammar->whereLike($name, 'name', $term));
            $any->orWhere(fn (Builder $department) => $this->grammar->whereLike($department, 'department', $term));
            $any->orWhere(fn (Builder $designation) => $this->grammar->whereLike($designation, 'designation', $term));
        });
    }

    /**
     * The attendance-import review list's `q`: the employee code or name AS
     * THE PUNCH REPORT PRINTED THEM, on the line itself — an unknown
     * employee has no master row to search through, and the whole point of
     * that filter is finding them.
     */
    public function whereImportLineMatches(Builder $lines, string $term): void
    {
        $lines->where(function (Builder $any) use ($term) {
            $this->grammar->whereLike($any, 'employee_code', $term);
            $any->orWhere(fn (Builder $name) => $this->grammar->whereLike($name, 'employee_name', $term));
        });
    }

    /** Inclusive range on a plain DATE column. */
    public function applyDateRange(Builder $query, string $column, ?string $from, ?string $to): void
    {
        $this->grammar->applyDateRange($query, $column, $from, $to);
    }

    /** The trimmed `q`, or null when the box was empty — an empty box narrows nothing. */
    public function term(array $filters): ?string
    {
        $term = trim((string) ($filters['q'] ?? ''));

        return $term === '' ? null : $term;
    }

    /** The validated per_page, or the default; the request already bounded it. */
    public function perPage(array $filters, int $max = self::PER_PAGE_MAX): int
    {
        $raw = $filters['per_page'] ?? null;

        return is_numeric($raw) ? max(1, min($max, (int) $raw)) : self::PER_PAGE_DEFAULT;
    }
}
