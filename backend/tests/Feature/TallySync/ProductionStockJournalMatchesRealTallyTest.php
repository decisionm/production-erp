<?php

namespace Tests\Feature\TallySync;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\FactoryWarehouseResolver;
use App\Modules\TallySync\Services\TallySyncService;
use Database\Seeders\CanonicalMachineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE VOUCHER WE BUILD, CHECKED AGAINST THE VOUCHERS THEIR ACCOUNTANT ACTUALLY
 * POSTS.
 *
 * The evidence is `tests/fixtures/tally/production-stock-journals.xml`: the
 * first two Stock Journals of the factory's own 24-Aug-2026 export from the
 * 'SWAASHPET POLYMERS PVT LTD Testing' company (34 vouchers in the export),
 * trimmed by `tests/scripts/build_stock_journal_fixture.py` — rates and
 * amounts stripped, because those are purchase rates and FC-06 keeps them out
 * of the repo. Every line, name, quantity string and godown below is theirs.
 *
 * A NOTE ON WHICH CORPUS THIS IS, because the decision records cite another
 * one. FC-02/03/04 and the resin decisions were read out of `Transactions.xml`
 * (30-Jul export, 38 vouchers), which `sources/manifest.json` now records as
 * MISSING — deleted from Downloads, its findings surviving only in the
 * decision records. This is a DIFFERENT, later export of the same factory's
 * Stock Journals. It is used the way DEC-20260827-001 already uses a Testing
 * company export: as real evidence, named for what it is.
 *
 * Two halves. First, what the real vouchers say — asserted directly, so a
 * later reader can see the contract rather than take this file's word for it.
 * Second, that a voucher the ERP builds has the same shape.
 */
class ProductionStockJournalMatchesRealTallyTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__.'/../../fixtures/tally/production-stock-journals.xml';

    /** @var array<int, array{in: array<int, array<string, string>>, out: array<int, array<string, string>>, godown: string}> */
    private array $real = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->real = $this->readVouchers();
    }

    // ================= half one: what the factory's Tally says ==============

    /**
     * THE COLUMN IS DRIVEN BY `ISDEEMEDPOSITIVE`, NEVER BY THE TAG NAME.
     *
     * This is the single fact most likely to be got backwards by anything that
     * writes or reads one of these vouchers, so it is pinned first: every line
     * Tally files as IN carries `Yes`, every line it files as OUT carries `No`.
     * A reader that keys off the tag alone will be right until it meets an
     * export that nests them differently; a reader that keys off this flag is
     * reading what Tally itself uses.
     */
    public function test_every_inward_line_is_deemed_positive_and_every_outward_line_is_not(): void
    {
        $this->assertNotEmpty($this->real, 'The fixture parsed to no vouchers at all.');

        foreach ($this->real as $index => $voucher) {
            $this->assertNotEmpty($voucher['in'], "Voucher {$index} has no inward lines.");
            $this->assertNotEmpty($voucher['out'], "Voucher {$index} has no outward lines.");

            foreach ($voucher['in'] as $line) {
                $this->assertSame('Yes', $line['ISDEEMEDPOSITIVE'], "Inward line {$line['STOCKITEMNAME']}");
            }

            foreach ($voucher['out'] as $line) {
                $this->assertSame('No', $line['ISDEEMEDPOSITIVE'], "Outward line {$line['STOCKITEMNAME']}");
            }
        }
    }

    /**
     * FC-04's shape, read off the real thing: finished bottles go IN, resin and
     * packing materials go OUT. Neither ever appears on the other side.
     */
    public function test_finished_goods_go_in_and_resin_and_packing_go_out(): void
    {
        $in = $this->itemNames('in');
        $out = $this->itemNames('out');

        // The resins DEC-20260805-002 names as the real purchased ones.
        foreach (['Relpet', 'PET Polyster Chips'] as $resin) {
            $this->assertContains($resin, $out, "{$resin} is consumed, so it belongs on the OUT side.");
            $this->assertNotContains($resin, $in);
        }

        // Packing materials — cartons and trays, out of the packing store.
        foreach (['100 Ml Master Box', '200 Ml Brute Tray'] as $packing) {
            $this->assertContains($packing, $out);
            $this->assertNotContains($packing, $in);
        }

        // Bottles are produced.
        $bottles = array_filter($in, fn (string $name) => str_starts_with($name, 'B.'));
        $this->assertNotEmpty($bottles, 'No produced bottle line — the voucher makes nothing.');
        foreach ($bottles as $bottle) {
            $this->assertNotContains($bottle, $out);
        }
    }

    /**
     * FC-02, stated by their own books: scrap is not discarded, it is produced
     * and booked INWARD. The code that builds our voucher agrees
     * (TallySyncService::producedScrapLine); this is the evidence it agrees
     * with.
     */
    public function test_pet_scrap_is_an_inward_line_not_a_write_off(): void
    {
        $this->assertContains('Pet Scrap', $this->itemNames('in'));
        $this->assertNotContains('Pet Scrap', $this->itemNames('out'));
    }

    /**
     * FC-03: tape is counted in Nos (rolls), and their voucher proves the unit
     * rather than our reading of it. One No is one roll and one roll is 65 m
     * (DEC-20260807-005) — a metres figure filed as Nos is a different number
     * about a different thing, and that reached live once.
     */
    public function test_packing_tape_is_consumed_in_nos_never_in_metres(): void
    {
        $tape = $this->line('out', 'Packing Tape - Transparent');

        $this->assertNotNull($tape, 'The fixture no longer carries the tape line this rule is read from.');
        $this->assertStringContainsString('Nos', $tape['ACTUALQTY']);
        $this->assertStringNotContainsStringIgnoringCase('mtr', $tape['ACTUALQTY']);
    }

    /**
     * DEC-20260830-002: ONE godown. Every line and the voucher itself name the
     * same one, so anything that emits a second godown name is emitting
     * something this factory's Tally has never seen.
     */
    public function test_every_line_names_the_one_company_godown(): void
    {
        foreach ($this->real as $voucher) {
            $this->assertSame('SWAASHPET POLYMERS PVT LTD', $voucher['godown']);

            foreach ([...$voucher['in'], ...$voucher['out']] as $line) {
                $this->assertSame('SWAASHPET POLYMERS PVT LTD', $line['GODOWNNAME'] ?? null);
            }
        }
    }

    // ================= half two: the voucher WE build ========================

    /**
     * A completed batch's payload has the real voucher's shape: what was made
     * and the scrap it made on the produced side, what it consumed on the other,
     * and the tape stated as withheld rather than posted in the wrong unit.
     */
    public function test_a_batch_we_complete_builds_a_voucher_of_the_same_shape(): void
    {
        $payload = app(TallySyncService::class)->buildBatchVoucherPayload($this->completedBatch());

        $produced = array_column($payload['produced'], 'item');
        $consumed = array_column($payload['consumed'], 'item');

        // Produced: the bottle, and the scrap the shift made — FC-02, the same
        // two kinds of inward line the real vouchers carry.
        $this->assertContains('B.100 Ml Round Pet Bottle Amber-12.9gms', $produced);
        $this->assertContains('Pet Scrap', $produced, 'FC-02: scrap is booked inward, never written off.');

        // Consumed: resin and the carton. Named exactly as the factory's Tally
        // names them — these two strings are copied from the fixture.
        $this->assertContains('Relpet', $consumed);
        $this->assertContains('100 Ml Master Box', $consumed);

        // NOTHING CROSSES SIDES. A produced item appearing as a consumption is
        // the mistake that books a bottle as its own input.
        $this->assertSame([], array_intersect($produced, $consumed));

        // Every consumption names a godown; a null would let the agent fall
        // back to the voucher's FG godown and deduct resin from the wrong store.
        foreach ($payload['consumed'] as $line) {
            $this->assertNotNull($line['godown'], "Consumption line {$line['item']} names no godown.");
        }
    }

    /** No zero lines. A shift that scrapped nothing says nothing about scrap. */
    public function test_a_batch_that_scrapped_nothing_carries_no_scrap_line(): void
    {
        $payload = app(TallySyncService::class)->buildBatchVoucherPayload($this->completedBatch(scrapKg: null));

        $this->assertNotContains('Pet Scrap', array_column($payload['produced'], 'item'));
    }

    // ================= fixture reading ======================================

    /** @return array<int, array{in: array<int, array<string, string>>, out: array<int, array<string, string>>, godown: string}> */
    private function readVouchers(): array
    {
        $xml = simplexml_load_file(self::FIXTURE);
        $this->assertNotFalse($xml, 'The Stock Journal fixture did not parse.');

        $vouchers = [];

        foreach ($xml->BODY->REQUESTDATA->VOUCHER as $voucher) {
            $vouchers[] = [
                'godown' => (string) $voucher->DESTINATIONGODOWN,
                'in' => $this->linesOf($voucher, 'INVENTORYENTRIESIN'),
                'out' => $this->linesOf($voucher, 'INVENTORYENTRIESOUT'),
            ];
        }

        return $vouchers;
    }

    /** @return array<int, array<string, string>> */
    private function linesOf(\SimpleXMLElement $voucher, string $tag): array
    {
        $lines = [];

        foreach ($voucher->{$tag.'.LIST'} as $line) {
            $row = [];
            foreach ($line->children() as $name => $value) {
                $row[$name] = (string) $value;
            }
            $lines[] = $row;
        }

        return $lines;
    }

    /** @return array<int, string> */
    private function itemNames(string $side): array
    {
        $names = [];

        foreach ($this->real as $voucher) {
            foreach ($voucher[$side] as $line) {
                $names[] = $line['STOCKITEMNAME'];
            }
        }

        return array_values(array_unique($names));
    }

    /** @return array<string, string>|null */
    private function line(string $side, string $item): ?array
    {
        foreach ($this->real as $voucher) {
            foreach ($voucher[$side] as $line) {
                if ($line['STOCKITEMNAME'] === $item) {
                    return $line;
                }
            }
        }

        return null;
    }

    // ================= a batch, completed ===================================

    private function completedBatch(?string $scrapKg = '18.5'): ShiftProductionEntry
    {
        $this->seed(CanonicalMachineSeeder::class);
        $machine = WorkCenter::where('code', 'MC-01')->firstOrFail();
        $shift = Shift::create(['name' => 'Shift A', 'start_time' => '06:00', 'end_time' => '14:00']);

        $store = Warehouse::create([
            'code' => 'STORE', 'name' => 'SWAASHPET POLYMERS PVT LTD',
            'is_active' => true, 'tally_guid' => 'gd-company',
        ]);
        app(FactoryWarehouseResolver::class)->setFinishedGoodsWarehouseId($store->id);
        app(FactoryWarehouseResolver::class)->setRawMaterialWarehouseId($store->id);
        app(FactoryWarehouseResolver::class)->setPackingMaterialWarehouseId($store->id);

        // Names copied from the factory's own Tally, not invented.
        $resin = Item::create([
            'sku' => 'RELPET', 'name' => 'Relpet', 'uom' => 'Kgs.', 'is_active' => true,
            'category' => ItemCategory::RawMaterial->value, 'tally_stock_item_guid' => 'g-resin',
        ]);
        $carton = Item::create([
            'sku' => 'BOX-100', 'name' => '100 Ml Master Box', 'uom' => 'Nos', 'is_active' => true,
            'category' => ItemCategory::PackingMaterial->value, 'tally_stock_item_guid' => 'g-box',
        ]);
        Item::create([
            'sku' => config('production.scrap.rejected_item_sku', 'PET-SCRAP'),
            'name' => 'Pet Scrap', 'uom' => 'Kgs.', 'is_active' => true,
            'category' => ItemCategory::Other->value, 'tally_stock_item_guid' => 'g-scrap',
        ]);

        $bottle = Item::create([
            'sku' => 'B100RA', 'name' => 'B.100 Ml Round Pet Bottle Amber-12.9gms', 'uom' => 'Nos.',
            'is_active' => true, 'category' => ItemCategory::FinishedGood->value,
            'nominal_weight_grams' => '12.9000', 'standard_cycle_time' => '12.30', 'standard_cavities' => 5,
            'nos_per_tray' => 162, 'trays_per_box' => 5, 'nos_per_box' => 810,
            'colour' => 'Amber', 'tally_stock_item_guid' => 'g-bottle',
        ]);

        // The run's plan: this bottle takes this resin and packs in this box.
        // Present so the completion is an ORDINARY one — nothing here is
        // testing the added-line path (AddedConsumptionLineTest does that).
        $bom = Bom::create(['item_id' => $bottle->id, 'name' => 'recipe', 'version' => '1', 'is_active' => true]);
        $bom->lines()->create(['component_item_id' => $resin->id, 'quantity_per' => '0.0129']);
        $bom->lines()->create(['component_item_id' => $carton->id, 'quantity_per' => '0.0012']);

        $user = User::factory()->create(['is_active' => true]);
        foreach (['production.view', 'production.manage', 'inventory.view', 'inventory.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo(['production.view', 'production.manage', 'inventory.view', 'inventory.manage']);
        Sanctum::actingAs($user);

        $entryId = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $bottle->id,
            'warehouse_id' => $store->id,
            'production_date' => '2026-09-01',
        ])->assertOk()->json('data.id');

        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", array_filter([
            'quantity_produced' => 11340,
            'nos_per_box' => 810,
            'no_of_box' => 14,
            'material_consumptions' => [
                ['item_id' => $resin->id, 'quantity_issued_kg' => '146.29'],
                ['item_id' => $carton->id, 'quantity_issued_kg' => '14'],
            ],
            'scraps' => $scrapKg === null ? null : [
                ['type' => 'lumps', 'quantity_kg' => $scrapKg],
            ],
        ]))->assertOk();

        return ShiftProductionEntry::findOrFail($entryId);
    }
}
