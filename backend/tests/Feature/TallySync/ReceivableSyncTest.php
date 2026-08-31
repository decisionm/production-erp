<?php

namespace Tests\Feature\TallySync;

use App\Models\User;
use App\Modules\Core\Services\AppSettingService;
use App\Modules\TallySync\Http\Controllers\TallySettingsController;
use App\Modules\TallySync\Models\TallyPendingSalesOrder;
use App\Modules\TallySync\Models\TallyReceivableBill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * THE AGENT'S RECEIVABLES PULL LANDS, AND REPLACES WHAT IT REPLACES.
 *
 * The inbound half of the CRM's client-outstanding page: the outstanding
 * position read out of Tally, written to two tables that nothing posts from.
 *
 * EVERY FIXTURE HERE IS SYNTHETIC. What a named client owes is Owner/Accounts
 * (FC-06); the shape is Tally's, the parties and numbers are invented.
 */
class ReceivableSyncTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = 'SYNTHETIC POLYMERS PVT LTD';

    private function actAsAgent(array $abilities = ['tally-sync:masters']): void
    {
        Sanctum::actingAs(User::factory()->create(['is_active' => true]), $abilities);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'company' => self::COMPANY,
            'as_of' => '2026-09-30',
            'bills' => [[
                'party_ledger_name' => 'Northwind Traders',
                'party_ledger_guid' => 'ledger-guid-northwind',
                'bill_reference' => 'INV-001',
                'bill_date' => '2026-08-01',
                'due_date' => '2026-09-01',
                'closing_amount' => 10000,
                'opening_amount' => 10000,
            ]],
            'orders' => [[
                'party_ledger_name' => 'Northwind Traders',
                'order_reference' => 'PO-4471',
                'order_date' => '2026-09-01',
                'stock_item_name' => 'ITEM_A',
                'pending_quantity' => 40,
                'quantity_unit' => 'Kgs.',
                'pending_amount' => 26960,
            ]],
        ], $overrides);
    }

    public function test_the_pull_lands(): void
    {
        $this->actAsAgent();

        $response = $this->postJson('/api/v1/tally-sync/receivables', $this->payload());

        $response->assertOk();
        $response->assertJsonPath('data.bills', 1);
        $response->assertJsonPath('data.orders', 1);
        $response->assertJsonPath('data.skipped_empty', false);

        $this->assertSame(1, TallyReceivableBill::query()->count());
        $this->assertSame('10000.0000', TallyReceivableBill::query()->first()->closing_amount);
    }

    public function test_a_pull_replaces_the_previous_position_rather_than_adding_to_it(): void
    {
        $this->actAsAgent();

        $this->postJson('/api/v1/tally-sync/receivables', $this->payload())->assertOk();

        // The bill was settled in Tally, so the next export simply does not
        // carry it — and a DIFFERENT bill is now outstanding.
        $second = $this->payload(['bills' => [[
            'party_ledger_name' => 'Northwind Traders',
            'bill_reference' => 'INV-002',
            'due_date' => '2026-09-15',
            'closing_amount' => 250,
        ]]]);

        $this->postJson('/api/v1/tally-sync/receivables', $second)->assertOk();

        // Upserting would leave INV-001 here for ever, still counted: an
        // outstanding total that only ever grows.
        $this->assertSame(1, TallyReceivableBill::query()->count());
        $this->assertSame('INV-002', TallyReceivableBill::query()->first()->bill_reference);
    }

    public function test_an_entirely_empty_pull_does_not_wipe_the_standing_position(): void
    {
        $this->actAsAgent();

        $this->postJson('/api/v1/tally-sync/receivables', $this->payload())->assertOk();

        $response = $this->postJson('/api/v1/tally-sync/receivables', $this->payload([
            'bills' => [],
            'orders' => [],
        ]));

        // "Tally answered with nothing" and "the factory is owed nothing" are
        // different facts. Wiping a real position on the strength of an answer
        // we may simply have failed to parse is the destructive form of the
        // bug #64 and #66 were both about — so it is refused, and said so.
        $response->assertOk();
        $response->assertJsonPath('data.skipped_empty', true);
        $this->assertSame(1, TallyReceivableBill::query()->count());
    }

    public function test_an_empty_pull_is_accepted_not_rejected(): void
    {
        $this->actAsAgent();

        // `present` not `required`: a 422 here would be logged on the factory
        // PC where nobody on this side can see it, and the ERP would look
        // exactly like one nobody has pressed the button on yet.
        $this->postJson('/api/v1/tally-sync/receivables', $this->payload([
            'bills' => [],
            'orders' => [],
        ]))->assertOk();
    }

    public function test_a_credit_note_keeps_its_negative_sign_through_the_wire(): void
    {
        $this->actAsAgent();

        $this->postJson('/api/v1/tally-sync/receivables', $this->payload(['bills' => [[
            'party_ledger_name' => 'Northwind Traders',
            'bill_reference' => 'CN-1',
            'closing_amount' => -2500,
        ]]]))->assertOk();

        // No `min:0` anywhere on this path, ever.
        $this->assertSame('-2500.0000', TallyReceivableBill::query()->first()->closing_amount);
    }

    public function test_a_pull_from_another_tally_company_is_refused(): void
    {
        $this->actAsAgent();

        // With no company bound in settings the guard cannot fire, so this
        // asserts the shape it protects: a bound ERP refuses a foreign pull
        // rather than filing one company's debtors against another's clients.
        $bound = app(AppSettingService::class);
        $bound->set(TallySettingsController::KEY_COMPANY, self::COMPANY);

        $this->postJson('/api/v1/tally-sync/receivables', $this->payload([
            'company' => 'SOME OTHER COMPANY LTD',
        ]))->assertStatus(409);

        $this->assertSame(0, TallyReceivableBill::query()->count());
    }

    public function test_a_token_without_the_masters_ability_is_refused(): void
    {
        $this->actAsAgent(['tally-sync:post']);

        $this->postJson('/api/v1/tally-sync/receivables', $this->payload())->assertStatus(403);

        $this->assertSame(0, TallyPendingSalesOrder::query()->count());
    }
}
