<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Sales\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * WHICH TALLY LEDGER A CUSTOMER IS, as the API reports it.
 *
 * The two columns are written by `sales:import-customers-from-ledgers` and by
 * nothing else (that command's own test pins the writing). What this test pins
 * is the READ side and the refusal:
 *
 *  · the customer payload carries `tally_ledger_guid` / `tally_ledger_name`,
 *    null on a customer nobody has linked — so the screen can say "posts as
 *    {name}" or "no Tally ledger" instead of guessing;
 *  · neither the create nor the update request can set them. A posting
 *    identity is imported from Tally, never typed on a form, and a request
 *    that could re-point a customer at another party's ledger is exactly the
 *    hole the non-fillable columns exist to close.
 *
 * FC-06 is not in play: this is a CUSTOMER ledger — no supplier identity and
 * no rate of any kind rides in this payload.
 */
class CustomerTallyLedgerLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $salesDesk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->salesDesk = $this->userWith('Sales Desk', ['sales.view', 'sales.manage']);
        Sanctum::actingAs($this->salesDesk);
    }

    /** @param  list<string>  $permissions */
    private function userWith(string $name, array $permissions): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    public function test_the_customer_payload_carries_the_ledger_link(): void
    {
        $customer = Customer::create(['code' => 'TL-7', 'name' => 'Acme Pharma', 'is_active' => true]);
        $customer->forceFill([
            'tally_ledger_guid' => 'guid-acme',
            'tally_ledger_name' => 'Acme Pharma',
        ])->save();

        $this->getJson("/api/v1/sales/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.tally_ledger_guid', 'guid-acme')
            ->assertJsonPath('data.tally_ledger_name', 'Acme Pharma');
    }

    public function test_an_unlinked_customer_says_so_rather_than_omitting_the_keys(): void
    {
        // Both keys present and null: "nobody has linked this one" is a state
        // the screen must be able to show, and a missing key is not one.
        $customer = Customer::create(['code' => 'CUS-1', 'name' => 'Walk-in Trader', 'is_active' => true]);

        $this->getJson("/api/v1/sales/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.tally_ledger_guid', null)
            ->assertJsonPath('data.tally_ledger_name', null);
    }

    public function test_the_create_form_cannot_set_a_ledger_link(): void
    {
        $this->postJson('/api/v1/sales/customers', [
            'code' => 'CUS-NEW',
            'name' => 'New Trader',
            'tally_ledger_guid' => 'guid-someone-else',
            'tally_ledger_name' => 'Someone Else',
        ])
            ->assertSuccessful()
            ->assertJsonPath('data.tally_ledger_guid', null)
            ->assertJsonPath('data.tally_ledger_name', null);

        $this->assertNull(Customer::where('code', 'CUS-NEW')->sole()->tally_ledger_guid);
    }

    public function test_an_edit_cannot_re_point_an_imported_customer_at_another_ledger(): void
    {
        $customer = Customer::create(['code' => 'TL-7', 'name' => 'Acme Pharma', 'is_active' => true]);
        $customer->forceFill([
            'tally_ledger_guid' => 'guid-acme',
            'tally_ledger_name' => 'Acme Pharma',
        ])->save();

        $this->putJson("/api/v1/sales/customers/{$customer->id}", [
            'name' => 'Acme Pharma',
            'tally_ledger_guid' => 'guid-rival',
            'tally_ledger_name' => 'Rival Pharma',
        ])->assertSuccessful();

        $customer->refresh();
        $this->assertSame('guid-acme', $customer->tally_ledger_guid);
        $this->assertSame('Acme Pharma', $customer->tally_ledger_name);
    }
}
