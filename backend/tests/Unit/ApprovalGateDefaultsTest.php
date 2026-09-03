<?php

namespace Tests\Unit;

use Tests\TestCase;

/** DEC-20260902-017 and -018: both gates ship OFF and are switched on only after the live reads named in the programme. */
class ApprovalGateDefaultsTest extends TestCase
{
    public function test_readiness_and_postability_default_off(): void
    {
        // Read the shipped config file directly rather than through
        // config(), matching ProductReadinessGateTest::
        // test_the_shipped_default_is_watch_only: this class has no
        // setUp() override to bypass, but requiring the file still keeps
        // the pin honest against one being added later.
        //
        // NOTE for whoever next touches this: phpunit.xml forces
        // PROD_READINESS_ENFORCED=false as a real env var for the whole
        // suite (both here and for the sibling test above), so neither
        // form of this assertion would catch a regression to
        // env('PROD_READINESS_ENFORCED', true) in the shipped file —
        // the env var wins over the file's own default either way. The
        // require_postable_voucher assertion has no such env override and
        // IS fully load-bearing.
        $shipped = require config_path('production.php');

        $this->assertFalse((bool) $shipped['readiness']['enforced']);
        $this->assertFalse((bool) $shipped['approvals']['require_postable_voucher']);
        $this->assertSame('block', $shipped['readiness']['checks']['item_active']);
        $this->assertSame('warn', $shipped['readiness']['checks']['colour']);
    }
}
