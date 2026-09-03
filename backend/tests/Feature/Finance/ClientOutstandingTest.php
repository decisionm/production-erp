<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Modules\Core\Services\AppSettingService;
use App\Modules\Finance\Services\ClientOutstandingService;
use App\Modules\Sales\Models\Customer;
use App\Modules\TallySync\Http\Controllers\TallySettingsController;
use App\Modules\TallySync\Models\TallyPendingSalesOrder;
use App\Modules\TallySync\Models\TallyReceivableBill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * WHAT EACH CLIENT OWES, HOW LATE IT IS, AND WHAT IS STILL TO SHIP THEM.
 *
 * EVERY FIXTURE HERE IS SYNTHETIC. What a named client owes the factory is
 * Owner/Accounts (FC-06); the SHAPE is Tally's Bills Receivable and Sales
 * Order Outstanding, the parties and the numbers are invented.
 *
 * The ageing assertions pin a FIXED today, because a test that computes the
 * expected bucket the same way the service does would agree with any bug in
 * the arithmetic. The dates below are chosen so each bill sits one day inside
 * its bucket's boundary.
 */
class ClientOutstandingTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = 'SYNTHETIC POLYMERS PVT LTD';

    /** The day every assertion in this file is asked about. */
    private const TODAY = '2026-09-30';

    private function bill(array $overrides = []): void
    {
        TallyReceivableBill::query()->create(array_merge([
            'party_ledger_name' => 'Northwind Traders',
            'party_ledger_guid' => 'ledger-guid-northwind',
            'bill_reference' => 'INV-001',
            'bill_date' => '2026-08-01',
            'due_date' => '2026-09-01',
            'closing_amount' => '10000.0000',
            'opening_amount' => '10000.0000',
            'as_of' => self::TODAY,
            'tally_company' => self::COMPANY,
            'tally_synced_at' => Carbon::parse(self::TODAY.' 09:00:00'),
        ], $overrides));
    }

    private function report(): array
    {
        return app(ClientOutstandingService::class)->report(Carbon::parse(self::TODAY));
    }

    private function clientNamed(array $report, string $ledger): array
    {
        foreach ($report['clients'] as $client) {
            if ($client['party_ledger_name'] === $ledger) {
                return $client;
            }
        }

        $this->fail("No client row for ledger '{$ledger}'.");
    }

    public function test_a_bill_lands_in_the_bucket_for_its_days_past_due(): void
    {
        // 29 days past due on the fixed today — inside 1-30.
        $this->bill(['bill_reference' => 'INV-29', 'due_date' => '2026-09-01', 'closing_amount' => '100.0000']);
        // 60 days past due — the upper edge of 31-60, not 61-90.
        $this->bill(['bill_reference' => 'INV-60', 'due_date' => '2026-08-01', 'closing_amount' => '200.0000']);
        // 91 days past due — over the edge into 90+.
        $this->bill(['bill_reference' => 'INV-91', 'due_date' => '2026-07-01', 'closing_amount' => '400.0000']);
        // Not due for another month — "current", never counted as overdue.
        $this->bill(['bill_reference' => 'INV-FUT', 'due_date' => '2026-10-31', 'closing_amount' => '800.0000']);

        $client = $this->clientNamed($this->report(), 'Northwind Traders');

        $this->assertSame('100.0000', $client['ageing']['d1_30']);
        $this->assertSame('200.0000', $client['ageing']['d31_60']);
        $this->assertSame('0.0000', $client['ageing']['d61_90']);
        $this->assertSame('400.0000', $client['ageing']['d90_plus']);
        $this->assertSame('800.0000', $client['ageing']['current']);

        // The outstanding total is every bill; the overdue total excludes the
        // one that is not due yet.
        $this->assertSame('1500.0000', $client['outstanding_amount']);
        $this->assertSame('700.0000', $client['overdue_amount']);
        $this->assertSame(91, $client['oldest_overdue_days']);
    }

    public function test_a_bill_with_no_due_date_is_its_own_bucket_and_is_not_overdue(): void
    {
        $this->bill(['bill_reference' => 'ON-ACCOUNT', 'due_date' => null, 'closing_amount' => '500.0000']);

        $client = $this->clientNamed($this->report(), 'Northwind Traders');

        // Not folded into "current" (which would claim a term the factory
        // never set) and not into 90+ (which would invent a debt that is late).
        $this->assertSame('500.0000', $client['ageing']['no_due_date']);
        $this->assertSame('0.0000', $client['ageing']['current']);
        $this->assertSame('0.0000', $client['ageing']['d90_plus']);
        $this->assertSame('0.0000', $client['overdue_amount']);
        $this->assertNull($client['oldest_overdue_days']);

        // The column the page reads for "Outstanding days" says nothing,
        // rather than 0 — which would mean "due today".
        $this->assertNull($client['bills'][0]['days_past_due']);
    }

    public function test_a_party_closing_balance_is_not_misreported_as_one_bill(): void
    {
        // This is the measured shape of the factory's all-parties Tally
        // export: a client closing balance without a bill reference or dates.
        $this->bill([
            'bill_reference' => null,
            'bill_date' => null,
            'due_date' => null,
            'closing_amount' => '7500.0000',
            'opening_amount' => null,
        ]);

        $client = $this->clientNamed($this->report(), 'Northwind Traders');

        $this->assertSame('7500.0000', $client['outstanding_amount']);
        $this->assertTrue($client['balance_only']);
        $this->assertSame(0, $client['bill_count']);
        $this->assertSame([], $client['bills']);
        $this->assertSame('7500.0000', $client['ageing']['no_due_date']);
    }

    public function test_a_client_in_credit_keeps_its_negative_sign(): void
    {
        $this->bill(['bill_reference' => 'INV-1', 'closing_amount' => '1000.0000']);
        $this->bill(['bill_reference' => 'CN-1', 'closing_amount' => '-2500.0000']);

        $client = $this->clientNamed($this->report(), 'Northwind Traders');

        // Flipping the sign, or dropping the credit note, would turn a client
        // the factory OWES into one of its largest debtors.
        $this->assertSame('-1500.0000', $client['outstanding_amount']);
    }

    public function test_an_unlinked_tally_ledger_still_appears_under_its_own_name(): void
    {
        $this->bill(['party_ledger_name' => 'Unlinked Party', 'party_ledger_guid' => 'ledger-guid-unlinked']);

        $client = $this->clientNamed($this->report(), 'Unlinked Party');

        $this->assertFalse($client['is_linked']);
        $this->assertNull($client['customer_id']);
        $this->assertSame('10000.0000', $client['outstanding_amount']);

        // The row still carries the address FIELD — the key is asserted
        // separately from its value, because reading a key that was never
        // written evaluates to null and would pass the null check alone.
        // Every row on this instance is this row today: 135 Tally parties,
        // none linked. The follow-up draft is composed for all of them and
        // simply has no recipient to fill in yet.
        $this->assertArrayHasKey('customer_email', $client);
        $this->assertNull($client['customer_email']);
    }

    public function test_a_linked_customer_is_resolved_by_its_recorded_ledger_guid(): void
    {
        // `tally_ledger_guid` / `tally_ledger_name` are deliberately NOT in
        // Customer's Fillable — only the ledger import writes them — so they
        // are set explicitly here rather than mass-assigned, which would drop
        // them silently and make this test pass for the wrong reason.
        $customer = Customer::query()->create([
            'code' => 'TL-9',
            'name' => 'Northwind Traders Pvt Ltd',
            'email' => 'accounts@northwind.example',
            'is_active' => true,
        ]);
        $customer->forceFill([
            'tally_ledger_guid' => 'ledger-guid-northwind',
            'tally_ledger_name' => 'Northwind Traders',
        ])->save();

        $this->bill();

        $client = $this->clientNamed($this->report(), 'Northwind Traders');

        $this->assertTrue($client['is_linked']);
        $this->assertSame($customer->id, $client['customer_id']);
        $this->assertSame('Northwind Traders Pvt Ltd', $client['customer_name']);

        // The positive half. Asserting only that an unlinked row is null
        // would pass against a field hardcoded to null and never resolved at
        // all — this is what proves the address actually comes off the linked
        // customer, and what a follow-up draft will address itself with once
        // the ledgers are matched.
        $this->assertSame('accounts@northwind.example', $client['customer_email']);
    }

    public function test_a_linked_customer_with_a_blank_email_reports_no_address(): void
    {
        $customer = Customer::query()->create([
            'code' => 'TL-10',
            'name' => 'Blank Address Ltd',
            'email' => '   ',
            'is_active' => true,
        ]);
        $customer->forceFill([
            'tally_ledger_guid' => 'ledger-guid-northwind',
            'tally_ledger_name' => 'Northwind Traders',
        ])->save();

        $this->bill();

        $client = $this->clientNamed($this->report(), 'Northwind Traders');

        // The customer IS linked, so the row keeps the link. What it must not
        // do is hand out whitespace as an address: a reader that only tests
        // for emptiness would treat "   " as a real recipient and compose a
        // draft addressed to nothing. One thing to test — null or an address.
        $this->assertTrue($client['is_linked']);
        $this->assertSame($customer->id, $client['customer_id']);
        $this->assertNull($client['customer_email']);
    }

    public function test_pending_orders_are_summed_and_valueless_lines_are_counted_not_invented(): void
    {
        TallyPendingSalesOrder::query()->create([
            'party_ledger_name' => 'Northwind Traders',
            'party_ledger_guid' => 'ledger-guid-northwind',
            'order_reference' => 'PO-4471',
            'order_date' => '2026-09-01',
            'pending_quantity' => '40.0000',
            'quantity_unit' => 'Kgs.',
            'pending_amount' => '26960.0000',
            'as_of' => self::TODAY,
            'tally_company' => self::COMPANY,
            'tally_synced_at' => Carbon::parse(self::TODAY.' 09:00:00'),
        ]);

        // A real pending line Tally priced no value for.
        TallyPendingSalesOrder::query()->create([
            'party_ledger_name' => 'Northwind Traders',
            'party_ledger_guid' => 'ledger-guid-northwind',
            'order_reference' => 'PO-4472',
            'pending_quantity' => '10.0000',
            'pending_amount' => null,
            'as_of' => self::TODAY,
            'tally_company' => self::COMPANY,
            'tally_synced_at' => Carbon::parse(self::TODAY.' 09:00:00'),
        ]);

        $client = $this->clientNamed($this->report(), 'Northwind Traders');

        $this->assertSame('26960.0000', $client['pending_order_amount']);
        $this->assertSame(2, $client['pending_order_count']);
        // Counted and flagged, never given a made-up value.
        $this->assertSame(1, $client['pending_orders_without_value']);
    }

    public function test_a_client_with_only_a_pending_order_still_gets_a_row(): void
    {
        TallyPendingSalesOrder::query()->create([
            'party_ledger_name' => 'Orders Only Ltd',
            'order_reference' => 'PO-1',
            'pending_amount' => '5000.0000',
            'as_of' => self::TODAY,
            'tally_company' => self::COMPANY,
            'tally_synced_at' => Carbon::parse(self::TODAY.' 09:00:00'),
        ]);

        $client = $this->clientNamed($this->report(), 'Orders Only Ltd');

        $this->assertSame('5000.0000', $client['pending_order_amount']);
        $this->assertSame('0.0000', $client['outstanding_amount']);
    }

    public function test_the_endpoint_returns_the_position_with_its_as_at_date(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        // The route group is gated by `module:finance`; a GET needs
        // finance.view. NOT crm.view: this is the factory's debtor book, and
        // it sits behind the same gate as reports/receivables beside it.
        Permission::findOrCreate('finance.view', 'web');
        $user->givePermissionTo('finance.view');
        Sanctum::actingAs($user);

        $this->bill();

        $response = $this->getJson('/api/v1/finance/client-outstanding');

        $response->assertOk();
        $response->assertJsonPath('data.as_of', self::TODAY);
        $response->assertJsonPath('data.company', self::COMPANY);
        $response->assertJsonPath('data.totals.clients', 1);
        $response->assertJsonPath('data.totals.outstanding_amount', '10000.0000');
    }

    public function test_only_the_bound_company_is_reported(): void
    {
        app(AppSettingService::class)
            ->set(TallySettingsController::KEY_COMPANY, self::COMPANY);

        $this->bill(['party_ledger_name' => 'Ours Ltd', 'party_ledger_guid' => 'g-ours', 'closing_amount' => '1000.0000']);
        $this->bill([
            'party_ledger_name' => 'Other Company Client',
            'party_ledger_guid' => 'g-other',
            'closing_amount' => '9999.0000',
            'tally_company' => 'A DIFFERENT COMPANY LTD',
        ]);

        $report = $this->report();

        // The SYNC replaces one company's rows, and the agent's 409 guard can
        // only refuse a foreign pull once a company is bound — so rows from
        // two companies can coexist. Summing both into one total and labelling
        // it with whichever row came back first is the bug this pins.
        $this->assertSame(1, $report['totals']['clients']);
        $this->assertSame('1000.0000', $report['totals']['outstanding_amount']);
    }

    public function test_the_crm_gate_no_longer_opens_the_debtor_book(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('crm.view', 'web');
        Permission::findOrCreate('crm.manage', 'web');
        $user->givePermissionTo('crm.view');
        $user->givePermissionTo('crm.manage');
        Sanctum::actingAs($user);

        $this->bill();

        // THIS IS WHAT THE MOVE WAS FOR. Asserting only that finance.view
        // WORKS would still pass if the route were left behind both gates, or
        // moved back under `crm` — so the refusal is asserted directly, with
        // the CRM permissions at their strongest. Whoever works leads must not
        // be able to read what every client owes.
        $this->getJson('/api/v1/finance/client-outstanding')->assertStatus(403);
    }

    public function test_totals_agree_with_the_rows_beneath_them(): void
    {
        $this->bill(['party_ledger_name' => 'A Ltd', 'party_ledger_guid' => 'g-a', 'closing_amount' => '1000.0000']);
        $this->bill(['party_ledger_name' => 'B Ltd', 'party_ledger_guid' => 'g-b', 'closing_amount' => '2500.0000']);

        $report = $this->report();

        // The header must sum exactly the set the table shows — the reason the
        // clients are not paginated.
        $summed = '0.0000';
        foreach ($report['clients'] as $client) {
            $summed = bcadd($summed, $client['outstanding_amount'], 4);
        }

        $this->assertSame($report['totals']['outstanding_amount'], $summed);
        $this->assertSame(2, $report['totals']['clients']);
    }
}
