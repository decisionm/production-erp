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
        // suite, so THIS assertion cannot tell "the shipped default is
        // false" apart from "the test environment forced it to false" —
        // env() reads the real env var over the file's own second
        // argument either way. It still proves the runtime BEHAVIOUR is
        // watch-only, which is worth pinning on its own; the SOURCE pin
        // below is what actually guards the shipped default. The
        // require_postable_voucher half has no such env override and is
        // fully load-bearing here.
        $shipped = require config_path('production.php');

        $this->assertFalse((bool) $shipped['readiness']['enforced']);
        $this->assertFalse((bool) $shipped['approvals']['require_postable_voucher']);
        $this->assertSame('block', $shipped['readiness']['checks']['item_active']);
        $this->assertSame('warn', $shipped['readiness']['checks']['colour']);
    }

    /**
     * SOURCE-TEXT pin, alongside the runtime one above.
     *
     * phpunit.xml forces PROD_READINESS_ENFORCED=false as a real
     * environment variable for the whole suite, so env('PROD_READINESS_
     * ENFORCED', ...) always evaluates to false during a test run no
     * matter what the file's own second-argument default says — neither
     * config() nor `require config_path()` (both ultimately call env())
     * can distinguish "shipped false" from "forced false by the test
     * environment". The literal source text of the config file is the
     * only place that distinction is still visible, so this test reads
     * the file as text instead of executing it, and asserts the exact
     * `env(..., false)` calls are still there. This is the load-bearing
     * half of the pin: it is the one that would fail if either default
     * were hardcoded to true.
     */
    public function test_the_shipped_source_still_defaults_both_switches_to_false(): void
    {
        $source = file_get_contents(config_path('production.php'));

        $this->assertStringContainsString(
            "env('PROD_READINESS_ENFORCED', false)",
            $source,
            'DEC-20260902-017: the readiness gate must ship OFF by default — enforcement is switched on only after every active production product shows Ready on live.',
        );

        $this->assertStringContainsString(
            "env('PROD_REQUIRE_POSTABLE_VOUCHER', false)",
            $source,
            'DEC-20260902-018: the posting gate must ship OFF by default — it is switched on only after the voucher preview is checked against real batches on live.',
        );
    }
}
