<?php

namespace Tests\Unit\Assistant;

use App\Modules\Assistant\Services\AnthropicSqlWriter;
use App\Modules\Assistant\Services\SqlRequest;
use PHPUnit\Framework\TestCase;

class AnthropicSqlWriterTest extends TestCase
{
    public function test_prompt_carries_the_rules_the_specs_and_the_history(): void
    {
        $request = new SqlRequest(
            question: 'how many open POs',
            tableSpecs: ["purchase_orders (procurement): A PO.\n  - id integer — pk"],
            history: [['question' => 'vendors count', 'sql' => 'SELECT COUNT(*) FROM vendors', 'answer' => 'There are 12 vendors.']],
            today: '2026-09-03',
        );

        $system = AnthropicSqlWriter::systemPrompt();
        $user = AnthropicSqlWriter::userPrompt($request);

        $this->assertStringContainsString('single SELECT', $system);
        $this->assertStringContainsString('MySQL', $system);
        $this->assertStringContainsString('purchase_orders (procurement)', $user);
        $this->assertStringContainsString('Today is 2026-09-03', $user);
        $this->assertStringContainsString('vendors count', $user);
        $this->assertStringContainsString('SELECT COUNT(*) FROM vendors', $user);
        $this->assertStringContainsString('There are 12 vendors.', $user);
        $this->assertStringEndsWith('Question: how many open POs', $user);
    }

    public function test_prompt_without_history_has_no_history_section(): void
    {
        $user = AnthropicSqlWriter::userPrompt(new SqlRequest('x', ['t (m): p'], [], '2026-09-03'));

        $this->assertStringNotContainsString('Earlier in this conversation', $user);
    }

    public function test_output_schema_requires_sql_answer_and_chart_hint(): void
    {
        $schema = AnthropicSqlWriter::outputSchema();

        $this->assertSame(['sql', 'answer_template', 'chart_hint'], $schema['required']);
        $this->assertSame(['bar', 'line', 'none'], $schema['properties']['chart_hint']['enum']);
        $this->assertFalse($schema['additionalProperties']);
    }
}
