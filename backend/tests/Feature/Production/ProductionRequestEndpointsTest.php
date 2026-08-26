<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\ProductionRequestStatus;
use App\Modules\Production\Models\ProductionRequest;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Services\ProductionRequestService;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * /production/requests — THE FLOOR'S WORKLIST over the wire, and the
 * permission wall of a TWO-SIDED document (P3).
 *
 * The wall, said once:
 *
 *   READING the queue     EITHER desk — the STORE raised these and needs to
 *                         see what the floor is doing with them, the FLOOR
 *                         runs them. `module:production,inventory` is OR,
 *                         never AND.
 *   REORDERING it         the FLOOR alone (production.manage). The store
 *                         says WHAT is owed; it does not tell the factory
 *                         what to run first.
 *   STARTING one          the FLOOR alone. Nobody presses Start for somebody
 *                         standing at a machine.
 *   CANCELLING one        EITHER desk, with a reason: the store when the
 *                         customer walked away, the floor when it cannot run
 *                         the job.
 *
 * And the invariant underneath all of it: NOTHING HERE TOUCHES A BATCH
 * (invariant 2). `start` writes a status on a piece of paper.
 */
class ProductionRequestEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Item $jar;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos']);
        $this->jar = Item::create(['sku' => 'JAR-1L', 'name' => '1L PET Jar', 'uom' => 'Nos']);
        Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'tally_guid' => 'gd-fg']);
        $this->customer = Customer::create(['code' => 'CUST-1', 'name' => 'Aqua Traders']);
    }

    public function test_the_queue_reads_in_priority_order_with_the_order_behind_each_row(): void
    {
        $first = $this->request($this->bottle, '500');
        $second = $this->request($this->jar, '200');

        $this->actingWith(['production.view']);

        $this->getJson('/api/v1/production/requests')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.0.request_number', "PR-{$first->id}")
            ->assertJsonPath('data.0.priority', 1)
            ->assertJsonPath('data.0.status', 'queued')
            ->assertJsonPath('data.0.item.sku', 'BTL-500')
            ->assertJsonPath('data.0.quantity', '500.0000')
            ->assertJsonPath('data.0.sales_order.customer_name', 'Aqua Traders')
            ->assertJsonPath('data.0.can.start', true)
            ->assertJsonPath('data.0.can.cancel', true)
            ->assertJsonPath('data.0.can.reorder', true)
            ->assertJsonPath('data.1.id', $second->id)
            ->assertJsonPath('data.1.priority', 2);
    }

    public function test_a_finished_request_leaves_the_queue(): void
    {
        $request = $this->request($this->bottle, '500');
        app(ProductionRequestService::class)->cancel($request, 'customer walked away');

        $this->actingWith(['production.view']);

        $this->getJson('/api/v1/production/requests')->assertOk()->assertJsonCount(0, 'data');
    }

    // ---- reorder -----------------------------------------------------------

    public function test_the_floor_rewrites_the_whole_queues_order(): void
    {
        $first = $this->request($this->bottle, '500');
        $second = $this->request($this->jar, '200');

        $this->actingWith(['production.manage']);

        $this->postJson('/api/v1/production/requests/reorder', [
            'ordered_ids' => [$second->id, $first->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.0.priority', 1)
            ->assertJsonPath('data.1.id', $first->id)
            ->assertJsonPath('data.1.priority', 2);
    }

    /**
     * A PARTIAL LIST IS REFUSED. Renumbering a subset would leave the rows
     * it omitted carrying stale priorities against the ones it moved, and
     * two rows would claim the same place.
     */
    public function test_a_reorder_that_does_not_name_the_whole_queue_is_refused(): void
    {
        $first = $this->request($this->bottle, '500');
        $this->request($this->jar, '200');

        $this->actingWith(['production.manage']);

        $this->postJson('/api/v1/production/requests/reorder', ['ordered_ids' => [$first->id]])
            ->assertStatus(422);
    }

    public function test_an_empty_or_duplicated_order_is_refused_by_validation(): void
    {
        $request = $this->request($this->bottle, '500');

        $this->actingWith(['production.manage']);

        $this->postJson('/api/v1/production/requests/reorder', ['ordered_ids' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ordered_ids');
        $this->postJson('/api/v1/production/requests/reorder', ['ordered_ids' => [$request->id, $request->id]])
            ->assertStatus(422);
    }

    // ---- start -------------------------------------------------------------

    public function test_the_floor_picks_a_job_up_and_no_batch_is_created(): void
    {
        $request = $this->request($this->bottle, '500');

        $this->actingWith(['production.manage']);

        $this->postJson("/api/v1/production/requests/{$request->id}/start")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.can.start', false)
            ->assertJsonPath('data.can.cancel', true);

        // Invariant 2: a status on a piece of paper, nothing else.
        $this->assertSame(0, ShiftProductionEntry::query()->count());
    }

    public function test_a_job_already_picked_up_cannot_be_started_again(): void
    {
        $request = $this->request($this->bottle, '500');

        $this->actingWith(['production.manage']);

        $this->postJson("/api/v1/production/requests/{$request->id}/start")->assertOk();
        $this->postJson("/api/v1/production/requests/{$request->id}/start")->assertStatus(422);
    }

    // ---- cancel ------------------------------------------------------------

    public function test_the_floor_withdraws_a_job_it_cannot_run(): void
    {
        $request = $this->request($this->bottle, '500');

        $this->actingWith(['production.manage']);

        $this->postJson("/api/v1/production/requests/{$request->id}/cancel", ['reason' => 'mold is down all week'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelled_reason', 'mold is down all week')
            ->assertJsonPath('data.can.cancel', false);
    }

    public function test_the_store_withdraws_a_job_the_customer_no_longer_wants(): void
    {
        $request = $this->request($this->bottle, '500');

        $this->actingWith(['inventory.manage']);

        $this->postJson("/api/v1/production/requests/{$request->id}/cancel", ['reason' => 'customer cancelled the order'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_a_cancellation_without_a_reason_is_refused(): void
    {
        $request = $this->request($this->bottle, '500');

        $this->actingWith(['production.manage']);

        $this->postJson("/api/v1/production/requests/{$request->id}/cancel", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame(ProductionRequestStatus::Queued, $request->fresh()->status);
    }

    public function test_a_finished_request_cannot_be_cancelled_twice(): void
    {
        $request = $this->request($this->bottle, '500');

        $this->actingWith(['production.manage']);

        $this->postJson("/api/v1/production/requests/{$request->id}/cancel", ['reason' => 'first time'])->assertOk();
        $this->postJson("/api/v1/production/requests/{$request->id}/cancel", ['reason' => 'second time'])->assertStatus(422);
    }

    // ---- the OR-gate, both ways --------------------------------------------

    public function test_both_desks_read_the_queue(): void
    {
        $this->request($this->bottle, '500');

        $this->actingWith(['production.view']);
        $this->getJson('/api/v1/production/requests')->assertOk();

        $this->actingWith(['inventory.view']);
        $this->getJson('/api/v1/production/requests')->assertOk();
    }

    public function test_a_login_with_neither_module_sees_nothing(): void
    {
        $request = $this->request($this->bottle, '500');

        $this->actingWith(['sales.manage']);

        $this->getJson('/api/v1/production/requests')->assertStatus(403);
        $this->postJson('/api/v1/production/requests/reorder', ['ordered_ids' => [$request->id]])->assertStatus(403);
        $this->postJson("/api/v1/production/requests/{$request->id}/start")->assertStatus(403);
        $this->postJson("/api/v1/production/requests/{$request->id}/cancel", ['reason' => 'x'])->assertStatus(403);
    }

    public function test_a_view_only_login_on_either_side_can_never_write(): void
    {
        $request = $this->request($this->bottle, '500');

        $this->actingWith(['production.view', 'inventory.view']);

        $this->postJson('/api/v1/production/requests/reorder', ['ordered_ids' => [$request->id]])->assertStatus(403);
        $this->postJson("/api/v1/production/requests/{$request->id}/start")->assertStatus(403);
        $this->postJson("/api/v1/production/requests/{$request->id}/cancel", ['reason' => 'x'])->assertStatus(403);
    }

    /**
     * THE HALF OF THE OR-GATE THAT IS NOT SYMMETRIC. The store may cancel a
     * request — it raised the thing — but it may not tell the factory what
     * to run first, and it may not press Start for somebody at a machine.
     */
    public function test_the_store_may_cancel_but_may_not_order_the_queue_or_start_a_job(): void
    {
        $request = $this->request($this->bottle, '500');

        $this->actingWith(['inventory.manage']);

        $this->postJson('/api/v1/production/requests/reorder', ['ordered_ids' => [$request->id]])->assertStatus(403);
        $this->postJson("/api/v1/production/requests/{$request->id}/start")->assertStatus(403);
        $this->postJson("/api/v1/production/requests/{$request->id}/cancel", ['reason' => 'order cancelled'])->assertOk();
    }

    /** FC-06: the worklist is about pieces and priority, never money. */
    public function test_the_queue_carries_no_rate_no_cost_and_no_vendor(): void
    {
        $this->request($this->bottle, '500');

        $this->actingWith(['production.view']);

        $body = json_encode($this->getJson('/api/v1/production/requests')->assertOk()->json());

        foreach (['unit_price', 'rate', 'cost', 'amount', 'vendor', 'supplier'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "FC-06: the worklist must not print {$forbidden}");
        }
    }

    // ---- fixtures ----------------------------------------------------------

    private function request(Item $item, string $quantity): ProductionRequest
    {
        return app(ProductionRequestService::class)->createFromShortfall(
            $this->line($item, $quantity),
            $quantity,
            null,
        );
    }

    private function line(Item $item, string $quantity): SalesOrderLine
    {
        $order = SalesOrder::create([
            'customer_id' => $this->customer->id,
            'status' => SalesOrderStatus::Confirmed,
            'order_date' => '2026-08-20',
        ]);

        return $order->lines()->create([
            'item_id' => $item->id,
            'quantity' => $quantity,
            'unit_price' => '4.50',
            'quantity_delivered' => 0,
        ]);
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
