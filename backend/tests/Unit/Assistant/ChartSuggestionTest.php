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

    public function test_one_column_or_one_row_is_no_chart(): void
    {
        $this->assertNull(ChartSuggestion::for(['n'], [['n' => 4]], 'bar'));
        $this->assertNull(ChartSuggestion::for(['k', 'v'], [['k' => 'x', 'v' => 1]], 'bar'));
    }

    /*
     * THE THREE SHAPES THAT CAME BACK EMPTY ON THE LIVE FLOOR. Demanding
     * exactly two columns refused every one of them, and each is a perfectly
     * good bar chart. The real answers are quoted in the test names.
     */

    public function test_today_productivity_charts_the_pieces_not_the_scrap(): void
    {
        // "Today, 10 work centers produced 141,430 pieces with 8,119 scrap."
        $rows = [
            ['work_center' => 'ASB-1', 'total_produced' => 19000, 'total_scrap' => 900],
            ['work_center' => 'ASB-2', 'total_produced' => 24000, 'total_scrap' => 700],
        ];

        // The larger total is the measure the question was about.
        $this->assertSame(
            ['type' => 'bar', 'x' => 'work_center', 'y' => 'total_produced'],
            ChartSuggestion::for(['work_center', 'total_produced', 'total_scrap'], $rows, 'bar'),
        );
    }

    public function test_two_label_columns_chart_against_the_first(): void
    {
        // "Machine 2 (ASB-2) produced the most bottles this month."
        $rows = [
            ['machine_code' => 'ASB-1', 'machine_name' => 'Machine 1', 'total_pieces' => 41000],
            ['machine_code' => 'ASB-2', 'machine_name' => 'Machine 2', 'total_pieces' => 63840],
        ];

        $this->assertSame(
            ['type' => 'bar', 'x' => 'machine_code', 'y' => 'total_pieces'],
            ChartSuggestion::for(['machine_code', 'machine_name', 'total_pieces'], $rows, 'bar'),
        );
    }

    public function test_rejection_beats_the_batch_count_on_size(): void
    {
        // Asked about rejection you mean the kilograms, not how many batches.
        $rows = [
            ['machine' => 'ASB-1', 'rejection_kg' => 120, 'batches' => 24],
            ['machine' => 'ASB-4', 'rejection_kg' => 80, 'batches' => 16],
        ];

        $this->assertSame('rejection_kg', ChartSuggestion::for(['machine', 'rejection_kg', 'batches'], $rows, 'bar')['y']);
    }

    public function test_an_id_column_is_never_the_measure(): void
    {
        // A key is numeric and often large; a chart of primary keys is noise.
        $rows = [
            ['vendor' => 'Acme', 'purchase_order_id' => 90210, 'open_orders' => 2],
            ['vendor' => 'Globex', 'purchase_order_id' => 90211, 'open_orders' => 5],
        ];

        $this->assertSame('open_orders', ChartSuggestion::for(['vendor', 'purchase_order_id', 'open_orders'], $rows, 'bar')['y']);
    }

    public function test_no_measure_left_after_excluding_ids_is_no_chart(): void
    {
        $rows = [['vendor' => 'Acme', 'id' => 1], ['vendor' => 'Globex', 'id' => 2]];

        $this->assertNull(ChartSuggestion::for(['vendor', 'id'], $rows, 'bar'));
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
