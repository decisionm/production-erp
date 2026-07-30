<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Core\Services\PermissionService;
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

    /** Every distinct module guard actually registered on a route. */
    private function guardedModules(): array
    {
        $modules = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'module:')) {
                    $modules[substr($middleware, strlen('module:'))] = true;
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
}
