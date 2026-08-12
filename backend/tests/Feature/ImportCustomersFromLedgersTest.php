<?php

namespace Tests\Feature;

use App\Modules\Sales\Models\Customer;
use App\Modules\TallySync\Models\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The customer import reads pulled Tally ledgers and creates Sales customers.
 * What is tested here is mostly what it REFUSES to do: select by anything
 * other than a named group, write without being asked, touch a row a person
 * already owns, or invent a field a ledger does not carry.
 */
class ImportCustomersFromLedgersTest extends TestCase
{
    use RefreshDatabase;

    private function ledger(string $name, ?string $group): Ledger
    {
        return Ledger::create([
            'tally_guid' => 'guid-'.str_replace(' ', '-', strtolower($name)),
            'name' => $name,
            'tally_group_name' => $group,
            'ledger_group_id' => null,
        ]);
    }

    public function test_it_writes_nothing_without_groups_and_lists_what_is_available(): void
    {
        $this->ledger('Acme Pharma', 'Sundry Debtors');
        $this->ledger('Chennai Agency', 'Chennai');

        $this->artisan('sales:import-customers-from-ledgers')
            ->expectsOutputToContain('Sundry Debtors')
            ->expectsOutputToContain('Chennai')
            ->expectsOutputToContain('No --groups given')
            ->assertSuccessful();

        $this->assertSame(0, Customer::count());
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->ledger('Acme Pharma', 'Sundry Debtors');

        $this->artisan('sales:import-customers-from-ledgers --groups="Sundry Debtors"')
            ->expectsOutputToContain('DRY RUN — nothing written')
            ->assertSuccessful();

        $this->assertSame(0, Customer::count());
    }

    public function test_write_creates_only_the_named_groups(): void
    {
        $wanted = $this->ledger('Acme Pharma', 'Sundry Debtors');
        $this->ledger('Chennai Agency', 'Chennai');
        // The thing an inferred parent-walk would have swept in.
        $this->ledger('Bank Of Somewhere', 'Bank Accounts');

        $this->artisan('sales:import-customers-from-ledgers --groups="Sundry Debtors" --write')
            ->expectsOutputToContain('IMPORTED')
            ->assertSuccessful();

        $this->assertSame(1, Customer::count());
        $customer = Customer::first();
        $this->assertSame('Acme Pharma', $customer->name);
        $this->assertSame('TL-'.$wanted->id, $customer->code);
    }

    public function test_it_never_invents_a_field_the_ledger_does_not_carry(): void
    {
        $this->ledger('Acme Pharma', 'Sundry Debtors');

        $this->artisan('sales:import-customers-from-ledgers --groups="Sundry Debtors" --write')->assertSuccessful();

        $customer = Customer::first();
        // A Tally ledger has a name and a group. Everything else stays empty
        // rather than being fabricated — AGENTS.md.
        $this->assertNull($customer->gstin);
        $this->assertNull($customer->email);
        $this->assertNull($customer->phone);
        $this->assertNull($customer->address);
        $this->assertNull($customer->state_code);
    }

    public function test_a_second_run_creates_nothing_new(): void
    {
        $this->ledger('Acme Pharma', 'Sundry Debtors');

        $this->artisan('sales:import-customers-from-ledgers --groups="Sundry Debtors" --write')->assertSuccessful();
        $this->artisan('sales:import-customers-from-ledgers --groups="Sundry Debtors" --write')
            ->expectsOutputToContain('already imported')
            ->assertSuccessful();

        $this->assertSame(1, Customer::count());
    }

    public function test_it_refuses_a_group_name_that_is_not_in_the_data(): void
    {
        $this->ledger('Acme Pharma', 'Sundry Debtors');

        // A typo must stop the run, not quietly import a subset.
        $this->artisan('sales:import-customers-from-ledgers --groups="Sundry Debtor" --write')
            ->assertFailed();

        $this->assertSame(0, Customer::count());
    }

    public function test_it_does_not_resurrect_a_customer_someone_deleted(): void
    {
        $ledger = $this->ledger('Acme Pharma', 'Sundry Debtors');
        $customer = Customer::create(['code' => 'TL-'.$ledger->id, 'name' => 'Acme Pharma', 'is_active' => true]);
        $customer->delete();

        $this->artisan('sales:import-customers-from-ledgers --groups="Sundry Debtors" --write')->assertSuccessful();

        $this->assertSame(0, Customer::count());
        $this->assertSame(1, Customer::withTrashed()->count());
    }

    public function test_a_name_already_used_by_another_customer_is_reported_not_merged(): void
    {
        $this->ledger('Acme Pharma', 'Sundry Debtors');
        Customer::create(['code' => 'CUS-001', 'name' => 'Acme Pharma', 'is_active' => true]);

        $this->artisan('sales:import-customers-from-ledgers --groups="Sundry Debtors" --write')
            ->expectsOutputToContain('name clash')
            ->assertSuccessful();

        // Still exactly the one a person made — nothing merged, nothing added.
        $this->assertSame(1, Customer::count());
        $this->assertSame('CUS-001', Customer::first()->code);
    }

    public function test_it_fails_cleanly_when_no_ledgers_have_been_pulled(): void
    {
        $this->artisan('sales:import-customers-from-ledgers')
            ->expectsOutputToContain('No ledgers in this database')
            ->assertFailed();
    }
}
