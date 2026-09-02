<?php

namespace App\Modules\Assistant\Services;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use App\Modules\Assistant\Exceptions\AskErpException;

/**
 * Asks Claude for one SELECT as structured JSON. The model sees only the
 * rendered specs SchemaRetriever chose — hidden columns are not in them —
 * and the SQL it returns is still checked by SqlGuard before anything runs.
 * Thinking is the model's adaptive default; `effort` is the cost lever.
 */
class AnthropicSqlWriter implements SqlWriter
{
    public function write(SqlRequest $request): SqlDraft
    {
        $apiKey = (string) config('ask-erp.api_key');
        if ($apiKey === '') {
            throw new AskErpException('Ask ERP is not configured on this server.', 503);
        }

        $client = new Client(apiKey: $apiKey);

        try {
            $message = $client->messages->create(
                maxTokens: (int) config('ask-erp.max_tokens'),
                messages: [['role' => 'user', 'content' => self::userPrompt($request)]],
                model: (string) config('ask-erp.model'),
                outputConfig: [
                    'effort' => (string) config('ask-erp.effort'),
                    'format' => ['type' => 'json_schema', 'schema' => self::outputSchema()],
                ],
                system: [['type' => 'text', 'text' => self::systemPrompt(), 'cacheControl' => ['type' => 'ephemeral']]],
                requestOptions: ['timeout' => (float) config('ask-erp.timeout'), 'maxRetries' => 1],
            );
        } catch (APITimeoutException) {
            throw new AskErpException('The model did not answer in time. Try again.', 504);
        } catch (APIConnectionException) {
            throw new AskErpException('The model could not be reached. Try again.', 502);
        } catch (APIStatusException $e) {
            throw new AskErpException('The model refused the request ('.($e->type?->value ?? 'error').').', 502);
        }

        if ($message->stopReason === 'refusal') {
            throw new AskErpException('The model declined to write that query.', 422);
        }

        $json = null;
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $json = json_decode($block->text, true);
                break;
            }
        }
        if (! is_array($json) || trim((string) ($json['sql'] ?? '')) === '') {
            throw new AskErpException('Could not turn that into a query. Try naming the table or the field.', 422);
        }

        $hint = $json['chart_hint'] ?? 'none';

        return new SqlDraft(
            sql: trim((string) $json['sql']),
            answerTemplate: (string) ($json['answer_template'] ?? ''),
            chartHint: in_array($hint, ['bar', 'line', 'none'], true) ? $hint : 'none',
        );
    }

    public static function systemPrompt(): string
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

    public static function userPrompt(SqlRequest $request): string
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

    /** @return array<string, mixed> */
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
}
