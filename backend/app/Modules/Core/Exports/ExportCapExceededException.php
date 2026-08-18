<?php

namespace App\Modules\Core\Exports;

use App\Exceptions\DomainException;
use RuntimeException;

/**
 * More rows match than the kind's cap allows. Exports are synchronous
 * streamed CSV (no worker on the host — config/exports.php), so over the
 * cap the server REFUSES, with a sentence naming the count and the cap,
 * and never truncates: a file that silently stopped at row 5,000 would be
 * read as the whole answer. Renders as a 422 (DomainException) carrying
 * `matched` and `cap` beside the sentence.
 */
class ExportCapExceededException extends RuntimeException implements DomainException
{
    public function __construct(public readonly ExportKind $kind, public readonly int $matched, public readonly int $cap)
    {
        parent::__construct(self::sentence($matched, $cap));
    }

    /** "5,213 rows match; the cap is 5,000 — narrow the range" */
    public static function sentence(int $matched, int $cap): string
    {
        return sprintf('%s rows match; the cap is %s — narrow the range', number_format($matched), number_format($cap));
    }

    public function errorCode(): string
    {
        return 'export_cap_exceeded';
    }

    /** @return array{matched: int, cap: int} */
    public function payload(): array
    {
        return ['matched' => $this->matched, 'cap' => $this->cap];
    }
}
