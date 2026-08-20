<?php

namespace Tests\Feature\Configuration;

use App\Modules\CRM\Models\Lead;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\CustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * THE CUSTOMER MASTER under the Configuration Lifecycle Contract
 * (DEC-20260817-002) — Create · View · Edit · Activate/Deactivate ·
 * Safe Delete · Audit.
 *
 * Customer is the one of the last two masters that actually needed the
 * declaration rather than merely benefiting from it. Four of its five inbound
 * keys are RESTRICT, which the database would refuse on its own. The fifth,
 * `leads.converted_customer_id`, is SET NULL: the database would let the
 * delete SUCCEED and quietly blank which customer a lead became. No
 * foreign-key error, and no schema backstop either — SchemaCascades reads
 * only DELETE_RULE='CASCADE'. The declaration in CustomerService is the whole
 * guard, so the test below is the thing that proves it exists.
 */
class CustomerLifecycleTest extends ProductDefinitionLifecycleTestCase
{
    use RefreshDatabase;

    private const MODULE = ['sales.view', 'sales.manage'];

    private function customer(string $code = 'C-1', array $overrides = []): Customer
    {
        return Customer::create([
            'code' => $code,
            'name' => 'Customer '.$code,
            ...$overrides,
        ]);
    }

    private function service(): CustomerService
    {
        return app(CustomerService::class);
    }

    public function test_archive_takes_a_customer_out_of_service_and_activate_puts_it_back(): void
    {
        $manager = $this->moduleUser(...self::MODULE);
        $customer = $this->customer('C-CYCLE');

        $this->actingAs($manager)
            ->postJson("/api/v1/sales/customers/{$customer->id}/archive", ['reason' => 'Account closed'])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($manager)
            ->postJson("/api/v1/sales/customers/{$customer->id}/activate", ['reason' => 'Trading again'])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_the_hard_delete_is_refused_for_a_module_user_without_the_owner_tier(): void
    {
        $manager = $this->moduleUser(...self::MODULE);
        $customer = $this->customer('C-TIER');

        $this->actingAs($manager)
            ->deleteJson("/api/v1/sales/customers/{$customer->id}")
            ->assertStatus(403);

        $this->assertNotNull($customer->fresh(), 'the customer survives a refused delete');
    }

    public function test_a_customer_with_a_sales_order_is_refused_with_counts(): void
    {
        $owner = $this->ownerUser(...self::MODULE);
        $customer = $this->customer('C-USED');

        SalesOrder::create([
            'customer_id' => $customer->id,
            'order_date' => '2026-08-20',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($owner)->deleteJson("/api/v1/sales/customers/{$customer->id}");

        $response->assertStatus(422);
        $this->assertSame(
            ['sales_orders'],
            collect($response->json('blocking'))->pluck('code')->all(),
        );
        $this->assertSame(1, $response->json('blocking.0.count'));
    }

    public function test_a_set_null_reference_blocks_the_delete_too(): void
    {
        $owner = $this->ownerUser(...self::MODULE);
        $customer = $this->customer('C-CONVERTED');

        Lead::create([
            'name' => 'A lead that became this customer',
            'status' => 'converted',
            'converted_customer_id' => $customer->id,
        ]);

        $response = $this->actingAs($owner)->deleteJson("/api/v1/sales/customers/{$customer->id}");

        // THE POINT OF THIS FILE. `leads.converted_customer_id` is
        // nullOnDelete: without the declaration the database would accept
        // this delete and silently blank the column, so the lead would stop
        // saying which customer it became — with no error anywhere to notice.
        $response->assertStatus(422);
        $this->assertSame(
            ['leads'],
            collect($response->json('blocking'))->pluck('code')->all(),
        );
        $this->assertSame(1, $response->json('blocking.0.count'));

        $this->assertNotNull($customer->fresh(), 'the customer survives');
        $this->assertSame(
            $customer->id,
            Lead::first()->converted_customer_id,
            'the lead still knows which customer it became',
        );
    }

    public function test_an_unused_customer_is_really_deleted_and_the_code_is_freed(): void
    {
        $owner = $this->ownerUser(...self::MODULE);
        $customer = $this->customer('C-FREE');

        $this->actingAs($owner)
            ->deleteJson("/api/v1/sales/customers/{$customer->id}")
            ->assertStatus(204);

        $this->assertNull(Customer::withTrashed()->find($customer->id));
        $this->customer('C-FREE');
        $this->assertSame(1, Customer::where('code', 'C-FREE')->count());
    }

    public function test_an_archived_customer_keeps_its_code_reserved(): void
    {
        $manager = $this->moduleUser(...self::MODULE);
        $customer = $this->customer('C-RESERVED');

        $this->actingAs($manager)
            ->postJson("/api/v1/sales/customers/{$customer->id}/archive", ['reason' => 'Dormant'])
            ->assertOk();

        $this->actingAs($manager)
            ->postJson('/api/v1/sales/customers', ['code' => 'C-RESERVED', 'name' => 'Impostor'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }
}
