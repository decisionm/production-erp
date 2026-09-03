<?php

namespace App\Modules\Assistant\Services;

use App\Modules\Assistant\Exceptions\AskErpException;

/**
 * The one prompt Ask ERP puts in front of a model, and the one output shape
 * it accepts back. It lives apart from any provider because the rules below
 * are the FEATURE's rules, not a vendor's: a question answered by Claude and
 * the same question answered by another model must be constrained
 * identically, or the guard downstream is enforcing one contract while the
 * prompt describes another.
 *
 * Nothing here is a security boundary. SqlGuard is. A model told "SELECT
 * only" may still return anything at all; these rules exist to make the
 * usual case correct and cheap, not to make the bad case safe.
 */
final class SqlPrompt
{
    public static function system(): string
    {
        return <<<'TXT'
You write one SQL query that answers a question about a manufacturing ERP's MySQL database.

Rules:
- Output a single SELECT (a WITH ... SELECT is fine). Never write, alter, or lock anything.
- Use only the tables and columns listed in the request. Do not invent columns. If the question cannot be answered from them, return an empty sql string.
- Alias every table. Prefer aggregates and GROUP BY over raw row dumps. Add ORDER BY.
- Money and quantities are DECIMAL; use ROUND(..., 2) on sums.
- Dates: use CURDATE(), DATE_SUB, YEAR(), MONTH(); the factory is in India.
- Soft-deleted rows have deleted_at IS NOT NULL — exclude them unless asked.
- Keep results under 200 rows; add LIMIT.
- answer_template is one plain sentence using {{count}} for the number of rows, {{first.<column>}} for a value from the first row, and {{sum.<column>}} for a column total. Use the output column aliases.
- chart_hint is "bar" for one label plus one number per row, "line" when the label is a date or month, else "none".
TXT;
    }

    public static function user(SqlRequest $request): string
    {
        $parts = ['Today is '.$request->today.'.', '', 'Tables available:', implode("\n\n", $request->tableSpecs)];

        if ($request->history !== []) {
            $parts[] = '';
            $parts[] = 'Earlier in this conversation:';
            foreach ($request->history as $turn) {
                $parts[] = 'Q: '.$turn['question'];
                if (! empty($turn['sql'])) {
                    $parts[] = 'SQL: '.$turn['sql'];
                }
                if (! empty($turn['answer'])) {
                    $parts[] = 'A: '.$turn['answer'];
                }
            }
        }

        $parts[] = '';
        $parts[] = 'Question: '.$request->question;

        return implode("\n", $parts);
    }

    /**
     * Strict-mode JSON schema. Every property is required and
     * additionalProperties is false, which BOTH providers demand before they
     * will guarantee the shape rather than merely aim for it.
     *
     * @return array<string, mixed>
     */
    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sql' => ['type' => 'string'],
                'answer_template' => ['type' => 'string'],
                'chart_hint' => ['type' => 'string', 'enum' => ['bar', 'line', 'none']],
            ],
            'required' => ['sql', 'answer_template', 'chart_hint'],
            'additionalProperties' => false,
        ];
    }

    /**
     * The decoded model output turned into a draft. Shared so that a
     * malformed answer is refused with the same sentence whichever model
     * produced it — an empty sql string is the model's own way of saying the
     * question cannot be answered from the tables it was shown.
     */
    public static function draftFrom(mixed $json): SqlDraft
    {
        if (! is_array($json) || trim((string) ($json['sql'] ?? '')) === '') {
            throw new AskErpException(
                'Could not turn that into a query. Try naming the table or the field.',
                422,
            );
        }

        $hint = $json['chart_hint'] ?? 'none';

        return new SqlDraft(
            sql: trim((string) $json['sql']),
            answerTemplate: (string) ($json['answer_template'] ?? ''),
            chartHint: in_array($hint, ['bar', 'line', 'none'], true) ? $hint : 'none',
        );
    }
}
