<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\MaterialRequestStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * WHO MAY DO WHAT with a material request. The document has TWO sides and
 * the permission wall says so:
 *
 *   RAISING one is the FLOOR's act        — production.manage
 *   READING the queue is the STORE's      — inventory.view (or .manage), and
 *                                           production may read its own too
 *   CANCELLING is either side's           — production.manage OR
 *                                           inventory.manage (the floor
 *                                           withdraws; the store returns a
 *                                           request it cannot fulfil)
 *
 * A login holding neither module's permission sees nothing at all, and a
 * .view login can never write — EnsureModulePermission's rule, unchanged.
 *
 * FC-06: no rate, no amount and no vendor appears anywhere on this surface,
 * so no finance standing is needed to read it and none is granted by it.
 */
class MaterialRequestPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Item $carton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->carton = Item::create(['sku' => 'PKG-CTN', 'name' => 'Carton 500ml', 'uom' => 'Nos', 'is_production_input' => true]);
    }

    public function test_a_login_with_neither_module_sees_nothing(): void
    {
        $this->actingWith(['sales.manage']);
        $request = $this->seededRequest();

        $this->getJson('/api/v1/inventory/material-requests')->assertStatus(403);
        $this->getJson("/api/v1/inventory/material-requests/{$request->id}")->assertStatus(403);
        $this->postJson('/api/v1/inventory/material-requests', $this->payload())->assertStatus(403);
        $this->postJson("/api/v1/inventory/material-requests/{$request->id}/submit")->assertStatus(403);
        $this->postJson("/api/v1/inventory/material-requests/{$request->id}/cancel", ['reason' => 'x'])->assertStatus(403);
    }

    public function test_the_store_reads_the_queue_with_inventory_view(): void
    {
        $this->actingWith(['inventory.view']);
        $request = $this->seededRequest();

        $this->getJson('/api/v1/inventory/material-requests')->assertOk();
        $this->getJson("/api/v1/inventory/material-requests/{$request->id}")->assertOk();
    }

    public function test_production_reads_its_own_requests_with_production_view(): void
    {
        $this->actingWith(['production.view']);
        $request = $this->seededRequest();

        $this->getJson('/api/v1/inventory/material-requests')->assertOk();
        $this->getJson("/api/v1/inventory/material-requests/{$request->id}")->assertOk();
    }

    public function test_a_view_only_login_can_never_raise_submit_or_cancel(): void
    {
        $this->actingWith(['production.view', 'inventory.view']);
        $request = $this->seededRequest();

        $this->postJson('/api/v1/inventory/material-requests', $this->payload())->assertStatus(403);
        $this->postJson("/api/v1/inventory/material-requests/{$request->id}/submit")->assertStatus(403);
        $this->postJson("/api/v1/inventory/material-requests/{$request->id}/cancel", ['reason' => 'x'])->assertStatus(403);
    }

    public function test_raising_a_request_is_the_floors_act_and_the_store_alone_cannot_do_it(): void
    {
        $this->actingWith(['inventory.manage']);
        $request = $this->seededRequest();

        $this->postJson('/api/v1/inventory/material-requests', $this->payload())->assertStatus(403);
        $this->postJson("/api/v1/inventory/material-requests/{$request->id}/submit")->assertStatus(403);

        // But the store MAY hand a request back it cannot fulfil.
        $this->postJson("/api/v1/inventory/material-requests/{$request->id}/cancel", [
            'reason' => 'Out of stock — nothing left in the store',
        ])->assertOk();
    }

    public function test_production_manage_raises_submits_and_cancels(): void
    {
        $this->actingWith(['production.manage']);

        $id = $this->postJson('/api/v1/inventory/material-requests', $this->payload())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/inventory/material-requests/{$id}/submit")->assertOk();
        $this->postJson("/api/v1/inventory/material-requests/{$id}/cancel", ['reason' => 'run pulled'])->assertOk();
    }

    public function test_the_surface_carries_no_rate_no_amount_and_no_vendor(): void
    {
        $this->actingWith(['production.manage']);
        $id = $this->postJson('/api/v1/inventory/material-requests', $this->payload())->assertCreated()->json('data.id');

        $body = $this->getJson("/api/v1/inventory/material-requests/{$id}")->assertOk()->json();
        $flat = json_encode($body);

        foreach (['rate', 'amount', 'unit_cost', 'vendor', 'supplier', 'price'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $flat, "FC-06: the request surface must not print {$forbidden}");
        }
    }

    // ---- helpers -----------------------------------------------------------

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['lines' => [['item_id' => $this->carton->id, 'quantity' => '40']]];
    }

    private function seededRequest(): MaterialRequest
    {
        $request = MaterialRequest::create([
            'status' => MaterialRequestStatus::Draft,
            'requested_at' => now(),
        ]);
        $request->lines()->create(['item_id' => $this->carton->id, 'quantity' => '40', 'uom' => 'Nos']);

        return $request;
    }

    /** @param  list<string>  $permissions */
    private function actingWith(array $permissions): User
    {
        $this->app['auth']->forgetGuards();

        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }
}
