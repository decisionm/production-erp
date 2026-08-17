<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Http\Resources\ShiftProductionEntryResource;
use App\Modules\Production\Models\BatchResinAllocation;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\BagCostAllocationService;
use App\Modules\Production\Services\ShiftProductionEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 7 (P7-03 (e)) — the two per-row COST reads on the entries index
 * are batched: `material_cost` is priced for the whole page off ONE
 * stock_movements read (StockMovementService::issuesForReferences →
 * ShiftProductionEntryService::materialCosts) and `batch_cost` reads the
 * page's live allocation rows ONCE (BagCostAllocationService::forEntries),
 * each row handed its own answer by ShiftProductionEntryResource::
 * collection(). Before: one stock_movements read AND one
 * batch_resin_allocations read PER ROW (a 60-row Completed Today page
 * paid 120 of them; a finance reader 180, with the allocation items).
 *
 *   - the whole page's query count is the SAME for 1 and for 60 completed
 *     entries, for a floor reader and for a finance reader (RED before:
 *     +2 / +3 per row);
 *   - the Phase 5.5 pin — tally_sync_entries reads ≤ 3 per page — holds;
 *   - the output is BYTE-IDENTICAL to the per-row path: every row of the
 *     page's collection equals the same entry rendered on its own through
 *     ShiftProductionEntryResource::make (which keeps the per-row reads),
 *     including the finance-only rate keys and the unpriced-line rule;
 *   - the two batch reads answer exactly what the single reads answer.
 */
class EntriesIndexCostReadsTest extends TestCase
{
    use RefreshDatabase;

    private Shift $shift;

    private WorkCenter $machine;

    private Item $bottle;

    private Item $resin;

    private Item $masterbatch;

    private Warehouse $fg;

    private Warehouse $rm;

    private Warehouse $emptyBin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-16 09:00:00');

        foreach (['production.view', 'production.manage', 'finance.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->shift = Shift::create(['name' => 'Day', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'display_sequence' => 1]);
        $this->fg = Warehouse::create(['code' => 'WH-FG', 'name' => 'FG Store']);
        $this->rm = Warehouse::create(['code' => 'WH-RM', 'name' => 'RM Store']);
        // A bin that never received anything: an issue out of it runs it
        // negative and stamps unit_cost 0.0000 — the ABSENCE of a price.
        $this->emptyBin = Warehouse::create(['code' => 'WH-EMPTY', 'name' => 'Empty Bin']);
        $this->bottle = Item::create(['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'pcs', 'nominal_weight_grams' => '12']);
        $this->resin = Item::create(['sku' => 'RM-PET', 'name' => 'PET Resin', 'uom' => 'Kgs']);
        $this->masterbatch = Item::create(['sku' => 'RM-MB', 'name' => 'Masterbatch', 'uom' => 'Kgs']);

        // Priced stock to issue from — the moving average each issue stamps.
        $stock = app(StockMovementService::class);
        $stock->recordReceipt($this->resin->id, $this->rm->id, '100000', '92.5000', 'GRN seed');
        $stock->recordReceipt($this->masterbatch->id, $this->rm->id, '10000', '310.0000', 'GRN seed');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function actAs(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo($permissions);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * A completed entry priced the way completeBatch leaves one: two
     * consumption lines, each with its issue movement stamped "SPE #{id}",
     * a pooled-resin allocation row (so batch_cost has both buckets), and —
     * on every third entry — an unpriced masterbatch line (issued out of a
     * bin with no recorded stock, the shortfall frozen on the snapshot, the
     * issue stamped 0.0000), so the null-total rule is in the fixture too.
     */
    private function completedEntry(int $sequence): ShiftProductionEntry
    {
        $unpriced = $sequence % 3 === 0;

        $entry = ShiftProductionEntry::create([
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => '2026-08-16',
            'batch_number' => sprintf('20260816-M01-%03d', $sequence),
            'batch_status' => BatchStatus::Completed,
            'quantity_produced' => '1000',
            'quantity_scrap' => '0',
            'status' => ShiftProductionEntryStatus::Pending,
            'config_snapshot' => $unpriced ? ['stock_shortfalls' => [[
                'item_id' => $this->masterbatch->id, 'item_name' => 'Masterbatch', 'item_uom' => 'Kgs',
                'warehouse_id' => $this->emptyBin->id, 'warehouse_name' => 'Empty Bin', 'short_kg' => '0.2500',
            ]]] : null,
        ]);

        $masterbatchBin = $unpriced ? $this->emptyBin : $this->rm;
        $entry->materialConsumptions()->create(['item_id' => $this->resin->id, 'warehouse_id' => $this->rm->id, 'quantity_issued_kg' => '12.5000']);
        $entry->materialConsumptions()->create(['item_id' => $this->masterbatch->id, 'warehouse_id' => $masterbatchBin->id, 'quantity_issued_kg' => '0.2500']);

        $stock = app(StockMovementService::class);
        $stock->recordIssue($this->resin->id, $this->rm->id, '12.5000', "SPE #{$entry->id}");
        // The unpriced line's issue runs the empty bin negative (as
        // completeBatch does under allow_negative_on_completion) and
        // stamps 0.0000: ZERO IS NOT A PRICE.
        $stock->recordIssue($this->masterbatch->id, $masterbatchBin->id, '0.2500', "SPE #{$entry->id}", allowNegative: $unpriced);

        BatchResinAllocation::create([
            'shift_production_entry_id' => $entry->id, 'allocation_run' => 1,
            'item_id' => $this->resin->id, 'quantity_kg' => '12.5000',
            'rate_per_kg' => '91.0000', 'amount' => '1137.5000',
            'rate_source' => BagCostAllocationService::SOURCE_POOL_AVERAGE,
        ]);
        // A reversed run beside it — history, never arithmetic (scopeLive).
        BatchResinAllocation::create([
            'shift_production_entry_id' => $entry->id, 'allocation_run' => 0,
            'item_id' => $this->resin->id, 'quantity_kg' => '12.5000',
            'rate_per_kg' => '80.0000', 'amount' => '1000.0000',
            'rate_source' => BagCostAllocationService::SOURCE_POOL_AVERAGE,
            'reversed_at' => '2026-08-16 08:00:00',
        ]);

        return $entry;
    }

    /** @return array{0: int, 1: int, 2: array<int, array<string, mixed>>} [total queries, tally_sync_entries queries, rows] */
    private function measure(int $rows): array
    {
        ShiftProductionEntry::query()->forceDelete();
        BatchResinAllocation::query()->delete();
        foreach (range(1, $rows) as $i) {
            $this->completedEntry($i);
        }

        // Warm the once-per-process reads (permissions, the BOM memo) so
        // both measurements see the same steady state.
        $this->getJson('/api/v1/production/shift-production-entries?per_page=100')->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson('/api/v1/production/shift-production-entries?per_page=100')->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount($rows, $response->json('data'));

        $tally = count(array_filter($log, fn (array $query) => str_contains($query['query'], 'tally_sync_entries')));

        return [count($log), $tally, $response->json('data')];
    }

    public function test_the_page_costs_the_same_number_of_queries_for_one_and_for_sixty_completed_entries(): void
    {
        $this->actAs(['production.view', 'production.manage']);

        [$forOne, $tallyForOne, $one] = $this->measure(1);
        [$forSixty, $tallyForSixty, $sixty] = $this->measure(60);

        // The rows carry real, priced figures — this is not a page of nulls.
        $this->assertSame('1233.7500', $one[0]['material_cost']['total_cost'], '12.5 × 92.5 + 0.25 × 310');
        $this->assertSame('1137.5000', $one[0]['batch_cost']['resin_cost']);
        $this->assertSame('77.5000', $one[0]['batch_cost']['other_cost'], 'the masterbatch line, priced at its issue cost');
        $this->assertSame('1215.0000', $one[0]['batch_cost']['material_cost_total']);
        $this->assertNull($sixty[0]['material_cost']['total_cost'], 'entry #60 has the unpriced masterbatch line (60 % 3 === 0): total null, never a partial figure');
        $this->assertNull($sixty[0]['batch_cost']['material_cost_total']);
        $this->assertNotNull($sixty[1]['material_cost']['total_cost']);

        // The floor reader sees no rate on either block (FC-06) — the batch
        // path must gate exactly as the single path did.
        foreach ($sixty as $row) {
            foreach ($row['material_cost']['lines'] as $line) {
                $this->assertArrayNotHasKey('unit_cost', $line);
                $this->assertArrayNotHasKey('cost', $line);
            }
            $this->assertArrayNotHasKey('allocations', $row['batch_cost']);
        }

        $this->assertSame($forOne, $forSixty, "the page's query count must not grow with the rows ({$forOne} for 1 row, {$forSixty} for 60)");
        // The Phase 5.5 pin stays.
        $this->assertSame($tallyForOne, $tallyForSixty);
        $this->assertLessThanOrEqual(3, $tallyForSixty, 'tally_sync_entries reads ≤ 3 per page (Phase 5.5)');
    }

    public function test_a_finance_reader_pays_the_same_constant_and_sees_every_rate(): void
    {
        $this->actAs(['production.view', 'production.manage', 'finance.view']);

        [$forOne, , $one] = $this->measure(1);
        [$forSixty, , $sixty] = $this->measure(60);

        $this->assertSame('92.5000', $one[0]['material_cost']['lines'][0]['unit_cost']);
        $this->assertSame('1156.2500', $one[0]['material_cost']['lines'][0]['cost']);
        $this->assertSame('91.0000', $one[0]['batch_cost']['allocations'][0]['pool_rate']);
        $this->assertSame('PET Resin', $one[0]['batch_cost']['allocations'][0]['item_name'], 'the allocation item rides the batch read');
        $this->assertCount(1, $one[0]['batch_cost']['allocations'], 'the reversed run is history, never a line');
        $this->assertSame('Masterbatch', $one[0]['batch_cost']['other_lines'][0]['item_name']);
        // The unpriced line: unit_cost null (zero is not a price), never 0.0000.
        $this->assertNull($sixty[0]['material_cost']['lines'][1]['unit_cost']);
        $this->assertNull($sixty[0]['material_cost']['lines'][1]['cost']);

        $this->assertSame($forOne, $forSixty, "a finance reader's page must not grow with the rows either ({$forOne} for 1 row, {$forSixty} for 60)");
    }

    /**
     * The golden compare: the page (batched reads) against every row
     * rendered on its own through make() (the per-row reads, untouched) —
     * byte for byte, for both readers. `as_of` is now(), frozen in setUp.
     */
    public function test_the_page_is_byte_identical_to_the_per_row_path_for_both_readers(): void
    {
        $entries = collect(range(1, 7))->map(fn (int $i) => $this->completedEntry($i));
        // A running batch on the same page: null costs on both paths.
        $entries->push(ShiftProductionEntry::create([
            'shift_id' => $this->shift->id, 'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id, 'warehouse_id' => $this->fg->id,
            'production_date' => '2026-08-16', 'batch_number' => '20260816-M01-999',
            'batch_status' => BatchStatus::InProgress, 'quantity_produced' => null, 'quantity_scrap' => '0',
        ]));

        foreach ([['production.view'], ['production.view', 'finance.view']] as $permissions) {
            $user = $this->actAs($permissions);
            $request = Request::create('/api/v1/production/shift-production-entries', 'GET');
            $request->setUserResolver(fn () => $user);

            $models = app(ShiftProductionEntryService::class)->paginate(perPage: 100)->getCollection()->values();
            $this->assertCount(8, $models);

            $page = ShiftProductionEntryResource::collection($models)->resolve($request);
            foreach ($models as $i => $model) {
                $alone = ShiftProductionEntryResource::make($model)->resolve($request);
                $this->assertSame(
                    json_encode($alone, JSON_THROW_ON_ERROR),
                    json_encode($page[$i], JSON_THROW_ON_ERROR),
                    "row {$model->batch_number} for ".implode('+', $permissions),
                );
            }
        }
    }

    public function test_the_batch_reads_answer_exactly_what_the_single_reads_answer(): void
    {
        $entries = collect(range(1, 4))->map(fn (int $i) => $this->completedEntry($i));
        $running = ShiftProductionEntry::create([
            'shift_id' => $this->shift->id, 'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id, 'warehouse_id' => $this->fg->id,
            'production_date' => '2026-08-16', 'batch_number' => '20260816-M01-999',
            'batch_status' => BatchStatus::InProgress, 'quantity_produced' => null, 'quantity_scrap' => '0',
        ]);

        $service = app(ShiftProductionEntryService::class);
        $stock = app(StockMovementService::class);
        $bags = app(BagCostAllocationService::class);

        // materialCosts ≡ materialCost per entry (null for the running one).
        $batched = $service->materialCosts($entries->concat([$running]));
        $this->assertSame([...$entries->pluck('id')->map(fn ($id) => (int) $id)->all(), $running->id], array_keys($batched));
        foreach ($entries as $entry) {
            $this->assertSame($service->materialCost($entry->fresh()), $batched[$entry->id], $entry->batch_number);
        }
        $this->assertNull($batched[$running->id]);

        // issuesForReferences ≡ issuesForReference per reference, in id order.
        $references = $entries->map(fn ($e) => "SPE #{$e->id}")->all();
        $grouped = $stock->issuesForReferences([...$references, 'SPE #0 (no such batch)']);
        $this->assertSame($references, array_keys($grouped));
        foreach ($references as $reference) {
            $this->assertSame(
                $stock->issuesForReference($reference)->pluck('id')->all(),
                $grouped[$reference]->pluck('id')->all(),
            );
        }
        $this->assertSame([], $stock->issuesForReferences([]));

        // forEntries ≡ summary()'s own live read: the reversed run excluded,
        // the item loaded, an entry with no rows an empty collection.
        $rows = $bags->forEntries([...$entries->pluck('id')->all(), $running->id]);
        $this->assertCount(5, $rows);
        foreach ($entries as $entry) {
            $this->assertSame(
                BatchResinAllocation::query()->live()->where('shift_production_entry_id', $entry->id)->orderBy('id')->pluck('id')->all(),
                $rows[$entry->id]->pluck('id')->all(),
            );
            $this->assertTrue($rows[$entry->id]->first()->relationLoaded('item'));
            $this->assertSame(
                $bags->summary($entry, withDetail: true),
                $bags->summary($entry, withDetail: true, liveRows: $rows[$entry->id]),
            );
        }
        $this->assertTrue($rows[$running->id]->isEmpty());
        $this->assertSame([], $bags->forEntries([]));
    }
}
