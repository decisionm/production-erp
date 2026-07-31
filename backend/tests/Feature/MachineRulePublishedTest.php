<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\MachineCapabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The factory's machine rule, published so a screen can state it.
 *
 * The rule — a mould under the cavity threshold runs on any machine, at or
 * above it runs only on the machines named in config — was enforced on every
 * Start Batch and shown on no screen. So the owner, who had given us that rule,
 * could not find it anywhere and reasonably concluded the machine mapping had
 * not been built.
 *
 * The answer is to publish the rule rather than expand it into rows: one
 * threshold plus the machines above it belong to lets any screen derive "which
 * machines does this product run on?" from the cavity count it already has.
 * Roughly 790 product-machine rows would have had to be approved by a person
 * and would then have drifted from the workbook the moment a cycle time was
 * corrected — an approved configuration outranks the standard it was copied
 * from.
 */
class MachineRulePublishedTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['production.view', 'production.manage'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $user->givePermissionTo(['production.view', 'production.manage']);
        $this->actingAs($user);

        return $user;
    }

    public function test_the_settings_endpoint_publishes_the_threshold_and_the_machines_it_names(): void
    {
        $this->actor();

        // Machine 10 is the factory's high-cavity machine; the others are not.
        $low = WorkCenter::create(['name' => 'Machine 3', 'code' => 'MC-03', 'is_active' => true, 'display_sequence' => 3]);
        $high = WorkCenter::create(['name' => 'Machine 10', 'code' => 'MC-10', 'is_active' => true, 'display_sequence' => 10]);

        config()->set('production.machine_capability.cavity_threshold', 6);
        config()->set('production.machine_capability.high_cavity_work_center_ids', [$high->id]);

        $response = $this->getJson('/api/v1/production/settings')->assertOk();

        $response->assertJsonPath('data.machine_capability.cavity_threshold', 6);
        $response->assertJsonPath('data.machine_capability.restricted_machines.0.id', $high->id);
        $response->assertJsonPath('data.machine_capability.restricted_machines.0.name', 'Machine 10');

        // Only the restricted machines are named — a screen must be able to say
        // "Machine 10 only", not "all ten machines, one of which is special".
        $names = array_column($response->json('data.machine_capability.restricted_machines'), 'name');
        $this->assertNotContains($low->name, $names);
    }

    public function test_the_published_rule_agrees_with_the_rule_that_is_enforced(): void
    {
        // The whole value of publishing is that the screen and the gate cannot
        // disagree. Both read the same service, and this asserts they do: the
        // threshold shown is the threshold that refuses, and the machine named
        // is the machine that allows.
        $this->actor();

        $low = WorkCenter::create(['name' => 'Machine 3', 'code' => 'MC-03', 'is_active' => true, 'display_sequence' => 3]);
        $high = WorkCenter::create(['name' => 'Machine 10', 'code' => 'MC-10', 'is_active' => true, 'display_sequence' => 10]);

        config()->set('production.machine_capability.cavity_threshold', 6);
        config()->set('production.machine_capability.high_cavity_work_center_ids', [$high->id]);

        $published = $this->getJson('/api/v1/production/settings')->assertOk()->json('data.machine_capability');
        $service = new MachineCapabilityService;

        $threshold = (int) $published['cavity_threshold'];
        $restrictedIds = array_column($published['restricted_machines'], 'id');

        // At the threshold: refused on a machine the published list omits,
        // allowed on one it names.
        $this->assertFalse($service->allows($threshold, $low->id));
        $this->assertTrue($service->allows($threshold, $high->id));
        $this->assertContains($high->id, $restrictedIds);

        // Below it: every machine, which is what "All machines" on the screen
        // will claim.
        $this->assertTrue($service->allows($threshold - 1, $low->id));
        $this->assertTrue($service->allows($threshold - 1, $high->id));
    }

    public function test_no_restricted_machines_configured_publishes_an_empty_list_not_a_guess(): void
    {
        // A deployment that has not named its high-cavity machines must say so
        // plainly. A screen showing "Machine 10 only" off a stale default would
        // send a supervisor to the wrong machine.
        $this->actor();
        config()->set('production.machine_capability.high_cavity_work_center_ids', []);

        $this->getJson('/api/v1/production/settings')
            ->assertOk()
            ->assertJsonPath('data.machine_capability.restricted_machines', []);
    }

    public function test_a_configured_machine_that_no_longer_exists_is_omitted_rather_than_faked(): void
    {
        // Config points at an id the work-centre master does not carry (a
        // renumbered floor, a machine retired). Publishing a name for it would
        // be inventing one.
        $this->actor();
        config()->set('production.machine_capability.high_cavity_work_center_ids', [99999]);

        $this->getJson('/api/v1/production/settings')
            ->assertOk()
            ->assertJsonPath('data.machine_capability.restricted_machines', []);
    }
}
