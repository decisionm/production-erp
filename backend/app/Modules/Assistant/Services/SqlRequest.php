<?php

namespace App\Modules\Assistant\Services;

/** Everything the SQL writer is told: the question, the specs, the history. */
final class SqlRequest
{
    /**
     * @param  list<string>  $tableSpecs  rendered TableSpec texts, hidden columns already removed
     * @param  list<array{question: string, sql: ?string, answer: ?string}>  $history  oldest first
     */
    public function __construct(
        public readonly string $question,
        public readonly array $tableSpecs,
        public readonly array $history,
        public readonly string $today,
    ) {}
}
