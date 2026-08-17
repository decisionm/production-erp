<?php

namespace Tests\Feature\Configuration;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\DowntimeReason;
use App\Modules\Production\Models\MasterbatchDosing;
use App\Support\Configuration\ConfigurationInUseException;
use App\Support\Configuration\ConfigurationLifecycle;
use App\Support\Configuration\DependencyCheck;
use App\Support\Configuration\ManagesConfigurationLifecycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * T17 — the Configuration Lifecycle Contract's ERROR CONTRACT and `can`
 * block, tested on the shared mechanism itself. NOTHING is wired to a
 * module here: every lifecycle in this file is declared inside the test,
 * because Phase 7.6 WS-A ships the mechanism and its proof, not an entity.
 *
 * What is pinned:
 *   - the 422 body: a stable `code`, a non-empty `blocking` list whose
 *     counts are INTEGERS, and the `alternative` the UI offers instead;
 *   - `can` carries exactly {edit, activate, archive, delete}, with
 *     delete === null meaning "undetermined — ask", never false;
 *   - the delete is REAL once the locked report is clear — a proven-unused
 *     row is gone even under withTrashed(), which is what frees its business
 *     code (DEC-20260817-002 §§1-2) — while a REFERENCED row is never
 *     force-deleted and its cascade-side children survive untouched;
 *   - the served report is ADVISORY: delete() re-runs it inside its own
 *     transaction, on a freshly locked row, and refuses if anything
 *     appeared in between;
 *   - a cascade-side child counts even when it is soft-deleted (a real
 *     DELETE would destroy that physical row — the audit's asymmetry);
 *   - a check that cannot prove non-use blocks exactly like a positive
 *     count (DEC-20260817-002 §5, fail-closed).
 */
class ConfigurationContractTest extends TestCase
{
    use RefreshDatabase;

    private function warehouse(string $code = 'WH-1'): Warehouse
    {
        return Warehouse::create(['code' => $code, 'name' => 'Store '.$code, 'is_active' => true]);
    }

    private function item(string $sku = 'ITM-1'): Item
    {
        return Item::create(['sku' => $sku, 'name' => 'Item '.$sku, 'uom' => 'Nos', 'is_active' => true]);
    }

    /** The lifecycle under test: a warehouse blocked by its stock balances. */
    private function warehouseLifecycle(): ConfigurationLifecycle
    {
        return new ConfigurationLifecycle(
            label: 'warehouse',
            checks: [
                DependencyCheck::table('stock_balances', 'warehouse_id')
                    ->label('stock balance')
                    ->cascadeSide(),
            ],
        );
    }

    public function test_a_blocked_delete_renders_the_422_error_contract(): void
    {
        $warehouse = $this->warehouse();
        StockBalance::create(['item_id' => $this->item()->id, 'warehouse_id' => $warehouse->id, 'quantity' => '5']);
        StockBalance::create(['item_id' => $this->item('ITM-2')->id, 'warehouse_id' => $warehouse->id, 'quantity' => '7']);

        $lifecycle = $this->warehouseLifecycle();
        Route::middleware('api')->delete(
            'api/v1/__configuration-contract/warehouses/{warehouse}',
            function (Warehouse $warehouse) use ($lifecycle) {
                $lifecycle->delete($warehouse);

                return response()->noContent();
            },
        );

        $response = $this->deleteJson("/api/v1/__configuration-contract/warehouses/{$warehouse->id}");

        $response->assertStatus(422);
        $body = $response->json();

        $this->assertSame('configuration_in_use', $body['code']);
        $this->assertSame(
            'Cannot delete warehouse "Store WH-1" — used by 2 stock balances. Deactivate instead.',
            $body['message'],
        );
        $this->assertSame('archive', $body['alternative']);
        $this->assertNotEmpty($body['blocking']);
        $this->assertSame(['code', 'label', 'count'], array_keys($body['blocking'][0]));
        $this->assertSame('stock_balances', $body['blocking'][0]['code']);
        $this->assertSame('stock balances', $body['blocking'][0]['label']);
        $this->assertIsInt($body['blocking'][0]['count']);
        $this->assertSame(2, $body['blocking'][0]['count']);

        // Refused means refused: the row is untouched, trashed or otherwise.
        $this->assertNotNull(Warehouse::withTrashed()->find($warehouse->id));
        $this->assertSame(2, StockBalance::query()->where('warehouse_id', $warehouse->id)->count());
    }

    public function test_the_sentence_pluralises_to_the_count_and_joins_several_reasons(): void
    {
        $warehouse = $this->warehouse();
        StockBalance::create(['item_id' => $this->item()->id, 'warehouse_id' => $warehouse->id, 'quantity' => '5']);

        $lifecycle = new ConfigurationLifecycle(
            label: 'warehouse',
            checks: [
                DependencyCheck::table('stock_balances', 'warehouse_id')->label('stock balance')->cascadeSide(),
                DependencyCheck::callable(fn (Model $model) => 2, 'production_batches')->label('production batch'),
            ],
        );

        $this->assertSame(
            'used by 1 stock balance and 2 production batches',
            $lifecycle->report($warehouse)->sentence(),
        );
    }

    public function test_the_can_block_prints_exactly_the_four_abilities(): void
    {
        $warehouse = $this->warehouse();
        $lifecycle = $this->warehouseLifecycle();

        $resource = new class($warehouse) extends JsonResource
        {
            /** @var array<string, bool|null> */
            public array $abilities = [];

            /** @return array<string, mixed> */
            public function toArray($request): array
            {
                return ['id' => $this->resource->getKey(), 'can' => $this->abilities];
            }
        };
        $resource->abilities = $lifecycle->abilities($warehouse);

        $can = $resource->toArray(Request::create('/'))['can'];

        $this->assertSame(['edit', 'activate', 'archive', 'delete'], array_keys($can));
        $this->assertTrue($can['edit']);
        $this->assertFalse($can['activate']);
        $this->assertTrue($can['archive']);
        $this->assertTrue($can['delete']);
    }

    public function test_an_unresolved_delete_is_null_and_never_false(): void
    {
        $warehouse = $this->warehouse();
        StockBalance::create(['item_id' => $this->item()->id, 'warehouse_id' => $warehouse->id, 'quantity' => '5']);

        $cheap = $this->warehouseLifecycle()->abilities($warehouse, resolveDelete: false);
        $authoritative = $this->warehouseLifecycle()->abilities($warehouse);

        $this->assertSame(['edit', 'activate', 'archive', 'delete'], array_keys($cheap));
        $this->assertNull($cheap['delete']);
        $this->assertFalse($authoritative['delete']);
    }

    public function test_a_clear_report_deletes_a_plain_row_outright(): void
    {
        $reason = DowntimeReason::create(['code' => 'DT-1', 'description' => 'Mould change']);
        $lifecycle = new ConfigurationLifecycle(
            label: 'downtime reason',
            checks: [
                DependencyCheck::table('production_downtime_events', 'downtime_reason_id')
                    ->label('downtime event')
                    ->cascadeSide(),
            ],
            nameUsing: fn (DowntimeReason $model) => $model->description,
        );

        $lifecycle->delete($reason);

        $this->assertSame(0, DowntimeReason::query()->where('id', $reason->id)->count());
    }

    /**
     * A proven-unused SoftDeletes master is REALLY gone, and its business
     * code is therefore free again.
     *
     * DEC-20260817-002 §1 requires a genuine hard delete for a record proven
     * never-used, and §2 releases the code "once the row is gone". A soft
     * delete would retain the row and keep reserving the code, satisfying
     * neither — so the delete is real once, and only once, the locked report
     * is clear. That guard is what makes it safe: a clear report means there
     * is no child for a cascade to reach.
     */
    public function test_a_proven_unused_soft_deletes_master_is_really_gone_and_frees_its_code(): void
    {
        $warehouse = $this->warehouse();
        $code = $warehouse->code;

        $this->warehouseLifecycle()->delete($warehouse);

        // Not merely trashed — gone, including from withTrashed().
        $this->assertNull(Warehouse::withTrashed()->find($warehouse->id));
        $this->assertSame(0, Warehouse::withTrashed()->where('code', $code)->count());

        // And the code can be used again, which is the point of §2.
        $reborn = Warehouse::create(['code' => $code, 'name' => 'A new store at the same code', 'is_active' => true]);
        $this->assertNotNull($reborn->id);
    }

    /**
     * The other half, and the one that matters for safety: a record that is
     * REFERENCED is never force-deleted. The prohibition was never on
     * forceDelete() itself — it is on using it to bypass a check.
     */
    public function test_a_referenced_master_is_never_force_deleted(): void
    {
        $warehouse = $this->warehouse();
        StockBalance::create(['item_id' => $this->item()->id, 'warehouse_id' => $warehouse->id, 'quantity' => '5']);

        try {
            $this->warehouseLifecycle()->delete($warehouse);
            $this->fail('Expected a referenced master to be refused.');
        } catch (ConfigurationInUseException) {
            // expected
        }

        $this->assertNotNull(Warehouse::withTrashed()->find($warehouse->id), 'the row survives untouched');
        $this->assertSame(1, StockBalance::query()->where('warehouse_id', $warehouse->id)->count(), 'and so does its cascade-side child');
    }

    public function test_delete_refuses_when_a_dependency_appears_after_the_report_was_served(): void
    {
        $warehouse = $this->warehouse();
        $lifecycle = $this->warehouseLifecycle();

        $this->assertTrue($lifecycle->report($warehouse)->isClear());

        // The button was served "deletable". Then the floor moved stock.
        StockBalance::create(['item_id' => $this->item()->id, 'warehouse_id' => $warehouse->id, 'quantity' => '5']);

        try {
            $lifecycle->delete($warehouse);
            $this->fail('Expected the locked re-check to refuse the delete.');
        } catch (ConfigurationInUseException $e) {
            $this->assertSame([['code' => 'stock_balances', 'label' => 'stock balance', 'count' => 1]], $e->payload()['blocking']);
        }

        $this->assertNotNull(Warehouse::find($warehouse->id));
    }

    public function test_the_recheck_runs_inside_the_transaction_on_a_freshly_read_row(): void
    {
        $warehouse = $this->warehouse();

        $levels = [];
        $seen = [];
        $lifecycle = new ConfigurationLifecycle(
            label: 'warehouse',
            checks: [
                DependencyCheck::callable(function (Model $model) use (&$levels, &$seen) {
                    $levels[] = DB::transactionLevel();
                    $seen[] = $model;

                    return 0;
                }, 'probe')->label('probe'),
            ],
        );

        $lifecycle->report($warehouse);
        $lifecycle->delete($warehouse);

        $this->assertCount(2, $levels);
        $this->assertGreaterThan($levels[0], $levels[1], 'delete() must re-run the report inside its own transaction.');
        $this->assertNotSame($warehouse, $seen[1], 'delete() must re-read (and lock) the row it is about to remove.');
        $this->assertSame($warehouse->getKey(), $seen[1]->getKey());
    }

    public function test_a_cascade_side_child_blocks_even_when_it_is_soft_deleted(): void
    {
        $item = $this->item('MB-AMBER');
        $dosing = MasterbatchDosing::create([
            'masterbatch_item_id' => $item->id,
            'grams_per_bottle' => '0.2500',
        ]);
        $dosing->delete();

        $lifecycle = new ConfigurationLifecycle(
            label: 'item',
            checks: [
                DependencyCheck::table('masterbatch_dosings', ['masterbatch_item_id', 'product_item_id'])
                    ->label('masterbatch dosing')
                    ->cascadeSide(),
            ],
        );

        $this->assertFalse($lifecycle->report($item)->isClear());
        $this->expectException(ConfigurationInUseException::class);
        $lifecycle->delete($item);
    }

    public function test_an_ordinary_check_ignores_soft_deleted_children_unless_asked(): void
    {
        $item = $this->item('MB-WHITE');
        MasterbatchDosing::create(['masterbatch_item_id' => $item->id, 'grams_per_bottle' => '0.2500'])->delete();

        $ignores = new ConfigurationLifecycle(
            label: 'item',
            checks: [DependencyCheck::table('masterbatch_dosings', 'masterbatch_item_id')->label('masterbatch dosing')],
        );
        $counts = new ConfigurationLifecycle(
            label: 'item',
            checks: [DependencyCheck::table('masterbatch_dosings', 'masterbatch_item_id')->label('masterbatch dosing')->includeTrashed()],
        );

        $this->assertTrue($ignores->report($item)->isClear());
        $this->assertFalse($counts->report($item)->isClear());
    }

    public function test_a_check_that_cannot_prove_non_use_blocks_the_delete(): void
    {
        $warehouse = $this->warehouse();
        $lifecycle = new ConfigurationLifecycle(
            label: 'warehouse',
            checks: [DependencyCheck::unprovable('legacy_stock_history')->label('legacy stock history')],
        );

        $report = $lifecycle->report($warehouse);

        $this->assertFalse($report->isClear());
        $this->assertSame([], $report->blocking());
        $this->assertSame([['code' => 'legacy_stock_history', 'label' => 'legacy stock history']], $report->unprovable());

        try {
            $lifecycle->delete($warehouse);
            $this->fail('Expected an unprovable dependency to refuse the delete.');
        } catch (ConfigurationInUseException $e) {
            $this->assertSame(
                'Cannot delete warehouse "Store WH-1" — past use of legacy stock history cannot be verified. Deactivate instead.',
                $e->getMessage(),
            );
            $this->assertSame([], $e->payload()['blocking']);
            $this->assertSame([['code' => 'legacy_stock_history', 'label' => 'legacy stock history']], $e->payload()['unprovable']);
            $this->assertSame('archive', $e->payload()['alternative']);
        }

        $this->assertNotNull(Warehouse::find($warehouse->id));
    }

    public function test_an_attribute_check_blocks_on_a_tally_identity(): void
    {
        $warehouse = $this->warehouse();
        $warehouse->update(['tally_guid' => 'abc-123']);

        $lifecycle = new ConfigurationLifecycle(
            label: 'warehouse',
            checks: [DependencyCheck::attribute('tally_guid')->label('Tally godown identity')],
        );

        $this->assertSame(
            [['code' => 'tally_guid', 'label' => 'Tally godown identity', 'count' => 1]],
            $lifecycle->report($warehouse)->blocking(),
        );
    }

    public function test_archive_and_activate_flip_the_flag_and_never_delete(): void
    {
        $warehouse = $this->warehouse();
        $lifecycle = $this->warehouseLifecycle();

        $lifecycle->archive($warehouse, 'retired 17-Aug');

        $this->assertFalse($warehouse->fresh()->is_active);
        $this->assertNotNull(Warehouse::find($warehouse->id), 'archive() must not delete anything.');
        $this->assertTrue($lifecycle->abilities($warehouse->fresh())['activate']);
        $this->assertFalse($lifecycle->abilities($warehouse->fresh())['archive']);

        $lifecycle->activate($warehouse->fresh());

        $this->assertTrue($warehouse->fresh()->is_active);
    }

    public function test_the_trait_gives_a_service_the_whole_lifecycle(): void
    {
        $warehouse = $this->warehouse();
        StockBalance::create(['item_id' => $this->item()->id, 'warehouse_id' => $warehouse->id, 'quantity' => '5']);

        $service = new class
        {
            use ManagesConfigurationLifecycle;

            protected function configurationLabel(): string
            {
                return 'warehouse';
            }

            /** @return list<DependencyCheck> */
            protected function dependencyChecks(): array
            {
                return [DependencyCheck::table('stock_balances', 'warehouse_id')->label('stock balance')->cascadeSide()];
            }
        };

        $this->assertFalse($service->dependencyReport($warehouse)->isClear());
        $this->assertSame(['edit', 'activate', 'archive', 'delete'], array_keys($service->abilities($warehouse)));
        $this->assertFalse($service->abilities($warehouse)['delete']);

        $this->expectException(ConfigurationInUseException::class);
        $service->delete($warehouse);
    }
}
