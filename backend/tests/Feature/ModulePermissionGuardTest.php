<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Core\Services\PermissionService;
use App\Modules\TallySync\Services\AgentIdentity;
use App\Support\Configuration\HardDeleteAuthority;
use Database\Seeders\AcceptanceFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Every `module:{key}` route guard must name a key in PermissionService::MODULES,
 * and every seeder must provision permissions from that same list.
 *
 * EnsureModulePermission DERIVES the permission names from the middleware
 * argument ("{key}.view" / "{key}.manage"). Nothing cross-checks that string
 * against the catalogue, so a guard or a seeder naming a key the other side
 * does not know fails as a plain "You don't have permission to access this
 * feature" — indistinguishable from a genuinely under-privileged user, and
 * silent in a test suite that grants the permission it invented.
 *
 * That is exactly how AcceptanceFixtureSeeder drifted: it hand-listed
 * "tally.view"/"tally.manage" while the catalogue and every route group use
 * "tally-sync". Fixture users then held a permission gating nothing and lacked
 * the one gating the Tally screens, so the whole Tally surface 403'd on any
 * database seeded from that fixture alone — which reads as a product bug.
 */
class ModulePermissionGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every distinct module guard actually registered on a route.
     *
     * A guard may name MORE THAN ONE module — `module:production,inventory`,
     * the two-sided documents (Phase 7.5) — and then EITHER module's
     * permission opens it. Each name is checked against the catalogue
     * separately: a typo in the second name would be exactly as silent as a
     * typo in the first.
     */
    private function guardedModules(): array
    {
        $modules = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'module:')) {
                    foreach (explode(',', substr($middleware, strlen('module:'))) as $module) {
                        $modules[trim($module)] = true;
                    }
                }
            }
        }

        return array_keys($modules);
    }

    public function test_every_route_guard_names_a_module_in_the_catalogue(): void
    {
        $catalogue = array_keys(PermissionService::MODULES);

        foreach ($this->guardedModules() as $guard) {
            $this->assertContains(
                $guard,
                $catalogue,
                "Route guard module:{$guard} derives {$guard}.view, which PermissionService::MODULES does not define — no seeder will ever create it, so every request to that group 403s for every user.",
            );
        }
    }

    public function test_the_acceptance_fixture_provisions_the_whole_catalogue(): void
    {
        // The regression. This fixture is what a local/demo database is seeded
        // from, so a permission missing here is invisible until someone opens
        // the screen it gates.
        $this->seed(AcceptanceFixtureSeeder::class);

        $seeded = Permission::query()->pluck('name')->all();

        foreach (app(PermissionService::class)->allPermissionNames() as $expected) {
            $this->assertContains(
                $expected,
                $seeded,
                "AcceptanceFixtureSeeder does not create \"{$expected}\" — a fixture user cannot reach the screens it gates.",
            );
        }
    }

    public function test_the_fixture_supervisor_can_reach_the_tally_queue(): void
    {
        // End-to-end proof against the real fixture rather than a hand-granted
        // permission: this 403'd before, for every fixture user.
        $this->seed(AcceptanceFixtureSeeder::class);

        $this->actingAs(User::where('email', 'supervisor@example.com')->firstOrFail())
            ->getJson('/api/v1/tally-sync/entries')
            ->assertOk();
    }

    public function test_a_user_with_no_permissions_is_still_refused(): void
    {
        // The guard must still guard — the fix was a seeder correction, not a
        // relaxation of the route.
        $this->actingAs(User::factory()->create(['is_active' => true]))
            ->getJson('/api/v1/tally-sync/entries')
            ->assertForbidden();
    }

    /**
     * The fixture's supervisor desk must NOT hold the three elevated tiers.
     *
     * The seeder's job is to state locally what live states, and the failure
     * mode is one-directional and silent: a fixture that grants MORE than live
     * does not break anything visibly — it makes every manual walkthrough of
     * that gate pass for the wrong reason. Both of the tiers below were caught
     * exactly that way in the 18-Aug browser walk, after carton-trace had
     * already been caught the same way earlier.
     *
     * Asserted through the real predicates the product gates on, not through
     * the permission names, so renaming a permission cannot quietly reopen a
     * tier that this test claims to hold shut.
     */
    public function test_the_fixture_supervisor_desk_holds_no_elevated_tier(): void
    {
        $this->seed(AcceptanceFixtureSeeder::class);

        $supervisor = User::where('email', 'supervisor@example.com')->firstOrFail();

        // FC-06, both halves: purchase rates AND supplier identity.
        $this->assertFalse(
            AgentIdentity::mayReadPurchaseDetails($supervisor),
            'The fixture supervisor can read purchase rates and supplier identity — FC-06 is Owner/Accounts only, so the FC-06 half of any manual walkthrough would pass for the wrong reason.',
        );

        // The configuration hard-delete tier (DEC-20260817-002 §3).
        $this->assertFalse(
            $supervisor->can(HardDeleteAuthority::PERMISSION),
            'The fixture supervisor can hard-delete configuration masters — that tier is Super Admin / Owner level.',
        );

        // The internal carton trace (DEC-20260810-001) — the original case.
        $this->assertFalse(
            $supervisor->can('carton-trace.view'),
            'The fixture supervisor holds the internal carton trace tier.',
        );

        // ...and the desks that SHOULD hold them still do, or the fixture has
        // simply been emptied rather than corrected.
        $accounts = User::where('email', 'accounts@example.com')->firstOrFail();
        $this->assertTrue(AgentIdentity::mayReadPurchaseDetails($accounts));
        $this->assertTrue($accounts->can(HardDeleteAuthority::PERMISSION));
    }
}
