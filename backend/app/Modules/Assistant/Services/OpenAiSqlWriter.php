<?php

namespace App\Modules\Assistant\Services;

use App\Modules\Assistant\Exceptions\AskErpException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Asks an OpenAI chat model for one SELECT as structured JSON — the same
 * prompt, the same schema and the same refusals as AnthropicSqlWriter, over
 * a different wire. Selected with ASK_ERP_DRIVER=openai.
 *
 * WHAT DOES NOT CHANGE BY SWAPPING PROVIDER. The model sees only the specs
 * SchemaRetriever chose for this login, so a table the reader may not view
 * is not in the prompt at all; and whatever comes back is still put through
 * SqlGuard before a row is read. Neither of those protections lives here.
 * This class is a transport.
 *
 * Laravel's HTTP client rather than a vendor SDK, deliberately: the whole
 * surface is one POST, and the deploy is a shared host where every added
 * composer package is another thing that must resolve on PHP 8.4 at deploy
 * time for the factory to keep working.
 *
 * NO RETRY, also deliberately. The three failures actually seen from this
 * endpoint — a bad key, an exhausted credit balance, and a slow model — are
 * respectively permanent, permanent, and made worse by a second attempt: the
 * request budget on shared hosting is one page load, not two model calls.
 */
class OpenAiSqlWriter implements SqlWriter
{
    public function write(SqlRequest $request): SqlDraft
    {
        $apiKey = (string) config('ask-erp.openai.api_key');
        if ($apiKey === '') {
            throw new AskErpException('Ask ERP is not configured on this server.', 503);
        }

        $base = rtrim((string) config('ask-erp.openai.base_url'), '/');

        try {
            $response = Http::withToken($apiKey)
                ->timeout((float) config('ask-erp.timeout'))
                ->acceptJson()
                ->asJson()
                ->post($base.'/chat/completions', self::payload($request));
        } catch (ConnectionException $e) {
            throw self::connectionFailure($e);
        }

        if ($response->failed()) {
            throw self::apiFailure($response->status(), $response->json());
        }

        $body = $response->json();

        // A model-side decline arrives as a 200 with `refusal` set, not as an
        // error status — reading only the status would show the user a blank
        // table and call it success.
        $refusal = data_get($body, 'choices.0.message.refusal');
        if (is_string($refusal) && trim($refusal) !== '') {
            throw new AskErpException('The model declined to write that query.', 422);
        }

        if (data_get($body, 'choices.0.finish_reason') === 'length') {
            throw new AskErpException('The query came back truncated. Ask for less at once.', 422);
        }

        return SqlPrompt::draftFrom(json_decode((string) data_get($body, 'choices.0.message.content'), true));
    }

    /** @return array<string, mixed> */
    public static function payload(SqlRequest $request): array
    {
        return [
            'model' => (string) config('ask-erp.openai.model'),
            // Reasoning depth is the cost and latency lever here, as `effort`
            // is on the Anthropic side. Writing SQL from specs that are
            // already in the prompt is not a hard reasoning task, so the
            // default is deliberately low: a slow answer on shared hosting is
            // a failed answer.
            'reasoning_effort' => (string) config('ask-erp.openai.reasoning_effort'),
            'max_completion_tokens' => (int) config('ask-erp.openai.max_completion_tokens'),
            'messages' => [
                ['role' => 'system', 'content' => SqlPrompt::system()],
                ['role' => 'user', 'content' => SqlPrompt::user($request)],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'sql_draft',
                    'strict' => true,
                    'schema' => SqlPrompt::outputSchema(),
                ],
            ],
        ];
    }

    /**
     * Laravel raises one exception for "could not connect" and "waited too
     * long", so the wait is told apart by its message. Worth the sniff: the
     * two ask the user to do different things.
     */
    private static function connectionFailure(ConnectionException $e): AskErpException
    {
        $timedOut = str_contains(strtolower($e->getMessage()), 'timed out')
            || str_contains(strtolower($e->getMessage()), 'timeout');

        return $timedOut
            ? new AskErpException('The model did not answer in time. Try again.', 504)
            : new AskErpException('The model could not be reached. Try again.', 502);
    }

    /**
     * The provider's own failures, said in words the person who can fix them
     * would recognise. Billing and a bad key are named outright because they
     * are the two an administrator resolves in a minute, and a generic "the
     * model refused the request" sends them hunting through logs instead.
     */
    private static function apiFailure(int $status, mixed $body): AskErpException
    {
        $code = (string) data_get($body, 'error.code');
        $type = (string) data_get($body, 'error.type');

        if ($code === 'credit_balance_exhausted' || $type === 'insufficient_quota') {
            return new AskErpException(
                'Ask ERP has no credit left with the model provider. Top up the account, then try again.',
                503,
            );
        }

        if ($status === 401 || $status === 403) {
            return new AskErpException('Ask ERP is not configured on this server.', 503);
        }

        if ($status === 429) {
            return new AskErpException('Too many questions at once. Wait a moment, then try again.', 429);
        }

        // Everything else, and this is the branch a rejected request body
        // lands in. It KEEPS the provider's own message and the parameter it
        // names: a 400 saying which field was wrong is the difference between
        // a one-line config fix and an afternoon in the logs. The full error
        // is logged too, because the sentence is trimmed for the screen.
        $message = trim((string) data_get($body, 'error.message'));
        $param = trim((string) data_get($body, 'error.param'));

        Log::warning('Ask ERP: the model provider rejected the request.', [
            'status' => $status,
            'type' => $type,
            'code' => $code,
            'param' => $param,
            'message' => $message,
        ]);

        $detail = $code !== '' ? $code : (string) $status;
        if ($param !== '') {
            $detail .= ' on '.$param;
        }

        return new AskErpException(
            'The model refused the request ('.$detail.')'
                .($message !== '' ? ': '.Str::limit($message, 200) : '.'),
            502,
        );
    }
}
