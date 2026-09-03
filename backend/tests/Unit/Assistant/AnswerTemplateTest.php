<?php

namespace Tests\Unit\Assistant;

use App\Modules\Assistant\Services\AnswerTemplate;
use PHPUnit\Framework\TestCase;

class AnswerTemplateTest extends TestCase
{
    public function test_fills_count_first_and_sum(): void
    {
        $rows = [['vendor' => 'Acme', 'n' => 3], ['vendor' => 'Bolt', 'n' => 5]];

        $this->assertSame(
            '2 vendors; Acme leads with 3; 8 in all.',
            AnswerTemplate::render('{{count}} vendors; {{first.vendor}} leads with {{first.n}}; {{sum.n}} in all.', ['vendor', 'n'], $rows),
        );
    }

    public function test_decimals_keep_two_places(): void
    {
        $rows = [['kg' => '10.456'], ['kg' => '2']];

        $this->assertSame('12.46 kg', AnswerTemplate::render('{{sum.kg}} kg', ['kg'], $rows));
    }

    public function test_empty_result_says_so(): void
    {
        $this->assertSame('No rows matched.', AnswerTemplate::render('{{first.vendor}} leads.', ['vendor'], []));
    }

    public function test_blank_template_counts_rows(): void
    {
        $this->assertSame('2 rows.', AnswerTemplate::render('', ['n'], [['n' => 1], ['n' => 2]]));
    }
}
