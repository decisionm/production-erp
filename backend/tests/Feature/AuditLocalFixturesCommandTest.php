<?php

namespace Tests\Feature;

use App\Console\Commands\AuditLocalFixtures;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * tally-sync:audit-fixtures — the READ-ONLY detector behind the Phase 1
 * deferral (MASTER-PLAN P1-04 / P3-05): a local-only fixture item may have
 * reached three places it must never post from — a packaging identity, a
 * vouchered production entry (the old Hole B), a queued voucher's payload —
 * and on live the owner decides what to do with each. The command only
 * SHOWS. What these pin: each of the three cases is listed with the facts
 * a person needs (the standard's source product name, the voucher's
 * status), the verdict counts them, a clean database says "clean", the exit
 * code is 0 either way, and the database is byte-identical before and after.
 */
class AuditLocalFixturesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Item $resin;

    private Item $prefixedFixture;

    private Item $flaggedFixture;

    private Warehouse $fg;

    private Shift $shift;

    private WorkCenter $machine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'NOS', 'tally_stock_item_guid' => 'itm-bottle']);
        $this->resin = Item::create(['sku' => 'RES-1', 'name' => 'PET Resin', 'uom' => 'KG', 'tally_stock_item_guid' => 'itm-resin']);
        // Both signals a fixture can carry (Item::isLocalFixture): the LOCAL-
        // prefix with the flag, and the flag alone on a SKU that no longer
        // looks like one.
        $this->prefixedFixture = Item::create(['sku' => 'LOCAL-500ML-TRAY', 'name' => '500ml Tray Pack (LOCAL FIXTURE)', 'uom' => 'NOS', 'is_local_fixture' => true]);
        $this->flaggedFixture = Item::create(['sku' => 'BTL-FLAGGED', 'name' => 'Flagged Fixture Bottle', 'uom' => 'NOS', 'is_local_fixture' => true]);

        $this->fg = Warehouse::create(['code' => 'WH-FG', 'name' => 'FG Store']);
        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->machine = WorkCenter::create(['code' => 'M-01', 'name' => 'Machine 1']);
    }

    private function standard(string $sourceProductName): ProductionStandard
    {
        return ProductionStandard::create([
            'source_product_name' => $sourceProductName, 'item_id' => $this->bottle->id,
            'cavities' => 8, 'unit_weight_grams' => 18, 'cycle_time' => 12, 'status' => 'approved',
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function entry(array $attributes = []): ShiftProductionEntry
    {
        return ShiftProductionEntry::create([
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => '2026-07-23',
            'batch_status' => BatchStatus::Completed,
            'quantity_produced' => '1000',
            'quantity_scrap' => '0',
            'status' => ShiftProductionEntryStatus::Approved,
            ...$attributes,
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    private function voucher(string $voucherType, array $payload, TallySyncStatus $status = TallySyncStatus::Failed): TallySyncEntry
    {
        return TallySyncEntry::create([
            'syncable_type' => (new Shift)->getMorphClass(),
            'syncable_id' => $this->shift->id,
            'tally_voucher_type' => $voucherType,
            'payload' => $payload,
            'status' => $status,
            'attempts' => $status === TallySyncStatus::Failed ? 1 : 0,
            'error_message' => $status === TallySyncStatus::Failed ? "Stock Item '500ml Tray Pack (LOCAL FIXTURE)' does not exist!" : null,
        ]);
    }

    /**
     * Every row of every table, as the command found it — the "writes
     * nothing" promise is asserted against the whole database, not the
     * three tables the report reads.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function snapshot(): array
    {
        $tables = collect(Schema::getTableListing())
            ->reject(fn (string $table) => in_array($table, ['migrations'], true))
            ->sort()
            ->values();

        $snapshot = [];
        foreach ($tables as $table) {
            $rows = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
            usort($rows, fn (array $a, array $b) => strcmp(json_encode($a), json_encode($b)));
            $snapshot[$table] = $rows;
        }

        return $snapshot;
    }

    public function test_it_lists_each_case_with_the_facts_a_person_needs_and_counts_them_in_the_verdict(): void
    {
        // (a) two packaging identities that are fixtures — one per signal —
        // beside a real identity and a packaging with no identity of its own,
        // neither of which may be listed.
        $standard = $this->standard('200ML RA');
        $prefixedPackaging = $standard->packagings()->create(['mode' => 'direct_box', 'nos_per_box' => 300, 'item_id' => $this->prefixedFixture->id]);
        $flaggedPackaging = $standard->packagings()->create(['mode' => 'tray', 'nos_per_tray' => 30, 'trays_per_box' => 10, 'nos_per_box' => 300, 'item_id' => $this->flaggedFixture->id]);
        $standard->packagings()->create(['mode' => 'pouch', 'nos_per_pouch' => 130, 'pouches_per_box' => 4, 'nos_per_box' => 520, 'item_id' => $this->bottle->id]);
        // (one mode per standard is unique — the identity-less row sits on a
        // sibling standard)
        $this->standard('500ML RB')->packagings()->create(['mode' => 'direct_box', 'nos_per_box' => 800, 'item_id' => null]);

        // (b) a vouchered entry whose FROZEN identity is a fixture (Hole B as
        // it stood on live), beside a fixture entry the guard left
        // unvouchered and a real entry that is vouchered — neither listed.
        $holeB = $this->voucher('Stock Journal', [
            'voucher_number' => 'SJ-20260723-S1',
            'produced' => [['item' => $this->prefixedFixture->name, 'quantity' => '1000.0000']],
            'consumed' => [['item' => $this->resin->name, 'quantity' => '10.0000', 'godown' => 'FG Store']],
        ]);
        $vouchered = $this->entry(['batch_number' => 'B-HOLE-B', 'finished_item_id' => $this->prefixedFixture->id, 'tally_sync_entry_id' => $holeB->id]);
        $this->entry(['batch_number' => 'B-UNVOUCHERED', 'finished_item_id' => $this->flaggedFixture->id, 'tally_sync_entry_id' => null]);
        $clean = $this->voucher('Stock Journal', [
            'voucher_number' => 'SJ-20260723-S1-2',
            'produced' => [['item' => $this->bottle->name, 'quantity' => '500.0000']],
            'consumed' => [['item' => $this->resin->name, 'quantity' => '5.0000', 'godown' => 'FG Store']],
        ], TallySyncStatus::Synced);
        $this->entry(['batch_number' => 'B-REAL', 'tally_sync_entry_id' => $clean->id]);

        // (c) payloads naming a fixture on every line shape the builders
        // write — lines[] (Sales / Receipt Note / Delivery Note), consumed[]
        // and withheld[] (production) — plus $holeB above (produced[]).
        $sales = TallySyncEntry::create([
            'syncable_type' => 'invoice', 'syncable_id' => 9, 'tally_voucher_type' => 'Sales',
            'payload' => ['voucher_number' => 'INV-9', 'lines' => [['item' => $this->flaggedFixture->name, 'quantity' => '10.0000', 'rate' => '4.5000', 'amount' => '45.0000']]],
            'status' => TallySyncStatus::Pending, 'attempts' => 0,
        ]);
        $withheld = $this->voucher('Manufacturing Journal', [
            'voucher_number' => 'SPE-77',
            'produced' => [['item' => $this->bottle->name, 'quantity' => '100.0000']],
            'consumed' => [['item' => $this->resin->name, 'quantity' => '1.0000', 'godown' => 'FG Store']],
            'withheld' => [['item' => $this->prefixedFixture->name, 'quantity' => '2.0000', 'reason' => 'x']],
        ], TallySyncStatus::Dismissed);

        $this->artisan('tally-sync:audit-fixtures')
            // (a) — with the standard's source_product_name.
            ->expectsOutputToContain(sprintf('packaging #%d  standard #%d "200ML RA"  direct_box  item #%d LOCAL-500ML-TRAY "500ml Tray Pack (LOCAL FIXTURE)"', $prefixedPackaging->id, $standard->id, $this->prefixedFixture->id))
            ->expectsOutputToContain(sprintf('packaging #%d  standard #%d "200ML RA"  tray  item #%d BTL-FLAGGED "Flagged Fixture Bottle"', $flaggedPackaging->id, $standard->id, $this->flaggedFixture->id))
            // (b) — with the voucher's status.
            ->expectsOutputToContain(sprintf('entry #%d  batch B-HOLE-B  2026-07-23  effective item #%d LOCAL-500ML-TRAY  voucher #%d SJ-20260723-S1 status=failed', $vouchered->id, $this->prefixedFixture->id, $holeB->id))
            ->doesntExpectOutputToContain('B-UNVOUCHERED')
            ->doesntExpectOutputToContain('B-REAL')
            // (c) — one line per voucher, naming the fixture item(s) it carries.
            ->expectsOutputToContain(sprintf('voucher #%d  Stock Journal SJ-20260723-S1 status=failed  names: 500ml Tray Pack (LOCAL FIXTURE)', $holeB->id))
            ->expectsOutputToContain(sprintf('voucher #%d  Sales INV-9 status=pending  names: Flagged Fixture Bottle', $sales->id))
            ->expectsOutputToContain(sprintf('voucher #%d  Manufacturing Journal SPE-77 status=dismissed  names: 500ml Tray Pack (LOCAL FIXTURE)', $withheld->id))
            ->doesntExpectOutputToContain('SJ-20260723-S1-2 ')
            ->expectsOutputToContain('VERDICT: 2 packagings, 1 vouchered entries, 3 vouchers name a fixture')
            ->assertSuccessful();
    }

    public function test_a_clean_database_says_clean(): void
    {
        // Real identities, real vouchers, and fixtures that reached none of
        // the three places: nothing to list.
        $standard = $this->standard('500ML RB');
        $standard->packagings()->create(['mode' => 'direct_box', 'nos_per_box' => 800, 'item_id' => $this->bottle->id]);
        $voucher = $this->voucher('Stock Journal', [
            'voucher_number' => 'SJ-20260723-S1',
            'produced' => [['item' => $this->bottle->name, 'quantity' => '1000.0000']],
            'consumed' => [['item' => $this->resin->name, 'quantity' => '10.0000', 'godown' => 'FG Store']],
        ], TallySyncStatus::Synced);
        $this->entry(['batch_number' => 'B-REAL', 'tally_sync_entry_id' => $voucher->id]);
        $this->entry(['batch_number' => 'B-FIXTURE-UNVOUCHERED', 'finished_item_id' => $this->prefixedFixture->id]);

        $this->artisan('tally-sync:audit-fixtures')
            ->expectsOutputToContain(AuditLocalFixtures::VERDICT_CLEAN)
            ->doesntExpectOutputToContain('name a fixture')
            ->assertSuccessful();
    }

    public function test_it_is_read_only_and_exits_zero_even_when_it_finds_something(): void
    {
        $standard = $this->standard('200ML RA');
        $standard->packagings()->create(['mode' => 'direct_box', 'nos_per_box' => 300, 'item_id' => $this->prefixedFixture->id]);
        $holeB = $this->voucher('Stock Journal', [
            'voucher_number' => 'SJ-20260723-S1',
            'produced' => [['item' => $this->prefixedFixture->name, 'quantity' => '1000.0000']],
        ]);
        $this->entry(['batch_number' => 'B-HOLE-B', 'finished_item_id' => $this->prefixedFixture->id, 'tally_sync_entry_id' => $holeB->id]);

        $before = $this->snapshot();

        $this->artisan('tally-sync:audit-fixtures')
            ->expectsOutputToContain('VERDICT: 1 packagings, 1 vouchered entries, 1 vouchers name a fixture')
            ->assertExitCode(0);

        // Byte-identical: no status flipped, no stamp cleared, no event
        // recorded, no item re-flagged. Report only.
        $this->assertSame($before, $this->snapshot());
    }
}
