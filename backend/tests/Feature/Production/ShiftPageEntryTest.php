<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A whole page of the factory's production report, entered at once.
 *
 * The owner's priority (05-Aug): "the daily production entry, each page needs to
 * enter in our app." Ten to twelve machine rows, entered together, instead of a
 * Start dialog and a Complete dialog each — roughly sixty interactions per page,
 * three pages a day.
 *
 * The fixtures are the real 5 August shift A page: ASB-1 running 100 RC at 12.0 g
 * for 10,080 good and 237 rejected, ASB-2 running 90ml Rib C at 8.5 g, and the
 * twelfth row where ASB-4 runs a SECOND product after a mould change.
 */
class ShiftPageEntryTest extends TestCase
{
    use RefreshDatabase;

    private Shift $shift;

    /** @var array<string, WorkCenter> */
    private array $machines = [];

    /** @var array<string, Item> */
    private array $products = [];

    private Item $resin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.enforced' => false]);

        $godown = Warehouse::create([
            'code' => 'SWA', 'name' => 'SWAASHPET POLYMERS PVT LTD', 'is_active' => true,
            'tally_guid' => '7cabb80e-0000-0000-0000-00000000003e',
        ]);

        $this->shift = Shift::create(['name' => 'A', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);

        foreach (['ASB-1', 'ASB-2', 'ASB-4'] as $code) {
            $this->machines[$code] = WorkCenter::create(['code' => $code, 'name' => $code, 'is_active' => true]);
        }

        // Straight off the paper: product, and the weight written beside it.
        foreach ([
            '100 RC' => '12.0000',
            '90ml Rib C' => '8.5000',
            '60 RA' => '10.0000',
        ] as $name => $grams) {
            $this->products[$name] = Item::create([
                'sku' => $name, 'name' => $name, 'uom' => 'Nos.',
                'nominal_weight_grams' => $grams, 'is_active' => true,
            ]);
        }

        $this->resin = Item::create(['sku' => 'RELPET', 'name' => 'Relpet', 'uom' => 'Kgs.', 'is_active' => true]);
        StockBalance::create([
            'item_id' => $this->resin->id, 'warehouse_id' => $godown->id,
            'quantity' => '10000.0000', 'average_cost' => '138.0000',
        ]);

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        Permission::findOrCreate('production.manage', 'web');
        $user->givePermissionTo(['production.view', 'production.manage']);
        $this->actingAs($user);
    }

    /** @param list<array<string, mixed>> $rows */
    private function page(array $rows)
    {
        return $this->postJson('/api/v1/production/shift-production-entries/page', [
            'shift_id' => $this->shift->id,
            'production_date' => '2026-08-05',
            'rows' => $rows,
        ]);
    }

    /** The paper's ASB-1 line. */
    private function asb1(): array
    {
        return [
            'work_center_id' => $this->machines['ASB-1']->id,
            'item_id' => $this->products['100 RC']->id,
            'quantity_produced' => 10080,
            'quantity_scrap' => 237,
            'nos_per_tray' => 168,
            'no_of_trays' => 5,
            'nos_per_box' => 840,
            'no_of_box' => 12,
            'running_hours' => 8,
        ];
    }

    public function test_a_page_of_rows_becomes_a_page_of_completed_batches(): void
    {
        $response = $this->page([
            $this->asb1(),
            [
                'work_center_id' => $this->machines['ASB-2']->id,
                'item_id' => $this->products['90ml Rib C']->id,
                'quantity_produced' => 5280,
                'quantity_scrap' => 1072,
                // The paper's LUMPS column, which is a different population from
                // rejection and is never added to it.
                'lumps_kg' => 1.23,
                'running_hours' => 8,
            ],
        ])->assertOk();

        $this->assertCount(2, $response->json('data.recorded'));
        $this->assertSame([], $response->json('data.failed'));

        // Both are COMPLETED and awaiting the approval chain — not approved, not
        // quality-checked, not posted. A bulk entry that also signed itself off
        // would defeat the control the accountant relies on.
        foreach (ShiftProductionEntry::all() as $entry) {
            $this->assertSame(BatchStatus::Completed, $entry->batch_status);
            $this->assertSame('pending', $entry->status->value);
        }
    }

    public function test_the_kilograms_match_the_paper(): void
    {
        $this->page([$this->asb1()])->assertOk();

        $entry = ShiftProductionEntry::query()->sole();

        // 10,080 x 12.0 g = 120.96 kg and 237 x 12.0 g = 2.844 kg — the two
        // figures the supervisor wrote by hand, summing to the 123.80 he wrote
        // in the CONSUMPTION column.
        $this->assertSame(0, bccomp((string) $entry->quantity_produced_kg, '120.9600', 4));
        $this->assertSame(0, bccomp((string) $entry->quantity_rejection_kg, '2.8440', 4));
    }

    public function test_the_lumps_column_becomes_its_own_scrap_line(): void
    {
        $this->page([[
            'work_center_id' => $this->machines['ASB-2']->id,
            'item_id' => $this->products['90ml Rib C']->id,
            'quantity_produced' => 5280,
            'lumps_kg' => 1.23,
        ]])->assertOk();

        $scrap = ShiftProductionEntry::query()->sole()->scraps()->sole();

        $this->assertSame('lumps', $scrap->type->value);
        $this->assertSame(0, bccomp((string) $scrap->quantity_kg, '1.2300', 4));
    }

    public function test_a_blank_lumps_column_creates_no_scrap_line(): void
    {
        // The paper leaves it blank on most rows. A 0.0000 kg line would put an
        // empty population into the reconciliation and onto the voucher's note.
        $this->page([$this->asb1()])->assertOk();

        $this->assertSame(0, ShiftProductionEntry::query()->sole()->scraps()->count());
    }

    public function test_one_machine_may_run_a_second_product_after_a_mould_change(): void
    {
        // THE ROW THE OBVIOUS IDEMPOTENCY KEY WOULD HAVE EATEN. The real page for
        // 5 August shift A has twelve rows across ten machines: ASB-4 and ASB-7
        // each ran a second product. Keyed on date+shift+machine alone, the
        // twelfth row would have been refused as a duplicate of itself.
        $response = $this->page([
            [
                'work_center_id' => $this->machines['ASB-4']->id,
                'item_id' => $this->products['60 RA']->id,
                'quantity_produced' => 3675,
            ],
            [
                'work_center_id' => $this->machines['ASB-4']->id,
                'item_id' => $this->products['100 RC']->id,
                'quantity_produced' => 5750,
            ],
        ])->assertOk();

        $this->assertCount(2, $response->json('data.recorded'));
        $this->assertSame([], $response->json('data.skipped'));
        $this->assertSame(2, ShiftProductionEntry::query()->count());
    }

    public function test_submitting_the_same_page_twice_does_not_double_the_day(): void
    {
        // THE HAZARD A BULK ENDPOINT ACTUALLY HAS: a slow response, a second tap,
        // and the day's production doubles with no error anywhere. Both copies
        // look real; the first sign is a stock count weeks later.
        $this->page([$this->asb1()])->assertOk();
        $second = $this->page([$this->asb1()])->assertOk();

        $this->assertSame([], $second->json('data.recorded'));
        $this->assertCount(1, $second->json('data.skipped'));
        $this->assertStringContainsString('already recorded', $second->json('data.skipped.0.reason'));

        $this->assertSame(1, ShiftProductionEntry::query()->count());
    }

    public function test_a_cancelled_row_can_be_entered_again(): void
    {
        // A batch withdrawn as a mistake is a row that still needs entering.
        // Treating it as "already recorded" would leave a supervisor unable to
        // correct their own page.
        $this->page([$this->asb1()])->assertOk();
        ShiftProductionEntry::query()->sole()->forceFill([
            'batch_status' => BatchStatus::Cancelled->value,
        ])->save();

        $this->page([$this->asb1()])->assertOk()->assertJsonCount(1, 'data.recorded');

        $this->assertSame(2, ShiftProductionEntry::query()->count());
    }

    public function test_one_bad_row_does_not_lose_the_good_ones(): void
    {
        // A page-wide transaction would be the obvious choice and the wrong one.
        // Eleven good rows lost because the twelfth cannot be recorded means the
        // supervisor types the whole page again — while annoyed.
        //
        // The failure used here is a real one and needs no configuration to
        // provoke: a machine that still holds an unfinished batch. A supervisor
        // who forgot to complete yesterday's run and then types up today's page
        // hits exactly this, and startBatch refuses it because a machine can only
        // physically run one thing at a time.
        ShiftProductionEntry::create([
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machines['ASB-2']->id,
            'item_id' => $this->products['90ml Rib C']->id,
            'warehouse_id' => Warehouse::query()->value('id'),
            'production_date' => '2026-08-04',
            'batch_number' => '20260804-M02-001',
            'batch_status' => BatchStatus::InProgress,
            'status' => 'pending',
        ]);

        $response = $this->page([
            $this->asb1(),
            [
                'work_center_id' => $this->machines['ASB-2']->id,
                'item_id' => $this->products['60 RA']->id,
                'quantity_produced' => 100,
            ],
        ])->assertOk();

        $this->assertCount(1, $response->json('data.recorded'), 'The good row must still land.');
        $this->assertCount(1, $response->json('data.failed'));
        // Numbered as the supervisor sees the page, not as an array offset.
        $this->assertSame(2, $response->json('data.failed.0.row'));
        $this->assertNotEmpty($response->json('data.failed.0.reason'));

        // AND THE FAILED ROW LEFT NOTHING BEHIND. Its own transaction rolled
        // back, so the only new entry is the good one — a half-started batch
        // would hold the machine and block the retry.
        $this->assertSame(2, ShiftProductionEntry::query()->count(), 'The seeded in-progress batch, plus the one good row.');
        $this->assertSame(1, ShiftProductionEntry::query()
            ->whereDate('production_date', '2026-08-05')->count());
    }

    public function test_consumption_sent_with_a_row_is_booked_against_it(): void
    {
        $this->page([[
            ...$this->asb1(),
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'quantity_issued_kg' => 123.804],
            ],
        ]])->assertOk();

        $consumption = ShiftProductionEntry::query()->sole()->materialConsumptions()->sole();

        $this->assertSame($this->resin->id, $consumption->item_id);
        $this->assertSame(0, bccomp((string) $consumption->quantity_issued_kg, '123.8040', 4));
    }

    public function test_a_future_page_is_refused_as_a_page(): void
    {
        // Shape, not domain: a malformed request should fail once as a request
        // rather than as twelve identical row errors.
        $this->postJson('/api/v1/production/shift-production-entries/page', [
            'shift_id' => $this->shift->id,
            'production_date' => now()->addDay()->toDateString(),
            'rows' => [$this->asb1()],
        ])->assertStatus(422)->assertJsonValidationErrors('production_date');

        $this->assertSame(0, ShiftProductionEntry::query()->count());
    }
}
