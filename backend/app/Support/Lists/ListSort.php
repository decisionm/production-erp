<?php

namespace App\Support\Lists;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * One spelling of "sort" for every paginated list (03-Sep-2026).
 *
 * The URL carries a single `sort` value in the server's own spelling: a bare
 * column for ascending, a "-" prefix for descending, absent for the list's
 * default. A FormRequest validates it with rule() so an unknown column
 * is refused with 422; the service applies it with apply(), which
 * falls back to the default rather than trusting its caller — the validation
 * is the gate, the guard is here.
 *
 * Every sort tiebreaks on `id desc`, so a page never reshuffles between two
 * loads of the same list. A nullable date column sorts its empties last in
 * either direction, because an undated row is not "earliest".
 *
 * Copied from SalesDocumentQuery::applySort (the Procurement and Sales lists
 * already sort this way); this is the module-neutral form the other modules
 * adopt.
 */
final class ListSort
{
    public const DEFAULT = '-id';

    /**
     * The validation rule for a `sort` key: id / -id plus each allowed column
     * bare and "-" prefixed.
     *
     * @param  list<string>  $columns
     * @return list<mixed>
     */
    public static function rule(array $columns): array
    {
        return ['sometimes', 'nullable', Rule::in(self::options($columns))];
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    public static function options(array $columns): array
    {
        $options = ['id', '-id'];
        foreach ($columns as $column) {
            if ($column === 'id') {
                continue;
            }
            $options[] = $column;
            $options[] = "-{$column}";
        }

        return $options;
    }

    /**
     * Order the query by the validated `sort`, with the list's default when
     * absent. `$nullableDates` names the columns whose empties sort last.
     *
     * @param  list<string>  $allowed
     * @param  list<string>  $nullableDates
     */
    public static function apply(Builder $query, ?string $sort, array $allowed, string $default = self::DEFAULT, array $nullableDates = []): Builder
    {
        $sort = ($sort === null || trim($sort) === '') ? $default : trim($sort);
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        // The list's own default is trusted as-is; anything else must be an
        // allowed column, or the list falls back to newest first.
        if ($sort !== $default && $column !== 'id' && ! in_array($column, $allowed, true)) {
            $column = 'id';
            $direction = 'desc';
        }

        if (in_array($column, $nullableDates, true)) {
            $wrapped = $query->getQuery()->getGrammar()->wrap($query->qualifyColumn($column));
            $query->orderByRaw("{$wrapped} is null");
        }

        $query->orderBy($query->qualifyColumn($column), $direction);
        if ($column !== 'id') {
            $query->orderByDesc($query->qualifyColumn('id'));
        }

        return $query;
    }
}
