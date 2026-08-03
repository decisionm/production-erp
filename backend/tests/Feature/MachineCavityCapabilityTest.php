<?php

namespace Tests\Feature;

use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\MachineCapabilityService;
use App\Modules\Production\Services\ProductionStandardResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The cavity rule: moulds of 6 cavities or more are set up for Machine 10.
 *
 * Advisory by design, and these tests pin that down as much as they pin the
 * arithmetic — a future edit that turns the warning into a refusal would stop
 * a shift, so it should have to change a test that says so out loud.
 */
class MachineCavityCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private function machines(): array
    {
        $low = WorkCenter::create(['name' => 'Machine 3', 'code' => 'M3', 'is_active' => true]);
        $high = WorkCenter::create(['name' => 'Machine 10', 'code' => 'M10', 'is_active' => true]);

        config()->set('production.machine_capability.cavity_threshold', 6);
        // BY CODE, never by id. The configured value used to be a work-centre
        // id, and the `10` someone wrote meaning "Machine 10" was MC-05,
        // "Machine 5" — every high-cavity product pointed at the wrong
        // machine. A code cannot be mistaken for a row number.
        config()->set('production.machine_capability.high_cavity_work_center_codes', [$high->code]);

        return [$low, $high];
    }

    private function standard(?int $cavities): ProductionStandard
    {
        return ProductionStandard::create([
            'source_product_name' => '90ML FLAT',
            'cavities' => $cavities,
            'unit_weight_grams' => '12.0000',
            'cycle_time' => '11.00',
            'status' => 'draft',
        ]);
    }

    public function test_a_low_cavity_standard_runs_anywhere(): void
    {
        [$low, $high] = $this->machines();
        $service = new MachineCapabilityService;

        $this->assertFalse($service->isRestricted(5));
        $this->assertTrue($service->allows(5, $low->id));
        $this->assertTrue($service->allows(5, $high->id));
        $this->assertNull($service->warningFor($this->standard(5), $low->id));
    }

    public function test_a_high_cavity_standard_is_restricted_to_the_configured_machine(): void
    {
        [$low, $high] = $this->machines();
        $service = new MachineCapabilityService;

        $this->assertTrue($service->isRestricted(6));
        $this->assertTrue($service->allows(6, $high->id));
        $this->assertFalse($service->allows(6, $low->id));
    }

    public function test_the_warning_names_both_the_intended_machine_and_the_chosen_one(): void
    {
        [$low] = $this->machines();

        $warning = (new MachineCapabilityService)->warningFor($this->standard(7), $low->id);

        $this->assertNotNull($warning);
        $this->assertSame('machine_cavity_restricted', $warning['code']);
        // A supervisor who is only told "no" has to go and find out where —
        // and will start it here anyway.
        $this->assertStringContainsString('Machine 10', $warning['message']);
        $this->assertStringContainsString('Machine 3', $warning['message']);
        $this->assertStringContainsString('7 cavities', $warning['message']);
    }

    public function test_an_unknown_cavity_count_is_never_treated_as_high(): void
    {
        [$low] = $this->machines();
        $service = new MachineCapabilityService;

        // The sheet leaves cavities blank on rows that already carry an
        // unresolved flag. Restricting a product on a figure nobody has would
        // invent a rule the factory never stated.
        $this->assertFalse($service->isRestricted(null));
        $this->assertTrue($service->allows(null, $low->id));
        $this->assertNull($service->warningFor($this->standard(null), $low->id));
    }

    public function test_no_machine_chosen_yet_is_not_a_violation(): void
    {
        $this->machines();

        $this->assertTrue((new MachineCapabilityService)->allows(6, null));
        $this->assertNull((new MachineCapabilityService)->warningFor($this->standard(6), null));
    }

    public function test_the_rule_switches_off_entirely_when_no_machines_are_configured(): void
    {
        [$low] = $this->machines();
        config()->set('production.machine_capability.high_cavity_work_center_codes', []);

        $service = new MachineCapabilityService;

        $this->assertFalse($service->isRestricted(7));
        $this->assertTrue($service->allows(7, $low->id));
    }

    public function test_the_resolver_surfaces_the_cavity_warning_alongside_the_others(): void
    {
        [$low] = $this->machines();
        $standard = $this->standard(6);
        $standard->setRelation('packagings', collect());

        $codes = array_column(
            (new ProductionStandardResolver)->warningsFor($standard, null, 1, $low->id),
            'code',
        );

        $this->assertContains('machine_cavity_restricted', $codes);
    }

    public function test_the_resolver_omits_the_warning_on_an_allowed_machine(): void
    {
        [, $high] = $this->machines();
        $standard = $this->standard(6);
        $standard->setRelation('packagings', collect());

        $codes = array_column(
            (new ProductionStandardResolver)->warningsFor($standard, null, 1, $high->id),
            'code',
        );

        $this->assertNotContains('machine_cavity_restricted', $codes);
    }

    public function test_the_rule_defaults_to_advisory_not_enforced(): void
    {
        // The safety property: a deployment whose .env was never edited must
        // warn, never refuse. Reaffirmed 03-Aug for first-day factory testing —
        // a hard refusal stops a real shift over a cavity figure the master is
        // still settling.
        $this->assertFalse((bool) config('production.machine_capability.enforced'));
    }

    public function test_a_non_compliant_run_is_permitted_but_never_reported_as_compliant(): void
    {
        // THE REGRESSION THIS EXISTS FOR: enforcement being off must not make
        // the warning disappear or mark the run compliant. Those exception
        // records are the evidence for deciding whether to switch enforcement
        // on, so a permissive setting that silently reported "fine" would
        // leave the log empty for exactly the wrong reason.
        [$low] = $this->machines();
        config()->set('production.machine_capability.enforced', false);

        $service = new MachineCapabilityService;

        // 6 cavities on a machine that is not MC-10: not compliant...
        $this->assertFalse($service->compliesWithRecommendation(6, $low->id));
        // ...but permitted, because enforcement is off...
        $this->assertTrue($service->isPermitted(6, $low->id));
        // ...and still spoken about, loudly.
        $warning = $service->warningFor($this->standard(6), $low->id);
        $this->assertNotNull($warning);
        $this->assertSame('machine_cavity_restricted', $warning['code']);
    }

    public function test_five_cavities_is_not_restricted_because_the_rule_is_more_than_five(): void
    {
        // 03-Aug ruling: five is NOT automatically MC-10. The 60 ml Round Amber
        // product carries a 5-cavity workbook standard and runs on MC-04.
        [$low] = $this->machines();

        $service = new MachineCapabilityService;

        $this->assertFalse($service->isRestricted(5));
        $this->assertTrue($service->allows(5, $low->id));
        $this->assertTrue($service->isRestricted(6));
    }

    public function test_the_restricted_machine_resolves_by_code_not_by_row_id(): void
    {
        // THE MC-10 / ID-10 REGRESSION. Machine 10's row id is not 10 — the
        // work-centre table starts at MC-01 = 6 — so a rule configured with the
        // number 10 selected Machine 5. Configuring the CODE must select
        // Machine 10 whatever its id happens to be.
        [$low, $high] = $this->machines();
        $decoy = WorkCenter::create(['name' => 'Machine 5', 'code' => 'M5', 'is_active' => true]);

        $service = new MachineCapabilityService;

        $this->assertSame([$high->id], $service->restrictedWorkCenterIds());
        $this->assertNotContains($decoy->id, $service->restrictedWorkCenterIds());
        $this->assertTrue($service->allows(7, $high->id));
        $this->assertFalse($service->allows(7, $decoy->id));
    }

    public function test_the_factory_rule_outranks_a_machines_own_capability_at_or_above_the_threshold(): void
    {
        // REVERSED 03-Aug on the factory's instruction. A machine declaring
        // itself capable of 6 no longer earns a 6-cavity mould: above the
        // threshold the rule is the rule, or a capability row edited on the
        // Machines & Capabilities tab would quietly override the factory's
        // "more than five means Machine 10". Below the threshold the machine's
        // own declaration still governs — see the two tests after this one.
        [$low] = $this->machines();
        $low->update(['max_cavities' => 7]);

        $service = new MachineCapabilityService;

        $this->assertFalse($service->allows(6, $low->id));
        $this->assertNotNull($service->warningFor($this->standard(6), $low->id));
    }

    public function test_a_machine_declaring_a_low_maximum_refuses_even_below_the_global_threshold(): void
    {
        // The other direction: a machine set up for at most 3 cavities warns on
        // a 4-cavity mould even though the global rule only watches 6 and up.
        [$low] = $this->machines();
        $low->update(['max_cavities' => 3]);

        $warning = (new MachineCapabilityService)->warningFor($this->standard(4), $low->id);

        $this->assertNotNull($warning);
        $this->assertStringContainsString('set up for', $warning['message']);
        $this->assertStringContainsString('Machines & Capabilities', $warning['message']);
    }

    public function test_an_explicit_permitted_list_beats_the_min_max_range(): void
    {
        [$low] = $this->machines();
        $low->update(['min_cavities' => 1, 'max_cavities' => 8, 'permitted_cavities' => [2, 4]]);

        $service = new MachineCapabilityService;

        $this->assertTrue($service->allows(4, $low->id));
        // Inside the range but not on the list — the list is the setup truth.
        $this->assertFalse($service->allows(3, $low->id));
    }

    public function test_a_machine_declaring_nothing_still_falls_back_to_the_global_rule(): void
    {
        // Capability columns all null — the .env threshold rule keeps working
        // exactly as before, so deployments that never touch the tab lose
        // nothing.
        [$low, $high] = $this->machines();

        $service = new MachineCapabilityService;

        $this->assertFalse($service->allows(6, $low->id));
        $this->assertTrue($service->allows(6, $high->id));
    }
}
