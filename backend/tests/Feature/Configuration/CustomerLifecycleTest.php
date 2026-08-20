<?php

namespace Tests\Feature\Configuration;

use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\Opportunity;
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
 *
 * A sixth check was added later and is not a foreign key at all. DEC-20260817-002
 * §5 asks whether the record was EVER used, and `opportunities.customer_id` is
 * editable with no history kept — so a current count of zero proves nothing
 * about the past, and the honest verdict is "cannot be verified", which blocks.
 * The three reassignment tests at the bottom pin both halves of that: it
 * refuses when an opportunity exists, and it does NOT refuse when none does,
 * which is the only state in which the past is knowable.
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

        // No opportunity has ever existed here, which is the one state in
        // which "never assigned to anyone" is provable — see the
        // reassignment tests below.
        $this->actingAs($owner)
            ->deleteJson("/api/v1/sales/customers/{$customer->id}")
            ->assertStatus(204);

        $this->assertNull(Customer::withTrashed()->find($customer->id));
        $this->customer('C-FREE');
        $this->assertSame(1, Customer::where('code', 'C-FREE')->count());
    }

    /**
     * The scenario the check exists for, end to end, as an operator meets it.
     *
     * DEC-20260817-002 §5 — "'ever used' means historical references ... not
     * merely current foreign keys", and where past use cannot be proven the
     * system refuses. `opportunities.customer_id` is editable
     * (UpdateOpportunityRequest), updated by a plain `$opportunity->update()`,
     * logged nowhere, and the row is never deleted. So an opportunity moved
     * from customer A to customer B leaves A with a current count of zero and
     * no trace whatsoever that it was ever A's. Counting the present would
     * call A unused and destroy it.
     *
     * BE CLEAR ABOUT WHAT THE ASSERTIONS CAN AND CANNOT SEE. They cannot tell
     * this customer's reassigned opportunity from any other opportunity — and
     * that is not a weak test, it is the finding: the fact that would
     * distinguish them is recorded nowhere, which is why the refusal cannot be
     * narrower. The next test pins that consequence directly. What this one
     * pins is the OUTCOME in the motivating scenario: a 422, no invented
     * count, and a sentence an operator can act on.
     */
    public function test_a_customer_whose_only_opportunity_was_reassigned_away_is_refused_rather_than_destroyed(): void
    {
        $owner = $this->ownerUser(...self::MODULE);
        $everReferenced = $this->customer('C-WAS-USED');
        $other = $this->customer('C-OTHER');

        $opportunity = Opportunity::create([
            'name' => 'A deal that started on C-WAS-USED',
            'customer_id' => $everReferenced->id,
        ]);

        // The reassignment. Nothing anywhere now records that this deal was
        // ever C-WAS-USED's.
        $opportunity->update(['customer_id' => $other->id]);
        $this->assertSame(
            0,
            Opportunity::where('customer_id', $everReferenced->id)->count(),
            'the premise: the present says this customer is unused',
        );

        $response = $this->actingAs($owner)->deleteJson("/api/v1/sales/customers/{$everReferenced->id}");

        $response->assertStatus(422);
        $this->assertSame(
            [],
            $response->json('blocking'),
            'nothing references this customer NOW, so there is no number to report — a refusal that invented '
            .'one would be worse than none',
        );
        $this->assertSame(
            [['code' => 'opportunity_reassignment', 'label' => 'an opportunity later reassigned to another customer']],
            $response->json('unprovable'),
        );
        $this->assertSame('archive', $response->json('alternative'));
        $this->assertStringContainsString(
            'past use of an opportunity later reassigned to another customer cannot be verified',
            $response->json('message'),
            'the operator has to be able to read WHY, not just that it failed',
        );

        $this->assertNotNull($everReferenced->fresh(), 'the customer survives');
    }

    /**
     * The refusal is exactly as narrow as the evidence. It is the EXISTENCE of
     * an opportunity that makes the past unknowable — with none, every
     * opportunity that ever existed is accounted for (the table is never
     * deleted from) and there is nothing left to be unsure about.
     *
     * This is why `test_an_unused_customer_is_really_deleted_and_the_code_is_freed`
     * above still passes: §1's hard delete and §2's code release survive for
     * the case that can be proven, and only that case.
     */
    public function test_the_reassignment_refusal_appears_only_once_an_opportunity_exists(): void
    {
        $owner = $this->ownerUser(...self::MODULE);
        $unrelated = $this->customer('C-UNRELATED');
        $host = $this->customer('C-HOST');

        $service = $this->service();
        $this->assertTrue(
            $service->dependencyReport($unrelated)->isClear(),
            'with no opportunity anywhere, nothing about the past is unknown',
        );

        Opportunity::create(['name' => 'Any deal at all', 'customer_id' => $host->id]);

        $this->assertFalse(
            $service->dependencyReport($unrelated)->isClear(),
            'one opportunity is enough: it could have been reassigned off ANY customer',
        );

        $this->actingAs($owner)
            ->deleteJson("/api/v1/sales/customers/{$unrelated->id}")
            ->assertStatus(422);
    }

    /** A current opportunity still reports its COUNT — the fail-closed verdict is added, not substituted. */
    public function test_a_current_opportunity_is_still_counted_as_well_as_unprovable(): void
    {
        $owner = $this->ownerUser(...self::MODULE);
        $customer = $this->customer('C-DEALING');

        Opportunity::create(['name' => 'An open deal', 'customer_id' => $customer->id]);

        $response = $this->actingAs($owner)->deleteJson("/api/v1/sales/customers/{$customer->id}");

        $response->assertStatus(422);
        $this->assertSame(
            ['opportunities'],
            collect($response->json('blocking'))->pluck('code')->all(),
        );
        $this->assertSame(1, $response->json('blocking.0.count'));
        $this->assertSame(
            ['opportunity_reassignment'],
            collect($response->json('unprovable'))->pluck('code')->all(),
        );
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
