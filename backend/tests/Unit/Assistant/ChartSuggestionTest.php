<?php

namespace Tests\Unit\Assistant;

use App\Modules\Assistant\Services\ChartSuggestion;
use PHPUnit\Framework\TestCase;

class ChartSuggestionTest extends TestCase
{
    public function test_label_plus_number_is_a_bar(): void
    {
        $rows = [['status' => 'open', 'n' => 3], ['status' => 'closed', 'n' => 9]];

        $this->assertSame(['type' => 'bar', 'x' => 'status', 'y' => 'n'], ChartSuggestion::for(['status', 'n'], $rows, 'none'));
    }

    public function test_number_first_is_still_a_bar(): void
    {
        $rows = [['n' => 3, 'status' => 'open'], ['n' => 9, 'status' => 'closed']];

        $this->assertSame(['type' => 'bar', 'x' => 'status', 'y' => 'n'], ChartSuggestion::for(['n', 'status'], $rows, 'bar'));
    }

    public function test_date_label_is_a_line(): void
    {
        $rows = [['day' => '2026-09-01', 'kg' => '10.5'], ['day' => '2026-09-02', 'kg' => '12']];

        $this->assertSame(['type' => 'line', 'x' => 'day', 'y' => 'kg'], ChartSuggestion::for(['day', 'kg'], $rows, 'none'));
    }

    public function test_single_row_or_three_columns_is_no_chart(): void
    {
        $this->assertNull(ChartSuggestion::for(['n'], [['n' => 4]], 'bar'));
        $this->assertNull(ChartSuggestion::for(['a', 'b', 'c'], [['a' => 'x', 'b' => 1, 'c' => 2], ['a' => 'y', 'b' => 1, 'c' => 2]], 'bar'));
    }

    public function test_two_numeric_columns_is_no_chart(): void
    {
        $this->assertNull(ChartSuggestion::for(['a', 'b'], [['a' => 1, 'b' => 2], ['a' => 3, 'b' => 4]], 'bar'));
    }

    public function test_more_than_sixty_rows_is_no_chart(): void
    {
        $rows = array_map(static fn (int $i) => ['k' => "k{$i}", 'v' => $i], range(1, 61));

        $this->assertNull(ChartSuggestion::for(['k', 'v'], $rows, 'bar'));
    }
}
