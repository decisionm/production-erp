<?php

namespace Tests\Unit;

use Tests\TestCase;

/** DEC-20260902-017 and -018: both gates ship OFF and are switched on only after the live reads named in the programme. */
class ApprovalGateDefaultsTest extends TestCase
{
    public function test_readiness_and_postability_default_off(): void
    {
        $this->assertFalse((bool) config('production.readiness.enforced'));
        $this->assertFalse((bool) config('production.approvals.require_postable_voucher'));
        $this->assertSame('block', config('production.readiness.checks.item_active'));
        $this->assertSame('warn', config('production.readiness.checks.colour'));
    }
}
