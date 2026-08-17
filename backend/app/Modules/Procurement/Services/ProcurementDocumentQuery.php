<?php

namespace App\Modules\Procurement\Services;

use App\Modules\Sales\Services\SalesDocumentQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * The filter grammar the two Procurement lists share (Phase 4.5): how a
 * typed `q` becomes a document id ("PO-12", "po 12", "12"; "GRN-7"), how a
 * date range lands on a plain date column (order_date) versus a factory-day
 * range on a datetime (received_date), how `sort` is applied, the one LIKE
 * escape, and the one vendor clause every list's `q` shares.
 *
 * It is DELIBERATELY the Sales grammar and not a second one: every "how"
 * delegates to SalesDocumentQuery (a stateless Sales service — the
 * cross-module hop is here, once, through a Service class as CLAUDE.md
 * requires, never a Sales model), so "SO-12" on a sales list and "PO-12"
 * on a purchase list are read by the same regex, and a `from`/`to` means
 * the same factory day on a receipt as on a delivery. What this class adds
 * is only what Procurement has and Sales does not: the vendor.
 *
 * Nothing here decides WHAT is filterable — ListPurchaseOrdersRequest /
 * ListGoodsReceiptsRequest do (validation lives in the FormRequest); this
 * class only knows how.
 */
class ProcurementDocumentQuery
{
    public const PER_PAGE_DEFAULT = 20;

    /**
     * 1000, not Sales' 100: the PO/GRN lists have served up to 1000 since
     * the `?po=` / `?grn=` deep links needed to find one older document
     * (Controller::perPage) — the frontend relies on it.
     */
    public const PER_PAGE_MAX = 1000;

    public function __construct(private readonly SalesDocumentQuery $grammar) {}

    /** The id a typed term names for a "{prefix}-{id}" document — SalesDocumentQuery::documentId, verbatim. */
    public function documentId(string $term, string $prefix): ?int
    {
        return $this->grammar->documentId($term, $prefix);
    }

    /** Inclusive range on a plain DATE column (order_date). */
    public function applyDateRange(Builder $query, string $column, ?string $from, ?string $to): void
    {
        $this->grammar->applyDateRange($query, $column, $from, $to);
    }

    /** Inclusive FACTORY-DAY range on a DATETIME column (received_date). */
    public function applyFactoryDayRange(Builder $query, string $column, ?string $from, ?string $to): void
    {
        $this->grammar->applyFactoryDayRange($query, $column, $from, $to);
    }

    /**
     * `sort` as the requests validate it; null → newest id first; ties break
     * on id descending; expected_date puts undated rows last either way.
     *
     * @param  list<string>  $allowed
     */
    public function applySort(Builder $query, ?string $sort, array $allowed): void
    {
        $this->grammar->applySort($query, $sort, $allowed);
    }

    /** Case-insensitive contains-match, the typed `%` and `_` taken as characters. */
    public function whereLike(Builder $query, string $column, string $term): void
    {
        $this->grammar->whereLike($query, $column, $term);
    }

    /** The one vendor clause every list's `q` shares: name or code contains the term. */
    public function whereVendorMatches(Builder $vendors, string $term): void
    {
        $vendors->where(function (Builder $either) use ($term) {
            $this->grammar->whereLike($either, 'name', $term);
            $either->orWhere(fn (Builder $code) => $this->grammar->whereLike($code, 'code', $term));
        });
    }

    /** The validated per_page, or the default; the request already bounded it. */
    public function perPage(array $filters): int
    {
        $raw = $filters['per_page'] ?? null;

        return is_numeric($raw) ? max(1, min(self::PER_PAGE_MAX, (int) $raw)) : self::PER_PAGE_DEFAULT;
    }
}
