<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\ProductionRequestStatus;
use App\Modules\Production\Models\ProductionRequest;
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
 * GET /production/requests?status[]=... — THE LOOK-BACK READ.
 *
 * DEC-20260902-032 leaves a produced request out of the queue; the owner
 * asked on 03-Sep-2026 to be able to LOOK at those rows. Reading is not
 * retiring: nothing here starts, cancels or completes anything, and the
 * default (no `status` given) is the same open queue as before — this test
 * class's fixtures follow ProductionRequestEndpointsTest's, the existing
 * wire-level test for this same controller.
 */
class ProductionRequestHistoryTest extends TestCase
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

    public function test_the_default_index_still_returns_only_open_requests(): void
    {
        $queued = $this->request($this->bottle, '500');
        $inProgress = $this->request($this->jar, '200');
        app(ProductionRequestService::class)->start($inProgress);

        $produced = $this->request($this->bottle, '50');
        $produced->update(['status' => ProductionRequestStatus::Produced]);

        $cancelled = $this->request($this->jar, '20');
        app(ProductionRequestService::class)->cancel($cancelled, 'customer walked away');

        $this->actingWith(['production.view']);

        $response = $this->getJson('/api/v1/production/requests')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$queued->id, $inProgress->id], $ids);
    }

    public function test_a_status_filter_returns_produced_and_cancelled_rows(): void
    {
        $this->request($this->bottle, '500'); // queued — must not appear

        $produced = $this->request($this->bottle, '50');
        $produced->update(['status' => ProductionRequestStatus::Produced]);

        $cancelled = $this->request($this->jar, '20');
        app(ProductionRequestService::class)->cancel($cancelled, 'customer walked away');

        $this->actingWith(['production.view']);

        $this->getJson('/api/v1/production/requests?'.http_build_query(['status' => ['produced', 'cancelled']], '', '&', PHP_QUERY_RFC3986))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $cancelled->id)
            ->assertJsonPath('data.1.id', $produced->id);
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $this->actingWith(['production.view']);

        $this->getJson('/api/v1/production/requests?status[]=finished')
            ->assertStatus(422)
            ->assertJsonValidationErrors('status.0');
    }

    /**
     * The 28-Aug standing rule: every list ships with server-side search AND
     * a real pager. `withStatuses()` used to be a bare `->get()` — ticking
     * all four statuses returned every production request the factory had
     * ever raised, unpaginated. Search is not part of this fix.
     */
    public function test_the_look_back_read_is_paginated(): void
    {
        $first = $this->request($this->bottle, '10');
        $first->update(['status' => ProductionRequestStatus::Produced]);
        $second = $this->request($this->bottle, '20');
        $second->update(['status' => ProductionRequestStatus::Produced]);
        $third = $this->request($this->bottle, '30');
        $third->update(['status' => ProductionRequestStatus::Produced]);

        $this->actingWith(['production.view']);

        $response = $this->getJson('/api/v1/production/requests?status[]=produced&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2);

        // Newest first — the third and second requests are page one, not the first.
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$third->id, $second->id], $ids);

        $this->getJson('/api/v1/production/requests?status[]=produced&per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $first->id);
    }

    public function test_the_look_back_read_defaults_to_a_page_of_twenty_five(): void
    {
        $produced = $this->request($this->bottle, '10');
        $produced->update(['status' => ProductionRequestStatus::Produced]);

        $this->actingWith(['production.view']);

        $this->getJson('/api/v1/production/requests?status[]=produced')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.current_page', 1);
    }

    public function test_a_per_page_above_the_ceiling_is_refused(): void
    {
        $this->actingWith(['production.view']);

        $this->getJson('/api/v1/production/requests?status[]=produced&per_page=101')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);
    }

    /**
     * The DEFAULT view (no `status` given) is untouched: still the open
     * queue, still unpaginated — reorder() renumbers the WHOLE queue, and a
     * page of it would let somebody reorder a queue they cannot see all of.
     */
    public function test_the_default_queue_stays_unpaginated(): void
    {
        $this->request($this->bottle, '500');
        $this->request($this->jar, '200');

        $this->actingWith(['production.view']);

        $this->getJson('/api/v1/production/requests')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('meta');
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
