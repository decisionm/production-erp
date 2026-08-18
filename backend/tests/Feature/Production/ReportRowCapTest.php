<?php

namespace Tests\Feature\Production;

use App\Modules\Production\Services\ProductionReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 7, P7-05 — a report that shows part of a range SAYS it showed part.
 *
 * The reconciliation and traceability reports are bounded only by
 * MAX_RANGE_DAYS (92 days), so a wide range on a busy factory returns a long
 * list. Cutting it is fine; cutting it silently is not — a reader who sees
 * 5,000 rows of a 6,000-row period and is told nothing reads a partial list as
 * the whole period, which is the reporting dishonesty this phase exists to
 * remove. Both payloads therefore carry `row_cap` and `truncated`.
 *
 * The cap is deliberately NOT the Export Center's cap: that one governs the
 * FILE (config/exports.php), this one the SCREEN's read.
 */
class ReportRowCapTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_report_within_the_cap_says_it_was_not_truncated(): void
    {
        $report = app(ProductionReportService::class)->reconciliationReport('2026-08-01', '2026-08-05');

        $this->assertArrayHasKey('rows', $report);
        $this->assertSame(5000, $report['row_cap']);
        $this->assertFalse($report['truncated'], 'an empty range is not a truncated one');
    }

    public function test_the_traceability_report_carries_the_same_two_keys(): void
    {
        config(['production.traceability_enabled' => true]);

        $report = app(ProductionReportService::class)->traceabilityReport(null, null, '2026-08-01', '2026-08-05');

        $this->assertArrayHasKey('lots', $report);
        $this->assertSame(5000, $report['row_cap']);
        $this->assertFalse($report['truncated']);
    }

    public function test_the_cap_is_configurable_and_the_flag_follows_it(): void
    {
        // The cap is read per call, not baked in at boot, so an instance can
        // lower it without a redeploy of the reader.
        config(['production.report_row_cap' => 1]);

        $report = app(ProductionReportService::class)->reconciliationReport('2026-08-01', '2026-08-05');

        $this->assertSame(1, $report['row_cap']);
        // Nothing to cut here, so still honest: false, not "unknown".
        $this->assertFalse($report['truncated']);
    }

    public function test_a_list_longer_than_the_cap_is_cut_and_says_so(): void
    {
        // The cut itself, proved directly on the helper every report returns
        // through — the fixture cost of 5,001 completed entries would buy no
        // extra certainty about this arithmetic.
        $capped = $this->capped(array_fill(0, 7, ['row' => 1]), 'rows', cap: 3);

        $this->assertCount(3, $capped['rows'], 'the list is cut at the cap');
        $this->assertTrue($capped['truncated'], 'and the reader is told it was cut');
        $this->assertSame(3, $capped['row_cap']);
    }

    public function test_a_list_exactly_at_the_cap_is_not_called_truncated(): void
    {
        // The off-by-one that would cry wolf on every full page.
        $capped = $this->capped(array_fill(0, 3, ['row' => 1]), 'rows', cap: 3);

        $this->assertCount(3, $capped['rows']);
        $this->assertFalse($capped['truncated'], 'exactly at the cap is complete, not cut');
    }

    /** @return array<string, mixed> */
    private function capped(array $rows, string $key, int $cap): array
    {
        config(['production.report_row_cap' => $cap]);

        $method = new \ReflectionMethod(ProductionReportService::class, 'capped');

        return $method->invoke(null, $rows, $key);
    }

    public function test_a_nonsense_cap_cannot_produce_an_empty_report(): void
    {
        // A misconfigured 0 (or a negative) would otherwise return an empty
        // list and call it complete — the exact lie the flag exists to stop.
        config(['production.report_row_cap' => 0]);

        $report = app(ProductionReportService::class)->reconciliationReport('2026-08-01', '2026-08-05');

        $this->assertSame(1, $report['row_cap'], 'the floor is one row, never zero');
    }
}
