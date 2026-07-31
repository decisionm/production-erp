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
        config()->set('production.machine_capability.high_cavity_work_center_ids', [$high->id]);

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
        config()->set('production.machine_capability.high_cavity_work_center_ids', []);

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
        // warn, never refuse. 12 of the master's 103 rows are affected, and a
        // wrong cavity figure would otherwise make a real product unrunnable.
        $this->assertFalse((bool) config('production.machine_capability.enforced'));
    }

    public function test_a_machines_own_declared_capability_beats_the_global_rule(): void
    {
        // THE resolution for the Machine 9 contradiction: the daily sheets show
        // 180ml PDL running 32 shifts at 6 cavities on Machine 9, while the
        // global rule says 6+ belongs on Machine 10. The factory settles it on
        // the Machines & Capabilities tab: declare Machine 9 capable up to 7,
        // and its own declaration outranks the .env list — no deploy, no code.
        [$low] = $this->machines();
        $low->update(['max_cavities' => 7]);

        $service = new MachineCapabilityService;

        $this->assertTrue($service->allows(6, $low->id));
        $this->assertNull($service->warningFor($this->standard(6), $low->id));
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
