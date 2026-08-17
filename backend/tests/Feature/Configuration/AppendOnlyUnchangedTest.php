<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * T16 — the append-only surfaces are UNCHANGED by Phase 7.6.
 *
 * The Configuration Lifecycle Contract adds Edit/Archive/Delete to
 * CONFIGURATION masters. It must not leak one inch into a transaction, a
 * ledger row or a posted document: those stay append-only, exactly as
 * MaterialLotCostVersionTest already pins for the cost history.
 *
 * Two tiers, because either alone can lie:
 *   - the ROUTER tier asks the route collection directly, so a real new
 *     PUT route cannot hide behind a model-binding 404;
 *   - the HTTP tier drives a fully-permissioned user through the stack, so
 *     a route that exists but 403s is not mistaken for a route that does
 *     not exist. The contract's own words: still 405/404.
 */
class AppendOnlyUnchangedTest extends TestCase
{
    use RefreshDatabase;

    /** Collection and member URLs of every surface that must stay append-only. */
    private const APPEND_ONLY_URLS = [
        'api/v1/inventory/material-lots/1/cost-versions',
        'api/v1/inventory/material-lots/1/cost-versions/1',
        'api/v1/inventory/stock-movements',
        'api/v1/inventory/stock-movements/1',
        'api/v1/finance/journal-entries',
        'api/v1/finance/journal-entries/1',
        'api/v1/tally-sync/entries',
        'api/v1/tally-sync/entries/1',
        'api/v1/production/shift-production-entries',
        'api/v1/production/shift-production-entries/1',
    ];

    private const MUTATING_METHODS = ['PUT', 'PATCH', 'DELETE'];

    public function test_no_mutating_route_is_registered_on_any_append_only_surface(): void
    {
        $routes = Route::getRoutes();

        foreach (self::APPEND_ONLY_URLS as $url) {
            foreach (self::MUTATING_METHODS as $method) {
                $matched = null;
                try {
                    $matched = $routes->match(Request::create("/{$url}", $method))->uri();
                } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
                    // Exactly what append-only means at the router: nothing to dispatch.
                }

                $this->assertNull($matched, "{$method} /{$url} resolved to a route ({$matched}) — the append-only surface grew a mutating verb.");
            }
        }
    }

    public function test_every_append_only_surface_still_answers_405_or_404(): void
    {
        $this->actingAsFullyPermissioned();

        foreach (self::APPEND_ONLY_URLS as $url) {
            foreach (self::MUTATING_METHODS as $method) {
                $status = $this->json($method, "/{$url}", ['quantity' => '1'])->getStatusCode();

                $this->assertContains(
                    $status,
                    [404, 405],
                    "{$method} /{$url} answered {$status}; append-only surfaces must answer 405 or 404.",
                );
            }
        }
    }

    private function actingAsFullyPermissioned(): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach (['inventory', 'finance', 'production', 'tally-sync', 'procurement', 'quality'] as $module) {
            foreach (['view', 'manage'] as $ability) {
                Permission::findOrCreate("{$module}.{$ability}", 'web');
                $user->givePermissionTo("{$module}.{$ability}");
            }
        }

        Sanctum::actingAs($user);

        return $user;
    }
}
