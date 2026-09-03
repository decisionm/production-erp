<?php

namespace App\Modules\Assistant\Services;

/**
 * Whether a result is worth a picture, and of what.
 *
 * IT USED TO DEMAND EXACTLY TWO COLUMNS, and so drew almost nothing. Real
 * answers carry more: "today productivity?" comes back as work centre,
 * produced AND scrap; "which machine made the most" as code, name and pieces;
 * the rule book's own output-by-machine as machine, batches and pieces. Every
 * one of those is a perfectly good bar chart and every one was refused for
 * having a third column.
 *
 * So it now looks for a LABEL and a MEASURE among however many columns there
 * are, rather than insisting the query be shaped for it.
 *
 * WHICH measure, when there are several, is the only real judgement here. The
 * largest total wins: asked for output by machine you mean the pieces, not the
 * batch count; asked for rejection you mean the kilograms, not the number of
 * batches. Identifier columns are excluded outright — an id is numeric and
 * often large, and a chart of primary keys is noise.
 */
final class ChartSuggestion
{
    /** Below this a picture says less than the two rows do; above it, bars stop being readable. */
    private const MIN_ROWS = 2;

    private const MAX_ROWS = 60;

    /**
     * @param  list<string>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @return array{type: string, x: string, y: string}|null
     */
    public static function for(array $columns, array $rows, string $hint): ?array
    {
        if (count($columns) < 2 || count($rows) < self::MIN_ROWS || count($rows) > self::MAX_ROWS) {
            return null;
        }

        $labels = [];
        $measures = [];

        foreach ($columns as $column) {
            if (self::isNumeric($column, $rows)) {
                if (! self::isIdentifier($column)) {
                    $measures[$column] = self::magnitude($column, $rows);
                }
            } else {
                $labels[] = $column;
            }
        }

        if ($labels === [] || $measures === []) {
            return null;
        }

        // The first label reads as the subject — "machine", not "machine
        // name" — and the biggest measure is what the question was about.
        $x = $labels[0];
        arsort($measures);
        $y = (string) array_key_first($measures);

        $dateLike = self::isDateLike($x, $rows);

        return ['type' => $hint === 'line' || $dateLike ? 'line' : 'bar', 'x' => $x, 'y' => $y];
    }

    /** @param list<array<string, mixed>> $rows */
    private static function isNumeric(string $column, array $rows): bool
    {
        foreach ($rows as $row) {
            if (! is_numeric($row[$column] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array<string, mixed>> $rows */
    private static function magnitude(string $column, array $rows): float
    {
        $total = 0.0;
        foreach ($rows as $row) {
            $total += abs((float) ($row[$column] ?? 0));
        }

        return $total;
    }

    /** A key is numeric and often large; charting one is noise, not information. */
    private static function isIdentifier(string $column): bool
    {
        $lower = strtolower($column);

        return $lower === 'id' || str_ends_with($lower, '_id') || str_ends_with($lower, ' id');
    }

    /** @param list<array<string, mixed>> $rows */
    private static function isDateLike(string $column, array $rows): bool
    {
        foreach ($rows as $row) {
            if (preg_match('/^\d{4}-\d{2}(-\d{2})?/', (string) ($row[$column] ?? '')) !== 1) {
                return false;
            }
        }

        return true;
    }
}
