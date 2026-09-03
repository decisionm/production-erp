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

        return SqlPrompt::draftFrom($json);
    }

    /*
     * The prompt and the schema moved to SqlPrompt when a second provider
     * arrived — they describe the feature, not this vendor. These three stay
     * as the names the rest of the code and its tests already call.
     */

    public static function systemPrompt(): string
    {
        return SqlPrompt::system();
    }

    public static function userPrompt(SqlRequest $request): string
    {
        return SqlPrompt::user($request);
    }

    /** @return array<string, mixed> */
    public static function outputSchema(): array
    {
        return SqlPrompt::outputSchema();
    }
}
