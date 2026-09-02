<?php

namespace Tests\Unit;

use Tests\TestCase;

/** DEC-20260902-022: both blocking thresholds stay disabled; variance is advisory. */
class ConsumptionBlockingDefaultsTest extends TestCase
{
    public function test_both_blocking_thresholds_default_to_disabled(): void
    {
        $tolerance = config('production.tolerances'); // the block holding machine_balance_ack_kg at config/production.php:91
        $this->assertNull($tolerance['variance_blocking_pct']);
        $this->assertNull($tolerance['unaccounted_blocking_kg']);
    }
}
