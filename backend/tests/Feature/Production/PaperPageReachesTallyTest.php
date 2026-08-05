<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ONE ROW OF THE FACTORY'S PAPER PRODUCTION REPORT, ALL THE WAY TO TALLY.
 *
 * The owner's stated priority (05-Aug): "the daily production entry, each page
 * needs to enter in our app, and with consumption — all raw material, packing
 * and production material all goes to Tally."
 *
 * This pins the second half of that sentence. It takes the real ASB-1 row from
 * the handwritten report for 5 August shift A — 100 RC, 12.0 g, 168/tray,
 * 840/box, 12 boxes, 10,080 produced, 237 rejected, 120.96 + 2.84 = 123.80 kg
 * consumed — and asserts that every material class on that page appears on the
 * voucher: the resin, the colourant, and each packing material.
 *
 * WHY IT IS WORTH A TEST OF ITS OWN. Nothing in the voucher builder filters by
 * material type, and that is exactly the kind of correctness that holds by
 * accident until someone adds a filter for a good local reason. A packing line
 * silently missing from the payload does not fail: the voucher posts, Tally
 * accepts it, and the factory's carton stock drifts upward against a shelf that
 * is emptying — discovered weeks later at a physical count. This test is what
 * makes that regression loud.
 *
 * It also records the paper's own arithmetic as the reference, so the
 * consumption rule (production kg + rejection kg + lumps kg, at ONE bottle
 * weight) cannot drift from what the supervisor writes by hand.
 */
class PaperPageReachesTallyTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $godown;

    private ShiftProductionEntry $entry;

    /** @var array<string, Item> */
    private array $materials = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.enforced' => false]);

        $this->godown = Warehouse::create([
            'code' => 'SWA', 'name' => 'SWAASHPET POLYMERS PVT LTD', 'is_active' => true,
            'tally_guid' => '7cabb80e-0000-0000-0000-00000000003e',
        ]);

        // The product on the paper's ASB-1 row, at the weight the paper states.
        $bottle = Item::create([
            'sku' => 'B100RC840', 'name' => 'B.100 Ml Round Clear Pet Bottle - 840',
            'uom' => 'Nos.', 'nominal_weight_grams' => '12.0000', 'is_active' => true,
        ]);

        // Every material class the factory's own Stock Journals consume: the
        // resin, the colourant, and four kinds of packing. Named exactly as
        // their Tally carries them.
        foreach ([
            'resin' => ['RELPET', 'Relpet', 'Kgs.'],
            'masterbatch' => ['MB-CLEAR', 'Master Batch - Pet White', 'Kgs.'],
            'carton' => ['MB-100', '100 Ml Master Box', 'Nos.'],
            'tray' => ['TR-100', '100 Ml Tray', 'Nos.'],
            'pouch' => ['POP', 'Poly Olefin Pouch', 'Kgs.'],
            'tape' => ['TAPE-T', 'Packing Tape - Transparent', 'Nos.'],
        ] as $key => [$sku, $name, $uom]) {
            $this->materials[$key] = Item::create([
                'sku' => $sku, 'name' => $name, 'uom' => $uom, 'is_active' => true,
            ]);

            StockBalance::create([
                'item_id' => $this->materials[$key]->id,
                'warehouse_id' => $this->godown->id,
                'quantity' => '10000.0000',
                'average_cost' => '100.0000',
            ]);
        }

        $shift = Shift::create(['name' => 'A', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $machine = WorkCenter::create(['code' => 'ASB-1', 'name' => 'ASB-1', 'is_active' => true]);

        $this->entry = ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $bottle->id,
            'warehouse_id' => $this->godown->id,
            'production_date' => '2026-08-05',
            'batch_number' => '20260805-ASB1-001',
            'batch_status' => BatchStatus::Completed,
            'status' => ShiftProductionEntryStatus::Pending,
            // Straight off the paper.
            'quantity_produced' => '10080',
            'quantity_produced_kg' => '120.9600',
            'quantity_scrap' => '237',
            'quantity_rejection_kg' => '2.8440',
            'nos_per_tray' => 168,
            'no_of_trays' => 5,
            'nos_per_box' => 840,
            'no_of_box' => 12,
        ]);

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        Permission::findOrCreate('production.manage', 'web');
        $user->givePermissionTo(['production.view', 'production.manage']);
        $this->actingAs($user);
    }

    /** Book one consumption line, the way a completion does. */
    private function consume(string $key, string $qty): void
    {
        $this->entry->materialConsumptions()->create([
            'item_id' => $this->materials[$key]->id,
            'warehouse_id' => $this->godown->id,
            'quantity_issued_kg' => $qty,
        ]);
    }

    public function test_every_material_class_on_the_paper_row_reaches_the_voucher(): void
    {
        // 123.80 kg of resin is the paper's own CONSUMPTION figure for this row.
        $this->consume('resin', '123.8040');
        $this->consume('masterbatch', '1.2380');
        // 12 boxes, 5 trays each: 12 cartons, 60 trays, 60 pouches (one per
        // tray), and tape by the roll.
        $this->consume('carton', '12.0000');
        $this->consume('tray', '60.0000');
        $this->consume('pouch', '7.2000');
        $this->consume('tape', '3.0000');

        $payload = app(TallySyncService::class)->buildBatchVoucherPayload($this->entry->fresh());

        $consumed = collect($payload['consumed'])->pluck('quantity', 'item');

        // NOT ONE CLASS DROPPED. The voucher builder does not filter by material
        // type, and this is what keeps it that way.
        $this->assertSame([
            'Relpet',
            'Master Batch - Pet White',
            '100 Ml Master Box',
            '100 Ml Tray',
            'Poly Olefin Pouch',
            'Packing Tape - Transparent',
        ], $consumed->keys()->all());

        // Compared as FIGURES, not as strings: what matters is that the
        // quantity survives the trip intact, not how many trailing zeros the
        // decimal cast happens to render.
        foreach ([
            'Relpet' => '123.8040',
            '100 Ml Master Box' => '12.0000',
            '100 Ml Tray' => '60.0000',
            'Poly Olefin Pouch' => '7.2000',
        ] as $item => $expected) {
            $this->assertSame(
                0,
                bccomp((string) $consumed[$item], $expected, 4),
                "{$item} reached the voucher as {$consumed[$item]}, not {$expected}.",
            );
        }

        // Every line names a godown Tally knows — a consumption line without one
        // is deducted from whatever the voucher's own godown happens to be.
        foreach ($payload['consumed'] as $line) {
            $this->assertSame('SWAASHPET POLYMERS PVT LTD', $line['godown'], "{$line['item']} lost its godown.");
        }

        // And the bottles themselves, at the paper's count.
        $this->assertSame('B.100 Ml Round Clear Pet Bottle - 840', $payload['produced'][0]['item']);
        $this->assertSame(0, bccomp((string) $payload['produced'][0]['quantity'], '10080', 0));
    }

    public function test_the_papers_own_consumption_arithmetic_is_the_reference(): void
    {
        // The rule, from the factory's paper and confirmed against every row of
        // it: consumption = production kg + rejection kg + lumps kg, all at ONE
        // bottle weight. The owner caught the app breaking this by using two
        // different weights on one panel — 133.09 where the paper says 123.80.
        $wt = '12.0000';
        $produced = bcdiv(bcmul('10080', $wt, 4), '1000', 4);
        $rejected = bcdiv(bcmul('237', $wt, 4), '1000', 4);

        $this->assertSame('120.9600', $produced, 'PRODUCTION IN KGS, as written on the paper.');
        $this->assertSame('2.8440', $rejected, 'REJECTION IN KGS, as written on the paper.');
        $this->assertSame('123.8040', bcadd($produced, $rejected, 4), 'CONSUMPTION IN KGS — the paper says 123.80.');

        // The entry stores the same two figures, so a voucher built from it
        // cannot disagree with the page it was typed from.
        $this->assertSame('120.9600', $this->entry->quantity_produced_kg);
        $this->assertSame('2.8440', $this->entry->quantity_rejection_kg);
    }

    public function test_a_packing_line_cannot_be_quietly_lost(): void
    {
        // The regression this suite exists for. A filter added to the
        // consumption loop for a good local reason — "only raw materials", "skip
        // anything counted in Nos" — would not fail anything: the voucher posts,
        // Tally accepts it, and the factory's carton stock drifts upward against
        // a shelf that is emptying. Found weeks later at a physical count.
        $this->consume('resin', '123.8040');
        $this->consume('carton', '12.0000');

        $payload = app(TallySyncService::class)->buildBatchVoucherPayload($this->entry->fresh());

        $this->assertCount(2, $payload['consumed'], 'A packing line was dropped from the payload.');
    }
}
