<?php

namespace Tests\Feature\Assistant;

use App\Modules\Assistant\Exceptions\AskErpException;
use App\Modules\Assistant\Services\ProviderStatus;
use App\Modules\Assistant\Services\Rules\RuleBook;
use App\Modules\Assistant\Services\RulesSqlWriter;
use App\Modules\Assistant\Services\SqlGuard;
use App\Modules\Assistant\Services\SqlRequest;
use App\Modules\Assistant\Services\SqlWriter;
use Tests\TestCase;

/**
 * The no-key driver. Nothing here reaches a network, which is the point.
 */
class RulesSqlWriterTest extends TestCase
{
    private function ask(string $question): SqlRequest
    {
        return new SqlRequest(question: $question, tableSpecs: [], history: [], today: '2026-09-03');
    }

    public function test_it_answers_the_questions_the_floor_actually_asks(): void
    {
        $writer = new RulesSqlWriter;

        foreach ([
            'how much stock do we have' => 'stock_balances',
            'which items are below reorder level' => 'reorder_level',
            'show me open purchase orders' => 'purchase_orders',
            'open purchase orders per vendor' => 'vendors',
            'what was produced today' => 'shift_production_entries',
            'rejection by machine' => 'quantity_rejection_kg',
            'lumps by machine' => 'lumps',
            'which batches are awaiting quality' => 'pending',
            'open sales orders' => 'sales_orders',
            'who is absent today' => 'attendances',
        ] as $question => $expected) {
            $draft = $writer->write($this->ask($question));

            $this->assertStringContainsString($expected, $draft->sql, "asking: {$question}");
            $this->assertNotSame('', $draft->answerTemplate, "asking: {$question}");
        }
    }

    public function test_the_factory_date_is_substituted_not_curdate(): void
    {
        // The server runs UTC while the factory day is IST, so CURDATE() is
        // the wrong day for several hours every night — the night shift's.
        $draft = (new RulesSqlWriter)->write($this->ask('what was produced today'));

        $this->assertStringContainsString('2026-09-03', $draft->sql);
        $this->assertStringNotContainsStringIgnoringCase('CURDATE()', $draft->sql);
        $this->assertStringNotContainsString('{{today}}', $draft->sql);
    }

    public function test_a_question_it_cannot_answer_is_refused_with_what_it_can(): void
    {
        try {
            (new RulesSqlWriter)->write($this->ask('what is the meaning of life'));
            $this->fail('Expected a refusal.');
        } catch (AskErpException $e) {
            $this->assertSame(422, $e->status);
            // THE EXAMPLES, NOT THE LABELS. The page offered "How much stock
            // do we have?" while the refusal answered "Stock on hand by item"
            // — one product, two vocabularies, and the reader left to guess
            // which one the box wanted.
            $this->assertStringContainsString('How much stock do we have?', $e->getMessage());
            $this->assertStringNotContainsString('Stock on hand by item', $e->getMessage());
        }
    }

    /**
     * "today productivity?" came back refused on the live floor. There IS a
     * rule for today's production; it simply had never been told that word.
     * Every phrasing here is one a supervisor might reasonably type.
     */
    public function test_it_understands_the_words_the_floor_actually_uses(): void
    {
        foreach ([
            'today productivity?' => 'production_today',
            'productivity today' => 'production_today',
            'productivity' => 'production_today',
            'what is our inventory' => 'stock_on_hand',
            'how many bottles do we have' => 'stock_on_hand',
            'anything short of reorder' => 'low_stock',
            'qc pending batches' => 'batches_awaiting_quality',
            'headcount today' => 'attendance_today',
        ] as $question => $expected) {
            $this->assertSame($expected, RulesSqlWriter::match($question)?->key, "asking: {$question}");
        }
    }

    public function test_the_more_specific_rule_wins_a_shared_phrase(): void
    {
        // "lumps by machine" and "output by machine" both contain "by
        // machine"; whoever typed lumps meant lumps.
        $this->assertSame('lumps_by_machine', RulesSqlWriter::match('lumps by machine')?->key);
        $this->assertSame('production_by_machine', RulesSqlWriter::match('output by machine')?->key);
    }

    /**
     * THE ONE THAT MATTERS. SqlGuard strips hidden columns only for the tables
     * the retriever ranked into this question's specs, but a rule's SQL may
     * touch a table it never ranked. A rule naming a money column could
     * therefore slip a rate past FC-06 for a reader with no finance
     * permission. So no rule may name one at all.
     */
    public function test_no_rule_names_a_money_column(): void
    {
        $forbidden = ['average_cost', 'unit_price', 'total_amount', 'subtotal', 'rate', 'salary', 'gross', 'net_pay', 'cgst', 'sgst', 'igst'];

        foreach (RuleBook::all() as $rule) {
            foreach ($forbidden as $column) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $column,
                    $rule->sql,
                    "rule {$rule->key} must not name the money column {$column}",
                );
            }
        }
    }

    public function test_every_rule_survives_the_guard_it_will_actually_face(): void
    {
        $guard = new SqlGuard;
        $allowed = [];
        foreach (RuleBook::all() as $rule) {
            $allowed = array_merge($allowed, $rule->tables);
        }
        $allowed = array_values(array_unique($allowed));

        foreach (RuleBook::all() as $rule) {
            $sql = $guard->check($rule->sqlFor('2026-09-03'), $allowed, [], 200);

            $this->assertStringContainsStringIgnoringCase('select', $sql, "rule {$rule->key}");
        }
    }

    public function test_every_rule_declares_the_tables_its_sql_touches(): void
    {
        $guard = new SqlGuard;

        foreach (RuleBook::all() as $rule) {
            foreach ($guard->tablesIn($rule->sqlFor('2026-09-03')) as $table) {
                $this->assertContains(
                    $table,
                    $rule->tables,
                    "rule {$rule->key} touches {$table} without declaring it",
                );
            }
        }
    }

    public function test_rule_keys_are_unique(): void
    {
        $keys = array_map(static fn ($r) => $r->key, RuleBook::all());

        $this->assertSame(count($keys), count(array_unique($keys)));
    }

    public function test_the_driver_switch_and_readiness_know_about_rules(): void
    {
        config(['ask-erp.driver' => 'rules', 'ask-erp.api_key' => null, 'ask-erp.openai.api_key' => null]);

        $this->assertInstanceOf(RulesSqlWriter::class, $this->app->make(SqlWriter::class));
        // Ready with no key of any kind — that is the entire point of it.
        $this->assertTrue(ProviderStatus::configured());
    }
}
