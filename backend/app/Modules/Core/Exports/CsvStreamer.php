<?php

namespace App\Modules\Core\Exports;

use Closure;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams RFC 4180 CSV: a UTF-8 BOM (so Excel opens it as UTF-8), CRLF
 * line ends, cells quoted only when they contain a comma, a quote or a
 * line break (quotes doubled), and a formula-injection guard that MIRRORS
 * frontend/src/lib/csv.ts EXACTLY — a cell starting with = + - @ tab or CR
 * gets a leading apostrophe unless it is a plain number (so -0.4 stays
 * -0.4 and =SUM(A1) becomes '=SUM(A1)). One write per row from a
 * generator; the whole file is never in memory. The sha256 of the streamed
 * bytes is accumulated as they go and handed to the completion callback
 * with the row count.
 */
final class CsvStreamer
{
    public const BOM = "\xEF\xBB\xBF";

    public const EOL = "\r\n";

    /**
     * @param  array<string, string>  $columns  CSV header → row key (dot paths allowed)
     * @param  iterable<int, array<string, mixed>>  $rows
     * @param  (Closure(int $rowCount, string $sha256): void)|null  $onComplete  called once, after the last byte
     */
    public function stream(string $fileName, array $columns, iterable $rows, ?Closure $onComplete = null): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $fileName),
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];

        return new StreamedResponse(function () use ($columns, $rows, $onComplete) {
            $out = fopen('php://output', 'w');
            $hash = hash_init('sha256');
            $count = 0;

            $write = function (string $bytes) use ($out, $hash) {
                fwrite($out, $bytes);
                hash_update($hash, $bytes);
            };

            $write(self::BOM);
            $write(self::csvLine(array_keys($columns)));

            foreach ($rows as $row) {
                $cells = [];
                foreach ($columns as $key) {
                    $cells[] = data_get($row, $key);
                }
                $write(self::csvLine($cells));
                $count++;
            }

            fclose($out);

            if ($onComplete !== null) {
                $onComplete($count, hash_final($hash));
            }
        }, 200, $headers);
    }

    /**
     * One CSV line, terminated with CRLF — pure, so a test can pin the bytes.
     *
     * @param  list<mixed>  $cells
     */
    public static function csvLine(array $cells): string
    {
        return implode(',', array_map(self::escapeCell(...), $cells)).self::EOL;
    }

    /**
     * the retired frontend/src/lib/csv.ts escapeCell (ported byte for byte; the JS is gone, CsvStreamerTest is the authority): null → empty; the
     * formula guard; then RFC 4180 quoting. \z (not $) so a trailing
     * newline cannot make a non-number pass as a number, which JS's $
     * without the m flag never allows.
     */
    public static function escapeCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $s = self::stringify($value);

        if (preg_match('/^[=+\-@\t\r]/', $s) === 1 && preg_match('/^-?\d+(\.\d+)?\z/', $s) !== 1) {
            $s = "'".$s;
        }

        return preg_match('/[",\r\n]/', $s) === 1
            ? '"'.str_replace('"', '""', $s).'"'
            : $s;
    }

    /** As String(value) would say it in JS: true/false, numbers as printed; a nested value as JSON. */
    private static function stringify(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            $value instanceof \Stringable => (string) $value,
            $value instanceof \BackedEnum => (string) $value->value,
            $value instanceof \UnitEnum => $value->name,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }
}
