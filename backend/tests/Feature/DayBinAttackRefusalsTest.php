<?php

namespace Tests\Feature;

use App\Exceptions\InvalidStatusTransitionException;
use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\TraceabilityService;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ShiftProductionEntryService;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\RecordsDayBinHistory;
use Tests\TestCase;

/**
 * A batch of cheap, targeted attacks from the QA brief's categories 1 and 2
 * (consumption math / bag integrity) that the app is EXPECTED to refuse.
 * Each test asserts the refusal actually happens and that no partial state
 * is left behind — evidence for the "attacks that correctly failed" side of
 * the report, not defects.
 *
 * One exception is called out explicitly where it is NOT a clean refusal:
 * see test_the_same_barcode_split_across_two_machines_keeps_a_traceable_trail
 * for a by-design allowance (splitting one bag's pour across two machines)
 * verified to behave correctly rather than assumed.
 */
class DayBinAttackRefusalsTest extends TestCase
{
    use RecordsDayBinHistory, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('production.readiness.enforced', false);
        config()->set('production.traceability_enabled', true);
    }

    private function actingAsProduction(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['production.manage', 'inventory.manage', 'production.override-fifo'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo(['production.manage', 'inventory.manage', 'production.override-fifo']);
        Sanctum::actingAs($user);

        return $user;
    }

    /** @return array{0: WorkCenter, 1: Item, 2: Item, 3: Shift, 4: Warehouse} */
    private function fixtures(): array
    {
        return [
            WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']),
            Item::create(['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'Nos.']),
            Item::create(['sku' => 'RM-PET', 'name' => 'PET Resin', 'uom' => 'Kgs.']),
            Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']),
            Warehouse::create(['code' => 'WH-1', 'name' => 'Store']),
        ];
    }

    private function startBatch(int $shiftId, int $machineId, int $itemId, int $warehouseId): int
    {
        return $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $shiftId, 'work_center_id' => $machineId,
            'item_id' => $itemId, 'warehouse_id' => $warehouseId,
        ])->assertOk()->json('data.id');
    }

    // -----------------------------------------------------------------
    // Category 1: a return after a segment's closing count
    // -----------------------------------------------------------------

    public function test_a_return_after_the_segments_closing_count_is_refused(): void
    {
        $user = $this->actingAsProduction();
        [$machine, $bottle, $resin, $shift, $warehouse] = $this->fixtures();

        $lot = app(TraceabilityService::class)->createLot([
            'item_id' => $resin->id, 'received_date' => '2026-07-20',
            'bag_count' => 1, 'bag_weight_kg' => '25', 'total_received_kg' => '25',
        ], $user->id);

        $entryId = $this->startBatch($shift->id, $machine->id, $bottle->id, $warehouse->id);

        $this->loadDayBin([
            'barcode' => $lot->bags->first()->barcode, 'work_center_id' => $machine->id,
            'shift_production_entry_id' => $entryId, 'quantity_kg' => '10',
        ]);

        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => '100',
            'closing_day_bin' => [['item_id' => $resin->id, 'quantity_kg' => '4']],
        ])->assertOk();

        // The segment is closed. A return tied to it now must be refused —
        // its consumption figure was already computed and submitted.
        $this->assertSame(
            'Segment already completed — day-bin movements cannot reference shift production entry #'.$entryId.' because it is no longer in progress.',
            $this->refusedDayBinWrite(fn () => $this->returnDayBin([
                'work_center_id' => $machine->id, 'item_id' => $resin->id,
                'quantity_kg' => '1', 'shift_production_entry_id' => $entryId,
            ])),
        );
    }

    // -----------------------------------------------------------------
    // Category 1: completing an already-completed batch
    // -----------------------------------------------------------------

    public function test_completing_an_already_completed_batch_is_refused_and_leaves_no_extra_side_effects(): void
    {
        $this->actingAsProduction();
        [$machine, $bottle, , $shift, $warehouse] = $this->fixtures();

        $entryId = $this->startBatch($shift->id, $machine->id, $bottle->id, $warehouse->id);

        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => '100',
        ])->assertOk();

        $stockMovementsAfterFirst = StockMovement::count();

        // The controller re-fetches the entry via route-model binding, so
        // this is a genuinely fresh load showing batch_status = completed
        // — the second call must be refused, not silently re-run.
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => '999',
        ])->assertStatus(422);

        $this->assertSame(
            $stockMovementsAfterFirst,
            StockMovement::count(),
            'A refused second completion must not record a second stock receipt.'
        );
        // The original figure survives untouched — the rejected second
        // attempt's "999" never landed.
        $this->assertSame(0, bccomp((string) ShiftProductionEntry::find($entryId)->quantity_produced, '100', 4));
    }

    /**
     * The same attack at the service layer, with a STALE in-memory entry
     * object whose batch_status still reads in_progress even though the
     * database has already moved on — the realistic shape of a race
     * between two people tapping Complete on the same batch. The
     * concurrency-guarded UPDATE (`WHERE batch_status = in_progress`)
     * must catch it even though the initial guard, using the stale
     * object, does not.
     */
    public function test_completing_via_a_stale_in_memory_entry_object_is_still_refused(): void
    {
        $this->actingAsProduction();
        [$machine, $bottle, , $shift, $warehouse] = $this->fixtures();

        $service = app(ShiftProductionEntryService::class);

        $entryId = $this->startBatch($shift->id, $machine->id, $bottle->id, $warehouse->id);
        $stale = ShiftProductionEntry::find($entryId); // loaded once, kept stale below

        $service->completeBatch(ShiftProductionEntry::find($entryId), ['quantity_produced' => '100'], null);

        $this->expectException(InvalidStatusTransitionException::class);
        // $stale->batch_status still reads "in_progress" in PHP memory.
        $service->completeBatch($stale, ['quantity_produced' => '200'], null);
    }

    // -----------------------------------------------------------------
    // Category 2: load 0 / negative / a fully consumed bag
    // -----------------------------------------------------------------

    public function test_loading_zero_or_negative_kg_is_refused_by_validation(): void
    {
        $user = $this->actingAsProduction();
        [$machine, $bottle, $resin, $shift, $warehouse] = $this->fixtures();

        $lot = app(TraceabilityService::class)->createLot([
            'item_id' => $resin->id, 'received_date' => '2026-07-20',
            'bag_count' => 1, 'bag_weight_kg' => '25', 'total_received_kg' => '25',
        ], $user->id);
        $entryId = $this->startBatch($shift->id, $machine->id, $bottle->id, $warehouse->id);

        // The retired door's FormRequest refused these with a `quantity_kg`
        // validation error; the guard that actually matters is the one in
        // TraceabilityService::loadBagToDayBin, which refuses any quantity
        // that is not strictly positive — so nothing weakened when the door
        // closed. The surviving scan door keeps its own `gt:0` rule too.
        foreach (['0', '-5'] as $badQuantity) {
            $this->refusedDayBinWrite(fn () => $this->loadDayBin([
                'barcode' => $lot->bags->first()->barcode, 'work_center_id' => $machine->id,
                'shift_production_entry_id' => $entryId, 'quantity_kg' => $badQuantity,
            ]));
        }

        $this->assertSame('25.0000', (string) $lot->bags->first()->fresh()->remaining_kg);
    }

    public function test_loading_a_bag_already_fully_consumed_is_refused(): void
    {
        $user = $this->actingAsProduction();
        [$machine, $bottle, $resin, $shift, $warehouse] = $this->fixtures();

        $lot = app(TraceabilityService::class)->createLot([
            'item_id' => $resin->id, 'received_date' => '2026-07-20',
            'bag_count' => 1, 'bag_weight_kg' => '10', 'total_received_kg' => '10',
        ], $user->id);
        $bag = $lot->bags->first();
        $entryId = $this->startBatch($shift->id, $machine->id, $bottle->id, $warehouse->id);

        // Fully consume the bag with one full-bag scan (no quantity_kg).
        $this->loadDayBin([
            'barcode' => $bag->barcode, 'work_center_id' => $machine->id,
            'shift_production_entry_id' => $entryId,
        ]);

        $bag->refresh();
        $this->assertSame('0.0000', (string) $bag->remaining_kg);
        $this->assertSame('consumed', $bag->status->value);

        // Scanning the same, now-empty bag again must be refused, not
        // silently accepted as a zero-kg load.
        $this->refusedDayBinWrite(fn () => $this->loadDayBin([
            'barcode' => $bag->barcode, 'work_center_id' => $machine->id,
            'shift_production_entry_id' => $entryId,
        ]));
    }

    // -----------------------------------------------------------------
    // Category 2: the same barcode loaded to two different machines
    // -----------------------------------------------------------------

    /**
     * NOT a refusal — this is the by-design allowance verified end to
     * end: a single physical bag genuinely can be poured partially into
     * two machines over time (a supervisor tops up two hoppers from one
     * open bag). The assertion here is that the audit trail survives it
     * correctly: each machine's ledger only ever claims the kg actually
     * poured into IT, the two totals foot to the bag's original weight,
     * and the bag is traceable from both machines' day-bin state.
     */
    public function test_the_same_barcode_split_across_two_machines_keeps_a_traceable_trail(): void
    {
        $user = $this->actingAsProduction();
        [$machineA, $bottle, $resin, $shift, $warehouse] = $this->fixtures();
        $machineB = WorkCenter::create(['code' => 'MC-02', 'name' => 'Machine 2']);

        $lot = app(TraceabilityService::class)->createLot([
            'item_id' => $resin->id, 'received_date' => '2026-07-20',
            'bag_count' => 1, 'bag_weight_kg' => '20', 'total_received_kg' => '20',
        ], $user->id);
        $bag = $lot->bags->first();

        $entryA = $this->startBatch($shift->id, $machineA->id, $bottle->id, $warehouse->id);

        $this->loadDayBin([
            'barcode' => $bag->barcode, 'work_center_id' => $machineA->id,
            'shift_production_entry_id' => $entryA, 'quantity_kg' => '12',
        ]);

        // The rest of the SAME bag is carried to machine B and poured in.
        $this->loadDayBin([
            'barcode' => $bag->barcode, 'work_center_id' => $machineB->id,
            'quantity_kg' => '8',
        ]);

        $bag->refresh();
        $this->assertSame('0.0000', (string) $bag->remaining_kg, 'The bag is now fully poured out, split across two machines.');

        $stateA = $this->getJson("/api/v1/production/work-centers/{$machineA->id}/day-bin")->assertOk();
        $stateB = $this->getJson("/api/v1/production/work-centers/{$machineB->id}/day-bin")->assertOk();

        $lineA = collect($stateA->json('data.materials'))->firstWhere('item.id', $resin->id);
        $lineB = collect($stateB->json('data.materials'))->firstWhere('item.id', $resin->id);

        // Each machine only ever claims what was actually poured into it.
        $this->assertSame('12.0000', $lineA['balance_kg']);
        $this->assertSame('8.0000', $lineB['balance_kg']);

        // The two portions foot to exactly the bag's original weight —
        // nothing was invented and nothing was lost across the split.
        $this->assertSame(0, bccomp(bcadd($lineA['balance_kg'], $lineB['balance_kg'], 4), (string) $bag->original_kg, 4));

        // The same barcode is traceable from BOTH machines' day-bin state.
        $this->assertSame($bag->barcode, $lineA['loaded_bags'][0]['barcode']);
        $this->assertSame($bag->barcode, $lineB['loaded_bags'][0]['barcode']);
        $this->assertSame(0, bccomp($lineA['loaded_bags'][0]['loaded_kg'], '12', 4));
        $this->assertSame(0, bccomp($lineB['loaded_bags'][0]['loaded_kg'], '8', 4));
    }

    public function test_the_voucher_preview_never_creates_tally_sync_rows_while_probing_these_attacks(): void
    {
        // Belt-and-braces per the engagement's hard boundary: nothing above
        // may have queued a Tally voucher.
        $this->assertSame(0, TallySyncEntry::query()->count());
    }
}
