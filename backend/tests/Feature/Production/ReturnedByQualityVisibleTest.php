<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Task 1 of the "Returned by Quality" plan (03-Sep-2026): the data behind a
 * Quality return already exists —
 * ShiftProductionEntryService::returnToProduction appends
 * {returned_by, returned_at, reason, cleared_quality_check} to
 * config_snapshot['quality_returns'] on every return — but nothing reads it
 * back. This proves ShiftProductionEntryResource now surfaces the LAST row
 * as `quality_return`, with the name resolved rather than a raw id, and
 * null when the batch has never been returned.
 *
 * NO RULE CHANGES ARE UNDER TEST HERE: who may return, when, and what a
 * return does to stock stay exactly BatchAmendmentAndQcReturnTest's
 * territory. This file only reads the payload.
 *
 * Fixture copied from BatchQualityStageTest's own setUp/actAs/completedBatch
 * helpers, per the task brief, rather than inventing a new one.
 */
class ReturnedByQualityVisibleTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Item $resin;

    private Warehouse $fg;

    private Warehouse $rm;

    private Shift $shift;

    private WorkCenter $machine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'is_active' => true]);

        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg']);
        $this->rm = Warehouse::create(['code' => 'RM', 'name' => 'RM Store', 'is_active' => true, 'tally_guid' => 'gd-rm']);

        $this->resin = Item::create([
            'sku' => 'PET-IV08', 'name' => 'Billion Pet Resin IV-0.8', 'uom' => 'Kgs.',
            'is_active' => true, 'tally_stock_item_guid' => 'itm-resin',
        ]);

        $this->bottle = Item::create([
            'sku' => 'BTL-500-AMB', 'name' => '500 ml Round Amber', 'uom' => 'Nos.',
            'is_active' => true, 'nominal_weight_grams' => '12.9000',
            'standard_cycle_time' => '12.00', 'standard_cavities' => 5, 'nos_per_box' => 800,
            'colour' => 'Amber', 'tally_stock_item_guid' => 'itm-bottle',
        ]);

        app(StockMovementService::class)->recordReceipt(
            itemId: $this->resin->id, warehouseId: $this->rm->id,
            quantity: '1000', unitCost: '0', reference: 'opening', createdBy: null,
        );
    }

    /** A fresh user every call, exactly as the live desks are different people. */
    private function actAs(string ...$roles): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $permissions = ['production.view', 'production.manage', 'quality.view', 'quality.manage'];
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);
        foreach ($roles as $role) {
            $user->assignRole(Role::findOrCreate($role, 'web'));
        }
        Sanctum::actingAs($user);

        return $user;
    }

    /** Start → complete, as the supervisor. Returns the entry id. */
    private function completedBatch(): int
    {
        $entryId = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => '2026-07-30',
        ])->assertOk()->json('data.id');

        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => '10000',
            'running_hours' => '8',
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'warehouse_id' => $this->rm->id, 'quantity_issued_kg' => '130'],
            ],
        ])->assertOk();

        return $entryId;
    }

    private function entryJson(int $entryId, string $status = 'pending'): array
    {
        return collect($this->getJson("/api/v1/production/shift-production-entries?status={$status}")->assertOk()->json('data'))
            ->firstWhere('id', $entryId);
    }

    public function test_a_batch_sent_back_by_quality_says_so_in_its_payload(): void
    {
        $this->actAs();
        $neverReturnedId = $this->completedBatch();

        $this->actAs();
        $entryId = $this->completedBatch();

        // The never-returned batch carries a null quality_return, both on
        // its own response shape and through the index (collection()).
        $this->assertNull($this->entryJson($neverReturnedId)['quality_return']);

        // ---- Quality checks the batch, then sends it back --------------
        $checker = $this->actAs();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/quality-check", [
            'reviewed_nos' => 10000,
            'ok_nos' => 9800,
            'rejected_nos' => 200,
            'note' => 'Short fill on two trays.',
        ])->assertOk();

        $firstReason = 'Box count on the sheet does not match the pallets on the floor — recount.';
        $returned = $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/return-to-production", [
            'reason' => $firstReason,
        ])->assertOk();

        // The return endpoint's own response is a single resource, not built
        // through collection() — this proves the name resolves with no page
        // of Tally-link-style precomputation to lean on.
        $this->assertSame($firstReason, $returned->json('data.quality_return.reason'));
        $this->assertSame($checker->name, $returned->json('data.quality_return.returned_by_name'));
        $this->assertSame(1, $returned->json('data.quality_return.times'));
        $this->assertNotNull($returned->json('data.quality_return.returned_at'));

        // The index — through collection(), the batch-safe path a page of
        // rows actually uses.
        $indexed = $this->entryJson($entryId);
        $this->assertSame($firstReason, $indexed['quality_return']['reason']);
        $this->assertSame($checker->name, $indexed['quality_return']['returned_by_name']);
        $this->assertSame(1, $indexed['quality_return']['times']);

        // The untouched batch is still null throughout.
        $this->assertNull($this->entryJson($neverReturnedId)['quality_return']);

        // ---- A second return: times increments, reason is the newer one ----
        $secondChecker = $this->actAs();
        $secondReason = 'Still short by a pallet — count the rejects too.';
        $twiceReturned = $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/return-to-production", [
            'reason' => $secondReason,
        ])->assertOk();

        $this->assertSame($secondReason, $twiceReturned->json('data.quality_return.reason'));
        $this->assertSame($secondChecker->name, $twiceReturned->json('data.quality_return.returned_by_name'));
        $this->assertSame(2, $twiceReturned->json('data.quality_return.times'));

        $indexedTwice = $this->entryJson($entryId);
        $this->assertSame($secondReason, $indexedTwice['quality_return']['reason']);
        $this->assertSame(2, $indexedTwice['quality_return']['times']);
    }
}
