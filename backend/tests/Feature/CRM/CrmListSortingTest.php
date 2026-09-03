<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Modules\CRM\Models\Enums\LeadStatus;
use App\Modules\CRM\Models\Enums\OpportunityStage;
use App\Modules\CRM\Models\Enums\QuotationStatus;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\Opportunity;
use App\Modules\CRM\Models\Quotation;
use App\Modules\Sales\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Leads, opportunities and quotations sort on the SERVER (03-Sep-2026):
 * `sort` is validated at the door, a named column orders the whole set
 * with `id desc` as the tiebreak, an undated expected close sorts last in
 * either direction, and `per_page` pages with the real total. Every party
 * below is synthetic.
 */
class CrmListSortingTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('crm.view', 'web');
        $user->givePermissionTo('crm.view');
        Sanctum::actingAs($user);

        $this->customer = Customer::create(['code' => 'CUST-SYN', 'name' => 'Synthetic Buyer', 'is_active' => true]);
    }

    private function lead(string $name, ?string $company = null): Lead
    {
        return Lead::create(['name' => $name, 'company' => $company, 'status' => LeadStatus::New]);
    }

    private function opportunity(string $name, string $value, ?string $close = null): Opportunity
    {
        return Opportunity::create([
            'name' => $name,
            'customer_id' => $this->customer->id,
            'stage' => OpportunityStage::Prospecting,
            'estimated_value' => $value,
            'probability' => '50.00',
            'expected_close_date' => $close,
        ]);
    }

    private function quotation(Opportunity $opportunity, string $date, QuotationStatus $status = QuotationStatus::Draft): Quotation
    {
        return Quotation::create([
            'opportunity_id' => $opportunity->id,
            'customer_id' => $this->customer->id,
            'status' => $status,
            'quotation_date' => $date,
        ]);
    }

    /** @return list<int> */
    private function ids(string $url): array
    {
        return array_map(fn (array $row) => $row['id'], $this->getJson($url)->assertOk()->json('data'));
    }

    // ---- leads ------------------------------------------------------------

    public function test_leads_refuse_a_sort_column_they_do_not_have(): void
    {
        $this->getJson('/api/v1/crm/leads?sort=nonsense')->assertStatus(422)->assertJsonValidationErrors('sort');
        // Last contact lives on the activity, not the lead: not a column here.
        $this->getJson('/api/v1/crm/leads?sort=last_contact')->assertStatus(422)->assertJsonValidationErrors('sort');
    }

    public function test_leads_sort_descending_on_company_with_newest_id_breaking_the_tie(): void
    {
        $zeta = $this->lead('One', 'Zeta Plastics');
        $alphaOld = $this->lead('Two', 'Alpha Packers');
        $alphaNew = $this->lead('Three', 'Alpha Packers');

        $this->assertSame([$zeta->id, $alphaNew->id, $alphaOld->id], $this->ids('/api/v1/crm/leads?sort=-company'));
        // The default is still newest first.
        $this->assertSame([$alphaNew->id, $alphaOld->id, $zeta->id], $this->ids('/api/v1/crm/leads'));
    }

    public function test_leads_page_with_the_real_total(): void
    {
        $this->lead('One');
        $this->lead('Two');
        $this->lead('Three');

        $this->getJson('/api/v1/crm/leads?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);
    }

    // ---- opportunities ----------------------------------------------------

    public function test_opportunities_refuse_a_sort_column_they_do_not_have(): void
    {
        $this->getJson('/api/v1/crm/opportunities?sort=nonsense')->assertStatus(422)->assertJsonValidationErrors('sort');
    }

    public function test_opportunities_sort_descending_on_value_with_newest_id_breaking_the_tie(): void
    {
        $big = $this->opportunity('Big', '900000.0000');
        $smallOld = $this->opportunity('Small A', '1000.0000');
        $smallNew = $this->opportunity('Small B', '1000.0000');

        $this->assertSame([$big->id, $smallNew->id, $smallOld->id], $this->ids('/api/v1/crm/opportunities?sort=-estimated_value'));
    }

    public function test_an_undated_expected_close_sorts_last_in_either_direction(): void
    {
        $undated = $this->opportunity('Undated', '1.0000', null);
        $march = $this->opportunity('March', '1.0000', '2026-03-31');
        $january = $this->opportunity('January', '1.0000', '2026-01-31');

        $this->assertSame([$january->id, $march->id, $undated->id], $this->ids('/api/v1/crm/opportunities?sort=expected_close_date'));
        $this->assertSame([$march->id, $january->id, $undated->id], $this->ids('/api/v1/crm/opportunities?sort=-expected_close_date'));
    }

    public function test_opportunities_page_with_the_real_total(): void
    {
        $this->opportunity('A', '1.0000');
        $this->opportunity('B', '2.0000');
        $this->opportunity('C', '3.0000');

        $this->getJson('/api/v1/crm/opportunities?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);
    }

    // ---- quotations -------------------------------------------------------

    public function test_quotations_refuse_a_sort_column_they_do_not_have(): void
    {
        $this->getJson('/api/v1/crm/quotations?sort=nonsense')->assertStatus(422)->assertJsonValidationErrors('sort');
    }

    public function test_quotations_sort_descending_on_date_with_newest_id_breaking_the_tie(): void
    {
        $opportunity = $this->opportunity('Deal', '1.0000');
        $march = $this->quotation($opportunity, '2026-03-01');
        $januaryOld = $this->quotation($opportunity, '2026-01-05');
        $januaryNew = $this->quotation($opportunity, '2026-01-05');

        $this->assertSame([$march->id, $januaryNew->id, $januaryOld->id], $this->ids('/api/v1/crm/quotations?sort=-quotation_date'));
        // The default is still newest first.
        $this->assertSame([$januaryNew->id, $januaryOld->id, $march->id], $this->ids('/api/v1/crm/quotations'));
    }

    public function test_quotations_page_with_the_real_total(): void
    {
        $opportunity = $this->opportunity('Deal', '1.0000');
        $this->quotation($opportunity, '2026-01-01');
        $this->quotation($opportunity, '2026-01-02');
        $this->quotation($opportunity, '2026-01-03');

        $this->getJson('/api/v1/crm/quotations?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);
    }
}
