<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\DayBinMovement;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\DayBinLedgerService;
use App\Modules\Production\Services\ShiftProductionEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * HANDOVER WITHOUT A CLOSING COUNT — where the incoming shift's opening
 * comes from when nobody weighed the bin.
 *
 * openingFor() gives a handover child its parent's closing count and zero
 * when there is none. Zero is wrong at a handover, and wrong in the
 * expensive direction: the bin does not empty itself because the shift
 * changed, so the night shift inherited material the ledger then denied it
 * had, and its consumption came out understated by exactly the carry-over —
 * silently, because zero looks like a real figure.
 *
 * The fix derives the opening from the ledger (opening + loaded − returned
 * − consumed) when nothing was counted, names the basis it used, and never
 * overrides a figure a human put on a scale.
 */
class HandoverOpeningBasisTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Minimal fixtures (no Tally identity, no cycle time) — the
        // readiness gate is covered by its own suite, not this one.
        config()->set('production.readiness.enforced', false);
        config()->set('production.traceability_enabled', true);
    }

    /**
     * @return array{0: ShiftProductionEntry, 1: Shift, 2: Item, 3: WorkCenter, 4: Warehouse, 5: User}
     */
    private function runningBatch(): array
    {
        $n = ++self::$seq;
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.manage', 'web');
        $user->givePermissionTo('production.manage');
        Sanctum::actingAs($user);

        $morning = Shift::create(['name' => "Morning {$n}", 'start_time' => '06:00', 'end_time' => '14:00']);
        $evening = Shift::create(['name' => "Evening {$n}", 'start_time' => '14:00', 'end_time' => '22:00']);
        $machine = WorkCenter::create(['code' => "MC-{$n}", 'name' => "Machine {$n}"]);
        $warehouse = Warehouse::create(['code' => "WH-{$n}", 'name' => 'Store']);
        $bottle = Item::create([
            'sku' => "BTL-{$n}", 'name' => 'Bottle', 'uom' => 'NOS',
            'nominal_weight_grams' => '8', 'standard_cycle_time' => '10.6', 'standard_cavities' => 5,
        ]);
        $resin = Item::create(['sku' => "RM-PET-{$n}", 'name' => 'PET Resin', 'uom' => 'Kgs']);

        $entry = app(ShiftProductionEntryService::class)->startBatch([
            'shift_id' => $morning->id,
            'work_center_id' => $machine->id,
            'item_id' => $bottle->id,
            'warehouse_id' => $warehouse->id,
        ], $user->id);

        // The bin as the outgoing shift left it: two bags in (25 + 7.5),
        // 1.3 handed back to the store. 31.2 kg passed through the machine.
        $ledger = app(DayBinLedgerService::class);
        foreach ([['load', '25'], ['load', '7.5'], ['return', '1.3']] as [$type, $kg]) {
            $ledger->record([
                'work_center_id' => $machine->id,
                'item_id' => $resin->id,
                'shift_production_entry_id' => $entry->id,
                'type' => $type,
                'quantity_kg' => $kg,
                'recorded_by' => $user->id,
            ]);
        }

        return [$entry, $evening, $resin, $machine, $warehouse, $user];
    }

    public function test_no_closing_count_opens_the_child_at_the_ledger_balance(): void
    {
        [$entry, $evening, $resin, , $warehouse] = $this->runningBatch();

        // Nobody weighed the bin. The shift declares it issued 27 kg.
        $response = $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/handover", [
            'shift_id' => $evening->id,
            'completion' => [
                'quantity_produced' => '5880',
                'material_consumptions' => [[
                    'item_id' => $resin->id,
                    'warehouse_id' => $warehouse->id,
                    'quantity_issued_kg' => '27',
                ]],
            ],
        ])->assertSuccessful();

        $child = ShiftProductionEntry::query()->findOrFail($response->json('data.id'));
        $ledger = app(DayBinLedgerService::class);

        // 0 opening + 32.5 loaded − 1.3 returned − 27 consumed = 4.2 kg left
        // in the bin. THE PINNED FIGURE: before this fix the child opened at
        // 0.0000 and those 4.2 kg vanished from the incoming shift's account.
        $this->assertSame('4.2000', $ledger->openingFor($child, $resin->id));

        // And the parent's own consumption still reads back as exactly what
        // the shift declared — the derived closing is consistent with the
        // formula, not a figure bolted on beside it.
        $this->assertSame([
            'opening_kg' => '0.0000',
            'loaded_kg' => '32.5000',
            'returned_kg' => '1.3000',
            'closing_kg' => '4.2000',
            'consumed_kg' => '27.0000',
        ], $ledger->consumptionFor($entry->fresh(), $resin->id));
    }

    /**
     * Named for what it actually proves: the basis rides the returned and
     * persisted child segment. It is NOT an assertion about the HTTP body —
     * ShiftProductionEntryResource exposes no path to config_snapshot beyond
     * `colour`, so the JSON does not yet carry this. Don't read a green tick
     * here as the API contract being met.
     */
    public function test_the_handover_result_names_the_basis_on_the_child_segment(): void
    {
        [$entry, $evening, $resin, , $warehouse] = $this->runningBatch();

        $child = app(ShiftProductionEntryService::class)->handover($entry, [
            'shift_id' => $evening->id,
            'completion' => [
                'quantity_produced' => '5880',
                'material_consumptions' => [[
                    'item_id' => $resin->id,
                    'warehouse_id' => $warehouse->id,
                    'quantity_issued_kg' => '27',
                ]],
            ],
        ], null);

        $expected = [[
            'item_id' => $resin->id,
            'basis' => 'ledger',
            'opening_kg' => '4.2000',
        ]];

        // Named on the returned segment...
        $this->assertSame($expected, $child->config_snapshot['opening_day_bin_basis']);
        // ...and persisted, so it is still answerable weeks later.
        $this->assertSame(
            $expected,
            ShiftProductionEntry::findOrFail($child->id)->config_snapshot['opening_day_bin_basis'],
        );
    }

    public function test_a_weighed_closing_count_still_wins(): void
    {
        [$entry, $evening, $resin, , $warehouse] = $this->runningBatch();

        // The scale says 3.0, the ledger arithmetic would have said 4.2.
        // The scale is the physical fact and must not be overwritten.
        $child = app(ShiftProductionEntryService::class)->handover($entry, [
            'shift_id' => $evening->id,
            'closing_day_bin' => [['item_id' => $resin->id, 'quantity_kg' => '3.0']],
            'completion' => [
                'quantity_produced' => '5880',
                'material_consumptions' => [[
                    'item_id' => $resin->id,
                    'warehouse_id' => $warehouse->id,
                    'quantity_issued_kg' => '27',
                ]],
            ],
        ], null);

        $this->assertSame('3.0000', app(DayBinLedgerService::class)->openingFor($child, $resin->id));
        $this->assertSame([[
            'item_id' => $resin->id,
            'basis' => 'counted',
            'opening_kg' => '3.0000',
        ]], $child->config_snapshot['opening_day_bin_basis']);

        // Exactly one count row: nothing derived was written alongside it.
        $this->assertSame(1, DayBinMovement::query()
            ->where('shift_production_entry_id', $entry->id)
            ->where('type', 'count')
            ->count());
    }

    public function test_a_count_taken_mid_shift_counts_as_weighed_too(): void
    {
        [$entry, $evening, $resin, $machine, $warehouse, $user] = $this->runningBatch();

        // Counted from the floor screen during the shift, not in the
        // handover payload. The test is "has this segment a count", not
        // "did the handover form carry one".
        app(DayBinLedgerService::class)->record([
            'work_center_id' => $machine->id,
            'item_id' => $resin->id,
            'shift_production_entry_id' => $entry->id,
            'type' => 'count',
            'quantity_kg' => '5.0',
            'recorded_by' => $user->id,
        ]);

        $child = app(ShiftProductionEntryService::class)->handover($entry, [
            'shift_id' => $evening->id,
            'completion' => [
                'quantity_produced' => '5880',
                'material_consumptions' => [[
                    'item_id' => $resin->id,
                    'warehouse_id' => $warehouse->id,
                    'quantity_issued_kg' => '27',
                ]],
            ],
        ], null);

        $this->assertSame('5.0000', app(DayBinLedgerService::class)->openingFor($child, $resin->id));
        $this->assertSame('counted', $child->config_snapshot['opening_day_bin_basis'][0]['basis']);
    }

    public function test_nothing_consumed_carries_the_whole_bin_forward(): void
    {
        [$entry, $evening, $resin] = $this->runningBatch();

        // No consumption lines at all. The ledger knows 31.2 kg went into
        // the bin and nothing came out of it, so 31.2 kg is what the next
        // shift inherits — not zero.
        $child = app(ShiftProductionEntryService::class)->handover($entry, [
            'shift_id' => $evening->id,
            'completion' => ['quantity_produced' => '5880'],
        ], null);

        $this->assertSame('31.2000', app(DayBinLedgerService::class)->openingFor($child, $resin->id));
    }

    public function test_issuing_more_than_the_bin_held_opens_the_child_at_zero_never_negative(): void
    {
        [$entry, $evening, $resin, , $warehouse] = $this->runningBatch();

        // 40 kg issued against a bin the ledger has 31.2 kg in — the stock
        // record is what is wrong (a missed receipt, an opening balance
        // never entered). The next shift starts from an empty bin, and a
        // negative balance is never handed forward.
        $child = app(ShiftProductionEntryService::class)->handover($entry, [
            'shift_id' => $evening->id,
            'completion' => [
                'quantity_produced' => '5880',
                'material_consumptions' => [[
                    'item_id' => $resin->id,
                    'warehouse_id' => $warehouse->id,
                    'quantity_issued_kg' => '40',
                ]],
            ],
        ], null);

        $this->assertSame('0.0000', app(DayBinLedgerService::class)->openingFor($child, $resin->id));
        $this->assertSame('ledger', $child->config_snapshot['opening_day_bin_basis'][0]['basis']);
    }

    public function test_a_derived_count_records_no_witness(): void
    {
        [$entry, $evening, $resin, , $warehouse, $user] = $this->runningBatch();

        app(ShiftProductionEntryService::class)->handover($entry, [
            'shift_id' => $evening->id,
            'completion' => [
                'quantity_produced' => '5880',
                'material_consumptions' => [[
                    'item_id' => $resin->id,
                    'warehouse_id' => $warehouse->id,
                    'quantity_issued_kg' => '27',
                ]],
            ],
        ], $user->id);

        // recorded_by stays null: a derived figure has no witness, and
        // stamping the handover's user on it would dress an inference up as
        // an observation somebody made with a scale.
        $derived = DayBinMovement::query()
            ->where('shift_production_entry_id', $entry->id)
            ->where('type', 'count')
            ->sole();

        $this->assertNull($derived->recorded_by);
        $this->assertSame(0, bccomp((string) $derived->quantity_kg, '4.2', 4));
    }

    public function test_the_carry_over_survives_a_second_handover(): void
    {
        // THE THREE-SHIFT DAY, which is the ordinary one. Morning loads the
        // bin and hands over; afternoon loads NOTHING, just runs the resin
        // it inherited, and hands over again. The afternoon segment owns no
        // day-bin movements of its own, so a fix that only looks at "what
        // moved in this segment" would find nothing to carry and open the
        // night shift at zero — the very defect this exists to remove,
        // resurfacing one link down the chain.
        [$entryA, $evening, $resin, , $warehouse, $user] = $this->runningBatch();
        $service = app(ShiftProductionEntryService::class);
        $ledger = app(DayBinLedgerService::class);

        $night = Shift::create(['name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00']);

        $b = $service->handover($entryA, [
            'shift_id' => $evening->id,
            'completion' => [
                'quantity_produced' => '5880',
                'material_consumptions' => [[
                    'item_id' => $resin->id,
                    'warehouse_id' => $warehouse->id,
                    'quantity_issued_kg' => '27',
                ]],
            ],
        ], $user->id);

        $this->assertSame('4.2000', $ledger->openingFor($b, $resin->id));

        // Afternoon: nothing loaded, 2 kg consumed, nobody weighed anything.
        $c = $service->handover($b, [
            'shift_id' => $night->id,
            'completion' => [
                'quantity_produced' => '400',
                'material_consumptions' => [[
                    'item_id' => $resin->id,
                    'warehouse_id' => $warehouse->id,
                    'quantity_issued_kg' => '2.0',
                ]],
            ],
        ], $user->id);

        // 4.2 inherited − 2.0 consumed = 2.2 kg still in the bin.
        $this->assertSame('2.2000', $ledger->openingFor($c, $resin->id));
        $this->assertSame([[
            'item_id' => $resin->id,
            'basis' => 'ledger',
            'opening_kg' => '2.2000',
        ]], $c->config_snapshot['opening_day_bin_basis']);
    }

    public function test_a_handover_with_no_bin_activity_is_untouched(): void
    {
        // The regression guard for every existing handover: a run with no
        // day-bin movements derives nothing, records nothing, and reports an
        // empty basis rather than inventing a material.
        $n = ++self::$seq;
        $user = User::factory()->create(['is_active' => true]);
        $morning = Shift::create(['name' => "M{$n}", 'start_time' => '06:00', 'end_time' => '14:00']);
        $evening = Shift::create(['name' => "E{$n}", 'start_time' => '14:00', 'end_time' => '22:00']);
        $machine = WorkCenter::create(['code' => "MCX-{$n}", 'name' => "Machine X{$n}"]);
        $warehouse = Warehouse::create(['code' => "WHX-{$n}", 'name' => 'Store']);
        $bottle = Item::create(['sku' => "BTLX-{$n}", 'name' => 'Bottle', 'uom' => 'NOS']);

        $service = app(ShiftProductionEntryService::class);
        $entry = $service->startBatch([
            'shift_id' => $morning->id, 'work_center_id' => $machine->id,
            'item_id' => $bottle->id, 'warehouse_id' => $warehouse->id,
        ], $user->id);

        $child = $service->handover($entry, [
            'shift_id' => $evening->id,
            'completion' => ['quantity_produced' => '100'],
        ], $user->id);

        $this->assertSame([], $child->config_snapshot['opening_day_bin_basis']);
        $this->assertSame(0, DayBinMovement::query()->count());
    }
}
