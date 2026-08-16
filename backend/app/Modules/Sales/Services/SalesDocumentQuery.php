<?php

namespace App\Modules\Sales\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The filter grammar the three Sales lists share (Phase 3.5): how a typed
 * `q` becomes a document id, how a date range lands on a plain date column
 * versus a factory-day range on a datetime, how `sort` is applied, and the
 * one LIKE escape. Each Sales service applies its own document's filters
 * (customer, status, item, order …) and calls in here for the shared parts,
 * so "SO-12" and "so 12" and "12" mean the same thing on every list and a
 * delivered_date range means the same factory day everywhere.
 *
 * Nothing here decides WHAT is filterable — the List*Request per document
 * does (validation lives in the FormRequest); this class only knows how.
 */
class SalesDocumentQuery
{
    public const PER_PAGE_DEFAULT = 20;

    public const PER_PAGE_MAX = 100;

    public const SORT_DEFAULT = '-id';

    /**
     * The id a typed term names, for a document whose numbers read
     * "{prefix}-{id}": "SO-12", "so 12", "so-12", "SO12" and a bare "12" all
     * name order 12; anything else (a name, a reference, "SO-") is null.
     * Case-insensitive; surrounding whitespace ignored.
     */
    public function documentId(string $term, string $prefix): ?int
    {
        $pattern = '/^\s*(?:'.preg_quote($prefix, '/').')?[\s\-#]*(\d+)\s*$/i';

        if (preg_match($pattern, $term, $matches) !== 1) {
            return null;
        }

        $id = (int) $matches[1];

        return $id > 0 ? $id : null;
    }

    /**
     * Inclusive range on a plain DATE column (order_date, invoice_date):
     * whereDate compiles per driver (date() on MySQL, strftime on SQLite)
     * so a stored 'Y-m-d 00:00:00' compares as the day it is.
     */
    public function applyDateRange(Builder $query, string $column, ?string $from, ?string $to): void
    {
        if ($from !== null && $from !== '') {
            $query->whereDate($column, '>=', $from);
        }
        if ($to !== null && $to !== '') {
            $query->whereDate($column, '<=', $to);
        }
    }

    /**
     * Inclusive FACTORY-DAY range on a DATETIME column (delivered_date): the
     * app clock is UTC and the factory's day is IST, so "delivered on the
     * 11th" means [11th 00:00 IST, 12th 00:00 IST) — a dispatch stamped
     * 20:00 UTC on the 10th belongs to the 11th, exactly as the shift and
     * carton reads already file it (CLAUDE.md: never compare the app clock
     * against a wall-clock string without localising it first).
     */
    public function applyFactoryDayRange(Builder $query, string $column, ?string $from, ?string $to): void
    {
        $timezone = config('tally-sync.factory_timezone');

        if ($from !== null && $from !== '') {
            $query->where($column, '>=', CarbonImmutable::parse($from, $timezone)->startOfDay()->utc());
        }
        if ($to !== null && $to !== '') {
            $query->where($column, '<', CarbonImmutable::parse($to, $timezone)->addDay()->startOfDay()->utc());
        }
    }

    /**
     * `sort` as the requests validate it: a column name, optionally "-"
     * prefixed for descending; null → newest id first. Ties (and every
     * secondary read) break on id descending, so a page never reshuffles
     * between two loads. `expected_date` puts undated rows LAST in either
     * direction — a promise date sort that opened with the orders that
     * have no promise would be a sort nobody asked for.
     *
     * @param  list<string>  $allowed  the bare column names this document sorts on
     */
    public function applySort(Builder $query, ?string $sort, array $allowed): void
    {
        $sort = ($sort === null || $sort === '') ? self::SORT_DEFAULT : $sort;
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        // The request already refused anything else with a 422; this is the
        // belt to that brace, so a service caller can never inject a column.
        if (! in_array($column, $allowed, true) && $column !== 'id') {
            $column = 'id';
            $direction = 'desc';
        }

        if ($column === 'expected_date') {
            $query->orderByRaw($query->getQuery()->getGrammar()->wrap($query->qualifyColumn('expected_date')).' is null');
        }

        $query->orderBy($query->qualifyColumn($column), $direction);

        if ($column !== 'id') {
            $query->orderByDesc($query->qualifyColumn('id'));
        }
    }

    /**
     * Case-insensitive contains-match on one column, the typed `%` and `_`
     * taken as characters ('!' is the escape character), the same way
     * TallySyncQueryService searches its payload.
     */
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

    /** The one customer clause every list's `q` shares: name or code contains the term. */
    public function whereCustomerMatches(Builder $customers, string $term): void
    {
        $customers->where(function (Builder $either) use ($term) {
            $this->whereLike($either, 'name', $term);
            $either->orWhere(fn (Builder $code) => $this->whereLike($code, 'code', $term));
        });
    }

    /** The validated per_page, or the default; the request already bounded it. */
    public function perPage(array $filters): int
    {
        $raw = $filters['per_page'] ?? null;

        return is_numeric($raw) ? max(1, min(self::PER_PAGE_MAX, (int) $raw)) : self::PER_PAGE_DEFAULT;
    }
}
