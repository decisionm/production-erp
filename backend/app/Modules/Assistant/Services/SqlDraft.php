<?php

namespace App\Modules\Assistant\Services;

/** What the SQL writer returned. Unchecked: SqlGuard decides whether it runs. */
final class SqlDraft
{
    public function __construct(
        public readonly string $sql,
        public readonly string $answerTemplate,
        public readonly string $chartHint = 'none',
    ) {}
}
