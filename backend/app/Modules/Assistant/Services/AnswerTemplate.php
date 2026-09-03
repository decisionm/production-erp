<?php

namespace App\Modules\Assistant\Services;

/**
 * The one-sentence answer, filled from the rows the query actually
 * returned — the model never sees the data, so it cannot make a number up.
 */
final class AnswerTemplate
{
    /**
     * @param  list<string>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    public static function render(string $template, array $columns, array $rows): string
    {
        if ($rows === []) {
            return 'No rows matched.';
        }
        if (trim($template) === '') {
            return count($rows).' rows.';
        }

        return (string) preg_replace_callback(
            '/\{\{\s*(count|first\.([a-z0-9_]+)|sum\.([a-z0-9_]+))\s*\}\}/i',
            static function (array $match) use ($rows): string {
                if ($match[1] === 'count') {
                    return (string) count($rows);
                }
                if (! empty($match[2])) {
                    return self::format($rows[0][$match[2]] ?? '');
                }
                $sum = array_sum(array_map(static fn ($row) => (float) ($row[$match[3]] ?? 0), $rows));

                return self::format($sum);
            },
            $template,
        );
    }

    private static function format(mixed $value): string
    {
        if (is_numeric($value)) {
            $float = (float) $value;

            return floor($float) == $float ? number_format($float, 0, '.', '') : number_format($float, 2, '.', '');
        }

        return (string) $value;
    }
}
