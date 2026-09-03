<?php

namespace App\Modules\Assistant\Services;

/**
 * Whether a result is worth a picture: exactly one label column and one
 * numeric column, two to sixty rows. A date-like label is a line, else a
 * bar. Anything else is a table and nothing more.
 */
final class ChartSuggestion
{
    /**
     * @param  list<string>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @return array{type: string, x: string, y: string}|null
     */
    public static function for(array $columns, array $rows, string $hint): ?array
    {
        if (count($columns) !== 2 || count($rows) < 2 || count($rows) > 60) {
            return null;
        }
        [$a, $b] = $columns;
        $numeric = static fn (string $column) => collect($rows)->every(static fn ($row) => is_numeric($row[$column] ?? null));

        if ($numeric($b) && ! $numeric($a)) {
            [$x, $y] = [$a, $b];
        } elseif ($numeric($a) && ! $numeric($b)) {
            [$x, $y] = [$b, $a];
        } else {
            return null;
        }

        $dateLike = collect($rows)->every(static fn ($row) => preg_match('/^\d{4}-\d{2}(-\d{2})?/', (string) $row[$x]) === 1);
        $type = $hint === 'line' || $dateLike ? 'line' : 'bar';

        return ['type' => $type, 'x' => $x, 'y' => $y];
    }
}
