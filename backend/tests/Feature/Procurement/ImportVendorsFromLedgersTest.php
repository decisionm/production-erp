<?php

namespace Tests\Feature\Procurement;

use App\Modules\Procurement\Models\Vendor;
use App\Modules\TallySync\Models\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VENDORS ARE CREATED FROM THE TALLY LEDGERS ALREADY MIRRORED HERE — never
 * invented, never inferred, and never without a person naming the groups.
 *
 * The live vendor master holds four demo rows. The real suppliers have been in
 * this database all along as mirrored ledgers, and the only thing missing was
 * a safe way to turn one into the other.
 *
 * WHY THE ALLOW-LIST IS THE WHOLE DESIGN. Sundry Creditors is not a list of
 * suppliers. The 28-Aug voucher exports surfaced, among the parties, an
 * INTEREST ledger whose name differs from a real supplier's by two letters, and
 * the company's OWN second GST registration. A filter that decided which
 * creditor is a vendor would be an agent making a factory judgement; the caller
 * names the groups instead, after reading a census.
 *
 * Shaped deliberately like ImportCustomersFromLedgers, which has already been
 * reviewed and run. A second set of rules for the same act would be the defect.
 */
class ImportVendorsFromLedgersTest extends TestCase
{
    use RefreshDatabase;

    private function ledger(string $name, string $group, array $overrides = []): Ledger
    {
        return Ledger::create([
            'tally_guid' => 'led-'.md5($name),
            'name' => $name,
            'tally_group_name' => $group,
            ...$overrides,
        ]);
    }

    public function test_a_dry_run_with_no_groups_prints_a_census_and_writes_nothing(): void
    {
        $this->ledger('Vendor Alpha', 'Sundry Creditors');

        $this->artisan('procurement:import-vendors-from-ledgers')
            ->expectsOutputToContain('Sundry Creditors')
            ->assertSuccessful();

        $this->assertSame(0, Vendor::count());
    }

    public function test_a_dry_run_with_groups_reports_what_it_would_create_and_writes_nothing(): void
    {
        $this->ledger('Vendor Alpha', 'Sundry Creditors');

        $this->artisan('procurement:import-vendors-from-ledgers --groups="Sundry Creditors"')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertSame(0, Vendor::count());
    }

    public function test_write_creates_the_vendor_with_a_minted_code_and_the_ledger_link(): void
    {
        $ledger = $this->ledger('Vendor Alpha', 'Sundry Creditors', [
            'gstin' => '33AAACD1227B1ZL',
            'state_name' => 'Tamil Nadu',
        ]);

        $this->artisan('procurement:import-vendors-from-ledgers --groups="Sundry Creditors" --write')
            ->assertSuccessful();

        $vendor = Vendor::where('name', 'Vendor Alpha')->firstOrFail();
        $this->assertSame('V-0001', $vendor->code, 'the code comes from the same sequence the form uses');
        $this->assertSame('33AAACD1227B1ZL', $vendor->gstin);
        $this->assertSame($ledger->tally_guid, $vendor->tally_ledger_guid);
        $this->assertSame('Vendor Alpha', $vendor->tally_ledger_name);
        $this->assertTrue((bool) $vendor->is_active);
    }

    /** A ledger Tally has no GSTIN for makes a vendor with none — never a placeholder. */
    public function test_a_ledger_without_a_gstin_makes_a_vendor_without_one(): void
    {
        $this->ledger('Vendor Bravo', 'Sundry Creditors');

        $this->artisan('procurement:import-vendors-from-ledgers --groups="Sundry Creditors" --write')
            ->assertSuccessful();

        $vendor = Vendor::where('name', 'Vendor Bravo')->firstOrFail();
        $this->assertNull($vendor->gstin);
        $this->assertNull($vendor->email);
        $this->assertNull($vendor->phone);
        $this->assertNull($vendor->address);
    }

    /** Only the named groups. A ledger outside them is not a vendor. */
    public function test_a_ledger_outside_the_named_groups_is_not_imported(): void
    {
        $this->ledger('Vendor Alpha', 'Sundry Creditors');
        $this->ledger('Bank Charges', 'Indirect Expenses');

        $this->artisan('procurement:import-vendors-from-ledgers --groups="Sundry Creditors" --write')
            ->assertSuccessful();

        $this->assertSame(1, Vendor::count());
        $this->assertNull(Vendor::where('name', 'Bank Charges')->first());
    }

    public function test_running_it_twice_leaves_one_vendor(): void
    {
        $this->ledger('Vendor Alpha', 'Sundry Creditors');

        $this->artisan('procurement:import-vendors-from-ledgers --groups="Sundry Creditors" --write')->assertSuccessful();
        $this->artisan('procurement:import-vendors-from-ledgers --groups="Sundry Creditors" --write')
            ->expectsOutputToContain('already imported')
            ->assertSuccessful();

        $this->assertSame(1, Vendor::where('name', 'Vendor Alpha')->count());
    }

    /**
     * Matched on the LEDGER, not the name, so a vendor somebody renamed after
     * import is still recognised as that ledger's rather than created twice.
     */
    public function test_a_renamed_vendor_is_still_recognised_as_its_ledger(): void
    {
        $this->ledger('Vendor Alpha', 'Sundry Creditors');
        $this->artisan('procurement:import-vendors-from-ledgers --groups="Sundry Creditors" --write')->assertSuccessful();

        Vendor::where('name', 'Vendor Alpha')->update(['name' => 'Alpha Polymers (renamed by Accounts)']);

        $this->artisan('procurement:import-vendors-from-ledgers --groups="Sundry Creditors" --write')->assertSuccessful();

        $this->assertSame(1, Vendor::count(), 'a rename caused a duplicate vendor');
    }

    /** A vendor of the same name from another source is reported, never merged. */
    public function test_a_name_clash_with_an_existing_vendor_is_skipped_and_named(): void
    {
        Vendor::create(['code' => 'VEN-OLD', 'name' => 'Vendor Alpha']);
        $this->ledger('Vendor Alpha', 'Sundry Creditors');

        $this->artisan('procurement:import-vendors-from-ledgers --groups="Sundry Creditors" --write')
            ->expectsOutputToContain('name clash')
            ->assertSuccessful();

        $this->assertSame(1, Vendor::count());
        $this->assertNull(Vendor::where('code', 'VEN-OLD')->first()->tally_ledger_guid);
    }

    /** A vendor somebody archived stays archived — the import must not resurrect it. */
    public function test_an_archived_vendor_of_the_same_name_is_not_recreated(): void
    {
        Vendor::create(['code' => 'VEN-OLD', 'name' => 'Vendor Alpha'])->delete();
        $this->ledger('Vendor Alpha', 'Sundry Creditors');

        $this->artisan('procurement:import-vendors-from-ledgers --groups="Sundry Creditors" --write')
            ->assertSuccessful();

        $this->assertSame(0, Vendor::count(), 'an archived vendor was recreated by the import');
    }

    /** A misspelled group would silently import nothing, so it is refused. */
    public function test_an_unknown_group_is_refused_rather_than_importing_a_subset(): void
    {
        $this->ledger('Vendor Alpha', 'Sundry Creditors');

        $this->artisan('procurement:import-vendors-from-ledgers --groups="Sundry Creditor" --write')
            ->assertFailed();

        $this->assertSame(0, Vendor::count());
    }

    /** The census label for ungrouped ledgers is not a group and cannot be imported. */
    public function test_the_no_group_label_cannot_be_selected(): void
    {
        Ledger::create(['tally_guid' => 'led-x', 'name' => 'Orphan', 'tally_group_name' => null]);

        $this->artisan('procurement:import-vendors-from-ledgers --groups="(no group)" --write')
            ->assertFailed();

        $this->assertSame(0, Vendor::count());
    }

    public function test_it_refuses_when_no_ledgers_have_been_pulled(): void
    {
        $this->artisan('procurement:import-vendors-from-ledgers --groups="Sundry Creditors" --write')
            ->assertFailed();
    }
}
