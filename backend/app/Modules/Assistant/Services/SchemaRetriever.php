<?php

namespace App\Modules\Assistant\Services;

use App\Modules\Assistant\Catalogue\SchemaCatalogue;
use App\Modules\Assistant\Catalogue\SensitiveColumns;
use App\Modules\Assistant\Catalogue\TableSpec;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Which tables a question is answered from. Permission first — a table
 * outside the reader's modules does not exist as far as this class is
 * concerned — then a plain lexical ranking over labels, keywords, column
 * names and sample questions. No embeddings: nothing to host, nothing to
 * drift, and a miss costs a rephrase rather than a leak.
 */
class SchemaRetriever
{
    private const array STOP_WORDS = [
        'the', 'a', 'an', 'of', 'in', 'on', 'for', 'per', 'and', 'or', 'to', 'by', 'with', 'how', 'many',
        'much', 'what', 'which', 'this', 'that', 'is', 'are', 'was', 'were', 'me', 'show', 'list', 'give',
        'all', 'each', 'from', 'at', 'as', 'it', 'its', 'do', 'does', 'did', 'only', 'ones', 'one',
        'month', 'week', 'today', 'yesterday', 'year', 'last', 'now', 'top', 'most', 'total', 'count',
    ];

    public function __construct(
        private readonly SchemaCatalogue $catalogue,
        private readonly int $tablesPerQuestion = 8,
    ) {}

    /** @return array<string, TableSpec> keyed by table, alphabetical */
    public function allowedTables(Authenticatable $user): array
    {
        $allowed = [];
        foreach ($this->catalogue->all() as $table => $spec) {
            if ($this->may($user, ["{$spec->module}.view", "{$spec->module}.manage"])) {
                $allowed[$table] = $spec;
            }
        }

        return $allowed;
    }

    /** @return list<string> */
    public function hiddenColumns(Authenticatable $user, TableSpec $spec): array
    {
        $hidden = [];
        foreach ($spec->sensitiveColumns() as $column => $kind) {
            if (! $this->may($user, SensitiveColumns::permissionsFor($kind))) {
                $hidden[] = $column;
            }
        }

        return $hidden;
    }

    /**
     * The ranked tables for a question, joined neighbours pulled in after
     * the cut so a "per vendor" question that scored only purchase_orders
     * still carries vendors along.
     *
     * @param  list<string>  $previousTables
     * @return list<TableSpec>
     */
    public function forQuestion(Authenticatable $user, string $question, array $previousTables = []): array
    {
        $allowed = $this->allowedTables($user);
        $tokens = self::tokens($question);

        $scores = [];
        foreach ($allowed as $table => $spec) {
            $haystack = self::tokens(implode(' ', [
                $spec->label,
                $spec->table,
                implode(' ', $spec->keywords),
                implode(' ', $spec->questions),
            ]));
            $columnTokens = self::tokens(implode(' ', $spec->columnNames()));

            $score = 0;
            foreach ($tokens as $token) {
                $score += 3 * count(array_keys($haystack, $token, true));
                $score += in_array($token, $columnTokens, true) ? 1 : 0;
            }
            if (in_array($table, $previousTables, true)) {
                $score += 5;
            }
            if ($score > 0) {
                $scores[$table] = $score;
            }
        }
        arsort($scores);

        $picked = array_slice(array_keys($scores), 0, $this->tablesPerQuestion);

        foreach ($picked as $table) {
            foreach ($allowed[$table]->joinedTables() as $neighbour) {
                if (isset($allowed[$neighbour]) && ! in_array($neighbour, $picked, true)) {
                    $picked[] = $neighbour;
                }
            }
        }

        return array_map(static fn (string $table) => $allowed[$table], $picked);
    }

    /**
     * Lowercase words, stop-words dropped, crude English stemming — enough
     * for "purchase orders" to meet "Purchase Orders" and "vendors" to
     * meet "vendor".
     *
     * @return list<string>
     */
    public static function tokens(string $text): array
    {
        preg_match_all('/[a-z0-9]+/', strtolower($text), $matches);

        $out = [];
        foreach ($matches[0] as $word) {
            if (strlen($word) < 2 || in_array($word, self::STOP_WORDS, true)) {
                continue;
            }
            $out[] = self::stem($word);
        }

        return $out;
    }

    private static function stem(string $word): string
    {
        $rules = ['ations' => 'at', 'ation' => 'at', 'ings' => '', 'ing' => '', 'ies' => 'i', 'ers' => 'er', 'es' => '', 'ed' => '', 's' => '', 'e' => ''];
        foreach ($rules as $suffix => $replacement) {
            if (strlen($word) > strlen($suffix) + 2 && str_ends_with($word, $suffix)) {
                return substr($word, 0, -strlen($suffix)).$replacement;
            }
        }

        return $word;
    }

    /** @param list<string> $permissions */
    private function may(Authenticatable $user, array $permissions): bool
    {
        return $permissions !== [] && method_exists($user, 'hasAnyPermission') && $user->hasAnyPermission($permissions);
    }
}
