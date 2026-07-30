<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AcceptanceFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * `module:{name}` guards must name a permission that actually exists.
 *
 * EnsureModulePermission DERIVES the permission names from the middleware
 * argument ("{name}.view" / "{name}.manage"). So a guard whose name does not
 * match a seeded permission does not fail loudly — it 403s every request for
 * every user, including one holding every permission in the system. There is
 * nothing on screen to distinguish that from "you lack the role", which is how
 * `module:tally-sync` went unnoticed against `tally.view` / `tally.manage`:
 * the entire Tally surface (sync queue, retry, agent tokens, company and
 * ledger mappings) was unreachable by anyone.
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

    public function test_the_tally_guard_names_a_permission_that_exists(): void
    {
        // The specific regression. Named on its own rather than only inside the
        // sweep below, because this is the one that shipped broken and the one
        // the production-to-Tally flow depends on.
        $this->assertContains('tally', $this->guardedModules());
        $this->assertNotContains(
            'tally-sync',
            $this->guardedModules(),
            'module:tally-sync derives tally-sync.view, which no seeder creates — use module:tally.',
        );
    }

    public function test_a_user_with_tally_view_can_reach_the_sync_queue(): void
    {
        Permission::findOrCreate('tally.view', 'web');

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('tally.view');

        // The end-to-end proof: holding the permission the deployment actually
        // grants is enough to see the queue. Before the guard was corrected
        // this was 403 for every user in the system.
        $this->actingAs($user)
            ->getJson('/api/v1/tally-sync/entries')
            ->assertOk();
    }

    public function test_a_user_without_any_tally_permission_is_still_refused(): void
    {
        // The guard must still guard — the fix was a naming correction, not a
        // relaxation.
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)
            ->getJson('/api/v1/tally-sync/entries')
            ->assertForbidden();
    }

    public function test_every_provisioned_module_permission_is_used_by_a_guard(): void
    {
        // Approached from the other side: a seeded "{module}.view" with no
        // guard using that exact module name means the permission grants
        // nothing. Only the module prefixes this deployment actually
        // provisions are checked — the phased modules (sales, finance, hrms,
        // ...) have no permissions yet BY DESIGN, and asserting on them would
        // fail for a reason that is not a defect.
        $this->seed(AcceptanceFixtureSeeder::class);

        $guards = $this->guardedModules();

        $provisioned = Permission::query()
            ->pluck('name')
            ->map(fn (string $name) => str_contains($name, '.') ? explode('.', $name)[0] : $name)
            ->unique()
            ->values();

        foreach ($provisioned as $module) {
            $this->assertContains(
                $module,
                $guards,
                "Permission \"{$module}.view\" is seeded but no route group is guarded by module:{$module} — the permission grants access to nothing.",
            );
        }
    }
}
