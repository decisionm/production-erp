<?php

namespace Tests\Feature\Assistant;

use App\Modules\Assistant\Exceptions\AskErpException;
use App\Modules\Assistant\Services\AnthropicSqlWriter;
use App\Modules\Assistant\Services\OpenAiSqlWriter;
use App\Modules\Assistant\Services\SqlRequest;
use App\Modules\Assistant\Services\SqlWriter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The OpenAI transport. Every test here fakes the wire — none of them spends
 * a credit, and none of them needs a key to be real.
 *
 * What is being pinned is not "does OpenAI work" but "does a failure reach
 * the user as a sentence they can act on". The provider's three real-world
 * failures (no key, no credit, too slow) each have their own assertion,
 * because each asks a different person to do a different thing.
 */
class OpenAiSqlWriterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ask-erp.driver' => 'openai',
            'ask-erp.timeout' => 45,
            'ask-erp.openai.api_key' => 'sk-test-not-a-real-key',
            'ask-erp.openai.model' => 'gpt-5.2',
            'ask-erp.openai.reasoning_effort' => 'low',
            'ask-erp.openai.max_completion_tokens' => 8000,
            'ask-erp.openai.base_url' => 'https://api.openai.com/v1',
        ]);
    }

    private function request(): SqlRequest
    {
        return new SqlRequest(
            question: 'how many open purchase orders per vendor',
            tableSpecs: ["purchase_orders (procurement): A PO.\n  - id integer — pk"],
            history: [],
            today: '2026-09-03',
        );
    }

    /** @param  array<string, mixed>  $content */
    private function fakeOk(array $content, string $finishReason = 'stop'): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [[
                'finish_reason' => $finishReason,
                'message' => ['role' => 'assistant', 'refusal' => null, 'content' => json_encode($content)],
            ]],
        ], 200)]);
    }

    public function test_it_returns_the_draft_the_model_wrote(): void
    {
        $this->fakeOk([
            'sql' => 'SELECT v.name, COUNT(*) AS open_orders FROM purchase_orders po JOIN vendors v ON v.id = po.vendor_id GROUP BY v.name LIMIT 200',
            'answer_template' => '{{count}} vendors have open purchase orders.',
            'chart_hint' => 'bar',
        ]);

        $draft = (new OpenAiSqlWriter)->write($this->request());

        $this->assertStringStartsWith('SELECT v.name', $draft->sql);
        $this->assertSame('{{count}} vendors have open purchase orders.', $draft->answerTemplate);
        $this->assertSame('bar', $draft->chartHint);
    }

    public function test_it_asks_for_the_strict_schema_and_sends_both_prompts(): void
    {
        $this->fakeOk(['sql' => 'SELECT 1', 'answer_template' => 'x', 'chart_hint' => 'none']);

        (new OpenAiSqlWriter)->write($this->request());

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            $this->assertSame('https://api.openai.com/v1/chat/completions', $request->url());
            $this->assertSame('gpt-5.2', $body['model']);
            $this->assertSame('low', $body['reasoning_effort']);
            $this->assertSame(8000, $body['max_completion_tokens']);
            $this->assertTrue($body['response_format']['json_schema']['strict']);
            $this->assertFalse($body['response_format']['json_schema']['schema']['additionalProperties']);
            $this->assertSame(
                ['sql', 'answer_template', 'chart_hint'],
                $body['response_format']['json_schema']['schema']['required'],
            );
            $this->assertSame('system', $body['messages'][0]['role']);
            $this->assertStringContainsString('single SELECT', $body['messages'][0]['content']);
            $this->assertStringContainsString('purchase_orders (procurement)', $body['messages'][1]['content']);
            $this->assertStringEndsWith('Question: how many open purchase orders per vendor', $body['messages'][1]['content']);

            return true;
        });
    }

    public function test_an_unset_key_refuses_before_any_call_is_made(): void
    {
        config(['ask-erp.openai.api_key' => null]);
        Http::fake();

        try {
            (new OpenAiSqlWriter)->write($this->request());
            $this->fail('Expected a refusal.');
        } catch (AskErpException $e) {
            $this->assertSame(503, $e->status);
            $this->assertSame('Ask ERP is not configured on this server.', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_an_exhausted_credit_balance_says_so_in_words_an_administrator_can_act_on(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'error' => [
                'message' => 'You have no credits remaining.',
                'type' => 'insufficient_quota',
                'code' => 'credit_balance_exhausted',
            ],
        ], 429)]);

        try {
            (new OpenAiSqlWriter)->write($this->request());
            $this->fail('Expected a refusal.');
        } catch (AskErpException $e) {
            $this->assertSame(503, $e->status);
            $this->assertStringContainsString('no credit left', $e->getMessage());
            $this->assertStringContainsString('Top up', $e->getMessage());
        }
    }

    public function test_a_rejected_key_reads_as_not_configured_not_as_a_model_failure(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['code' => 'invalid_api_key']], 401)]);

        try {
            (new OpenAiSqlWriter)->write($this->request());
            $this->fail('Expected a refusal.');
        } catch (AskErpException $e) {
            $this->assertSame(503, $e->status);
            $this->assertSame('Ask ERP is not configured on this server.', $e->getMessage());
        }
    }

    public function test_plain_rate_limiting_is_told_apart_from_having_no_credit(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'error' => ['type' => 'rate_limit_error', 'code' => 'rate_limit_exceeded'],
        ], 429)]);

        try {
            (new OpenAiSqlWriter)->write($this->request());
            $this->fail('Expected a refusal.');
        } catch (AskErpException $e) {
            $this->assertSame(429, $e->status);
            $this->assertStringContainsString('Wait a moment', $e->getMessage());
        }
    }

    public function test_a_model_refusal_arrives_as_a_200_and_is_still_refused(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['role' => 'assistant', 'refusal' => 'I cannot help with that.', 'content' => null],
            ]],
        ], 200)]);

        try {
            (new OpenAiSqlWriter)->write($this->request());
            $this->fail('Expected a refusal.');
        } catch (AskErpException $e) {
            $this->assertSame(422, $e->status);
            $this->assertStringContainsString('declined', $e->getMessage());
        }
    }

    public function test_a_truncated_answer_is_refused_rather_than_run_as_half_a_query(): void
    {
        $this->fakeOk(['sql' => 'SELECT * FROM purchase_orders WHERE', 'answer_template' => '', 'chart_hint' => 'none'], 'length');

        $this->expectException(AskErpException::class);
        $this->expectExceptionMessage('truncated');

        (new OpenAiSqlWriter)->write($this->request());
    }

    public function test_an_empty_sql_string_is_the_models_way_of_saying_it_cannot_answer(): void
    {
        $this->fakeOk(['sql' => '   ', 'answer_template' => '', 'chart_hint' => 'none']);

        try {
            (new OpenAiSqlWriter)->write($this->request());
            $this->fail('Expected a refusal.');
        } catch (AskErpException $e) {
            $this->assertSame(422, $e->status);
            $this->assertStringContainsString('Could not turn that into a query', $e->getMessage());
        }
    }

    /*
     * One fake per test, not two in one: Http::fake() ADDS a stub rather than
     * replacing the previous one, so a second fake in the same test never
     * gets reached and the assertion silently passes against the first.
     */

    public function test_waiting_too_long_asks_the_user_to_try_again(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 45000 ms'));

        try {
            (new OpenAiSqlWriter)->write($this->request());
            $this->fail('Expected a refusal.');
        } catch (AskErpException $e) {
            $this->assertSame(504, $e->status);
            $this->assertStringContainsString('did not answer in time', $e->getMessage());
        }
    }

    public function test_an_unreachable_host_is_reported_as_unreachable_not_as_slow(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host api.openai.com'));

        try {
            (new OpenAiSqlWriter)->write($this->request());
            $this->fail('Expected a refusal.');
        } catch (AskErpException $e) {
            $this->assertSame(502, $e->status);
            $this->assertStringContainsString('could not be reached', $e->getMessage());
        }
    }

    public function test_the_driver_setting_decides_which_writer_the_container_hands_out(): void
    {
        config(['ask-erp.driver' => 'openai']);
        $this->assertInstanceOf(OpenAiSqlWriter::class, $this->app->make(SqlWriter::class));

        config(['ask-erp.driver' => 'anthropic']);
        $this->assertInstanceOf(AnthropicSqlWriter::class, $this->app->make(SqlWriter::class));
    }

    public function test_an_unknown_driver_refuses_instead_of_falling_back(): void
    {
        config(['ask-erp.driver' => 'gemini']);

        $this->expectException(AskErpException::class);

        $this->app->make(SqlWriter::class);
    }
}
