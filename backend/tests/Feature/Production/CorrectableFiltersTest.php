<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Task 1 of the "Filters for Earlier batches — still correctable" plan
 * (03-Sep-2026): the list endpoint gains four filters — item_id, q (the
 * batch number), returned and sort — on top of the ones it already
 * accepts (production_date, date_from, date_to, work_center_id, shift_id,
 * batch_status, status, per_page, page, correctable, awaiting_correction).
 *
 * NO RULE CHANGE IS UNDER TEST HERE: canAmendCompletion, correctionLists()'s
 * parity guard and the server's own eligibility filter are untouched — this
 * only narrows which rows a page can contain, never who may act on one.
 *
 * Fixture pattern (actAs/completedBatch) copied from
 * ReturnedByQualityVisibleTest per the task brief, parametrised over two
 * machines, two shifts, two products and two production dates so every
 * filter has something to narrow away.
 */
class CorrectableFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Item $itemA;

    private Item $itemB;

    private Item $resin;

    private Warehouse $fg;

    private Warehouse $rm;

    private Shift $shift1;

    private Shift $shift2;

    private WorkCenter $machine1;

    private WorkCenter $machine2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shift1 = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->shift2 = Shift::create(['name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00']);

        $this->machine1 = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'is_active' => true]);
        $this->machine2 = WorkCenter::create(['code' => 'MC-02', 'name' => 'Machine 2', 'is_active' => true]);

        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg']);
        $this->rm = Warehouse::create(['code' => 'RM', 'name' => 'RM Store', 'is_active' => true, 'tally_guid' => 'gd-rm']);

        $this->resin = Item::create([
            'sku' => 'PET-IV08', 'name' => 'Billion Pet Resin IV-0.8', 'uom' => 'Kgs.',
            'is_active' => true, 'tally_stock_item_guid' => 'itm-resin',
        ]);

        $this->itemA = Item::create([
            'sku' => 'BTL-500-AMB', 'name' => '500 ml Round Amber', 'uom' => 'Nos.',
            'is_active' => true, 'nominal_weight_grams' => '12.9000',
            'standard_cycle_time' => '12.00', 'standard_cavities' => 5, 'nos_per_box' => 800,
            'colour' => 'Amber', 'tally_stock_item_guid' => 'itm-bottle-a',
        ]);

        $this->itemB = Item::create([
            'sku' => 'BTL-750-CLR', 'name' => '750 ml Round Clear', 'uom' => 'Nos.',
            'is_active' => true, 'nominal_weight_grams' => '13.0000',
            'standard_cycle_time' => '13.00', 'standard_cavities' => 4, 'nos_per_box' => 600,
            'colour' => 'Clear', 'tally_stock_item_guid' => 'itm-bottle-b',
        ]);

        app(StockMovementService::class)->recordReceipt(
            itemId: $this->resin->id, warehouseId: $this->rm->id,
            quantity: '2000', unitCost: '0', reference: 'opening', createdBy: null,
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

    /** Start → complete, on the given machine/shift/product/date. Returns id + batch_number. */
    private function completedBatch(WorkCenter $machine, Shift $shift, Item $item, string $productionDate): array
    {
        $this->actAs();

        $entryId = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => $productionDate,
        ])->assertOk()->json('data.id');

        $this->actAs();

        $completed = $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => '10000',
            'running_hours' => '8',
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'warehouse_id' => $this->rm->id, 'quantity_issued_kg' => '130'],
            ],
        ])->assertOk();

        return ['id' => $entryId, 'batch_number' => $completed->json('data.batch_number')];
    }

    /**
     * Four entries spanning both machines, both shifts, both products and
     * both dates, one of them sent back by Quality (returned, not yet
     * corrected — return-to-production alone is enough to set
     * config_snapshot['quality_returns'], which is all `returned` reads).
     *
     * Creation order fixes the id order, which — combined with
     * production_date — fixes the newest/oldest expectation below:
     *   e1  machine1 shift1 itemA 2026-07-28  (oldest, id 1)
     *   e2  machine2 shift2 itemB 2026-07-30  (returned by Quality, id 2)
     *   e3  machine1 shift2 itemB 2026-07-28  (id 3)
     *   e4  machine2 shift1 itemA 2026-07-30  (newest by date, id 4)
     *
     * newest-first (production_date desc, id desc): e4, e2, e3, e1
     * oldest-first (production_date asc, id asc):    e1, e3, e2, e4
     */
    private function seedEntries(): array
    {
        $e1 = $this->completedBatch($this->machine1, $this->shift1, $this->itemA, '2026-07-28');
        $e2 = $this->completedBatch($this->machine2, $this->shift2, $this->itemB, '2026-07-30');
        $e3 = $this->completedBatch($this->machine1, $this->shift2, $this->itemB, '2026-07-28');
        $e4 = $this->completedBatch($this->machine2, $this->shift1, $this->itemA, '2026-07-30');

        $this->actAs();
        $this->postJson("/api/v1/production/shift-production-entries/{$e2['id']}/return-to-production", [
            'reason' => 'Recount the boxes on this pallet — the sheet says 40, the floor has 38.',
        ])->assertOk();

        return compact('e1', 'e2', 'e3', 'e4');
    }

    private function list(array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/production/shift-production-entries?'.http_build_query($query))->assertOk();
    }

    /** @return list<int> */
    private function ids(array $query = []): array
    {
        return array_map(fn (array $row) => $row['id'], $this->list($query)->json('data'));
    }

    public function test_no_filters_returns_every_entry_newest_first(): void
    {
        $entries = $this->seedEntries();

        $this->assertSame(
            [$entries['e4']['id'], $entries['e2']['id'], $entries['e3']['id'], $entries['e1']['id']],
            $this->ids()
        );
    }

    public function test_item_id_narrows_to_that_products_batches_only(): void
    {
        $entries = $this->seedEntries();
        $default = $this->ids();

        $itemA = $this->ids(['item_id' => $this->itemA->id]);
        $this->assertSame([$entries['e4']['id'], $entries['e1']['id']], $itemA);
        $this->assertEmpty(array_diff($itemA, $default), 'item_id must never surface a row the default view did not have');

        $itemB = $this->ids(['item_id' => $this->itemB->id]);
        $this->assertSame([$entries['e2']['id'], $entries['e3']['id']], $itemB);
        $this->assertEmpty(array_diff($itemB, $default));
    }

    public function test_q_matches_the_batch_number_and_ignores_a_blank_value(): void
    {
        $entries = $this->seedEntries();
        $default = $this->ids();

        $matched = $this->ids(['q' => $entries['e3']['batch_number']]);
        $this->assertSame([$entries['e3']['id']], $matched);
        $this->assertEmpty(array_diff($matched, $default));

        // A substring is enough — the search box is not exact-match only.
        $substring = mb_substr($entries['e1']['batch_number'], 0, 8);
        $bySubstring = $this->ids(['q' => $substring]);
        $this->assertContains($entries['e1']['id'], $bySubstring);
        $this->assertEmpty(array_diff($bySubstring, $default));

        // A blank q is no filter at all.
        $this->assertSame($default, $this->ids(['q' => '']));
        $this->assertSame($default, $this->ids(['q' => '   ']));

        // A term matching nothing narrows to nothing, not to everything.
        $this->assertSame([], $this->ids(['q' => 'no-such-batch-anywhere']));
    }

    public function test_returned_keeps_only_the_batch_quality_sent_back(): void
    {
        $entries = $this->seedEntries();
        $default = $this->ids();

        $returned = $this->ids(['returned' => '1']);
        $this->assertSame([$entries['e2']['id']], $returned);
        $this->assertEmpty(array_diff($returned, $default));

        // Falsy / absent never turns the switch on.
        $this->assertSame($default, $this->ids(['returned' => '0']));
        $this->assertSame($default, $this->ids());
    }

    public function test_sort_oldest_reverses_the_default_order(): void
    {
        $entries = $this->seedEntries();

        $this->assertSame(
            [$entries['e1']['id'], $entries['e3']['id'], $entries['e2']['id'], $entries['e4']['id']],
            $this->ids(['sort' => 'oldest'])
        );

        // sort=newest is the explicit spelling of the default.
        $this->assertSame($this->ids(), $this->ids(['sort' => 'newest']));
    }

    public function test_an_unknown_sort_value_is_refused(): void
    {
        $this->seedEntries();
        $this->actAs();

        $this->getJson('/api/v1/production/shift-production-entries?sort=sideways')
            ->assertStatus(422);
    }

    public function test_combined_filters_narrow_further_than_either_alone(): void
    {
        $entries = $this->seedEntries();

        // itemB alone is [e2, e3]; adding returned=1 narrows it to just e2.
        $itemBReturned = $this->ids(['item_id' => $this->itemB->id, 'returned' => '1']);
        $this->assertSame([$entries['e2']['id']], $itemBReturned);
    }

    /**
     * The real screen never reads this endpoint bare — it reads
     * `status=pending&correctable=1` (listCorrectableEntries). Every filter
     * above is proven against the bare list; this pins the composition,
     * and the one thing that differs from the quality queue's own
     * `returned=1` (ReturnedByQualityVisibleTest): returnToProduction()
     * clears quality_checked_at unconditionally, so a batch Quality sent
     * back but the floor has not yet corrected is STILL a `correctable`
     * member here — it only leaves the quality QUEUE, whose own
     * whereAwaitingQualityCheck predicate this list does not use.
     */
    public function test_the_new_filters_narrow_the_real_screens_correctable_query(): void
    {
        $entries = $this->seedEntries();

        $base = $this->ids(['status' => 'pending', 'correctable' => '1']);
        $this->assertSame(
            [$entries['e4']['id'], $entries['e2']['id'], $entries['e3']['id'], $entries['e1']['id']],
            $base,
            'all four are pending, completed and unchecked — correctable=1 does not exclude any of them'
        );

        $returnedAndCorrectable = $this->ids(['status' => 'pending', 'correctable' => '1', 'returned' => '1']);
        $this->assertSame([$entries['e2']['id']], $returnedAndCorrectable);
        $this->assertEmpty(array_diff($returnedAndCorrectable, $base));

        $itemBAndCorrectable = $this->ids(['status' => 'pending', 'correctable' => '1', 'item_id' => $this->itemB->id]);
        $this->assertSame([$entries['e2']['id'], $entries['e3']['id']], $itemBAndCorrectable);
        $this->assertEmpty(array_diff($itemBAndCorrectable, $base));

        $oldestCorrectable = $this->ids(['status' => 'pending', 'correctable' => '1', 'sort' => 'oldest']);
        $this->assertSame(
            [$entries['e1']['id'], $entries['e3']['id'], $entries['e2']['id'], $entries['e4']['id']],
            $oldestCorrectable
        );
    }
}
