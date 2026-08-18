<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
use App\Modules\Inventory\Models\Enums\StockMovementType;
use App\Modules\Inventory\Models\Enums\StoreIssueStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StoreIssue;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\FactoryWarehouseResolver;
use Database\Seeders\CanonicalMachineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * WHAT A REVERSAL MAY TAKE BACK, AND WHERE AN OVER-DRAW LANDS (Phase 7.5).
 *
 * Two defects with one root: the store issue's own arithmetic
 * (issued − returned) says how much of a handover has not come back, and
 * that is NOT the same number as how much of it is still standing
 * unconsumed. Once a batch has burnt part of an issue, the difference is
 * gone — and a reversal bounded only by the line's arithmetic would take it
 * out of whatever else happens to be pooled in Production/WIP: another
 * issue's kilograms, moved quietly, with the transfer succeeding because
 * the pooled balance covered it.
 *
 * THE BOUND IS CONSERVATIVE ON PURPOSE. A batch's consumption is CALCULATED
 * (FC-01, DEC-20260807-007) — nothing in this factory can say which handover
 * the kilograms a machine burnt came out of — so every kilogram consumed
 * since an issue was made is charged against THAT issue when it is reversed.
 * The worst case is a refusal naming real figures, which a person can act
 * on; the alternative is a silent wrong number nobody would ever see.
 * Whether the factory would rather cap than refuse is an OWNER question and
 * is recorded as one.
 *
 * AND THE OVER-DRAW ITSELF. A batch may consume more than was issued. That
 * is not refused — config/production.php 'stock' and the 30-Jul incident
 * behind it are explicit that a paperwork gap must never become lost
 * production — but it must land on Production/WIP, where the shortfall is
 * recorded and says what it means, and never fall through onto a store that
 * handed nothing over.
 */
class StoreIssueReversalBoundsTest extends TestCase
{
    use RefreshDatabase;

    private Item $resin;

    private Item $bottle;

    private Warehouse $store;

    private Warehouse $wip;

    private Warehouse $fg;

    private User $storeKeeper;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.approvals.quality_stage_enabled' => false]);

        $this->store = Warehouse::create(['code' => 'RM-STORE', 'name' => 'Raw Material Store', 'is_active' => true]);
        $this->wip = Warehouse::create(['code' => 'WIP', 'name' => 'Work In Progress', 'is_active' => false]);
        $this->fg = Warehouse::create(['code' => 'FG-STORE', 'name' => 'Finished Goods Store', 'is_active' => true]);

        $this->resin = Item::create(['sku' => 'PET-RESIN', 'name' => 'PET Resin', 'uom' => 'KGS']);
        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml Bottle', 'uom' => 'Nos']);

        app(StockMovementService::class)->recordReceipt(
            itemId: $this->resin->id,
            warehouseId: $this->store->id,
            quantity: '1000',
            unitCost: '85',
            reference: 'Opening',
            purpose: StockMovementPurpose::Opening,
        );

        $this->storeKeeper = $this->userWith(['inventory.manage', 'production.manage']);
        $this->supervisor = $this->userWith(['production.manage']);

        Sanctum::actingAs($this->storeKeeper);
    }

    // ---- (1) a reversal may never exceed what is still unconsumed ----------

    public function test_a_cancellation_is_refused_once_production_has_consumed_against_the_issue(): void
    {
        $issue = $this->issueResin('100');
        $this->consume('60');

        $this->assertSame('40.0000', $this->balance($this->wip));

        $refusal = $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/cancel", [
            'reason' => 'Wrong shift',
        ])->assertStatus(422);

        $this->assertStringContainsString(
            'only 40.0000 is still standing unconsumed in Production/WIP',
            (string) $refusal->json('errors.status.0'),
        );

        // Refused, and nothing moved: the issue is still open, the ledger is
        // where it was, and no kilogram was invented or destroyed.
        $this->assertSame(StoreIssueStatus::Issued, StoreIssue::query()->sole()->status);
        $this->assertSame('40.0000', $this->balance($this->wip));
        $this->assertSame('900.0000', $this->balance($this->store));
        $this->assertLedgerMatchesBalances('after the refused cancellation');
    }

    public function test_a_return_may_not_take_more_than_is_still_unconsumed(): void
    {
        $issue = $this->issueResin('100');
        $lineId = $issue['lines'][0]['id'];
        $this->consume('60');

        // The LINE still says 100 was handed over and none came back — which
        // is true, and is exactly why the line's own arithmetic is not a
        // sufficient bound.
        $refusal = $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/returns", [
            'lines' => [['store_issue_line_id' => $lineId, 'quantity' => '100']],
        ])->assertStatus(422);

        $this->assertStringContainsString(
            'of the 100.0000 this line handed over and has not had back, only 40.0000 is still standing '
            .'unconsumed in Production/WIP',
            (string) $refusal->json('errors')['lines.0'][0],
        );
        $this->assertSame('40.0000', $this->balance($this->wip));

        // What is genuinely standing does come home.
        $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/returns", [
            'lines' => [['store_issue_line_id' => $lineId, 'quantity' => '40']],
        ])->assertOk();

        $this->assertSame('0.0000', $this->balance($this->wip));
        $this->assertSame('940.0000', $this->balance($this->store));
        $this->assertLedgerMatchesBalances('after the capped return');
    }

    public function test_a_reversal_can_never_drain_another_issues_material(): void
    {
        // TWO issues standing side by side. The pooled Production/WIP balance
        // (200) covers a full reversal of either, so the transfer's own
        // insufficient-stock refusal would NOT fire — it would succeed and
        // quietly move the other issue's kilograms.
        $first = $this->issueResin('100');
        $second = $this->issueResin('100');
        $this->consume('60');

        $this->assertSame('140.0000', $this->balance($this->wip));

        $this->postJson("/api/v1/inventory/store-issues/{$first['id']}/cancel", ['reason' => 'Wrong shift'])
            ->assertStatus(422);
        $this->postJson("/api/v1/inventory/store-issues/{$second['id']}/cancel", ['reason' => 'Wrong shift'])
            ->assertStatus(422);

        // Both are bounded at 40 — the consumption is charged to whichever
        // issue is being reversed, because nothing can say which one it came
        // out of. Their two reversals together (80) still cannot exceed what
        // is pooled and unconsumed (140).
        $this->postJson("/api/v1/inventory/store-issues/{$first['id']}/returns", [
            'lines' => [['store_issue_line_id' => $first['lines'][0]['id'], 'quantity' => '40']],
        ])->assertOk();
        $this->postJson("/api/v1/inventory/store-issues/{$second['id']}/returns", [
            'lines' => [['store_issue_line_id' => $second['lines'][0]['id'], 'quantity' => '40']],
        ])->assertOk();

        $this->assertSame('60.0000', $this->balance($this->wip));
        $this->assertSame(1, bccomp($this->balance($this->wip), '0', 4));
        $this->assertLedgerMatchesBalances('after two bounded returns');
    }

    public function test_an_issue_made_after_the_consumption_is_not_charged_for_it(): void
    {
        // Material handed over at 14:00 cannot have been eaten at 09:00, so
        // consumption older than an issue is not charged against it. Without
        // this the bound would refuse perfectly ordinary reversals on every
        // issue raised after the factory's first batch.
        $this->issueResin('100');
        $this->consume('60');

        // An hour later — the point of the bound is that this handover had
        // not happened yet when the machine ate, so it cannot be charged.
        $this->travel(1)->hours();
        $later = $this->issueResin('100');

        $this->postJson("/api/v1/inventory/store-issues/{$later['id']}/cancel", ['reason' => 'Wrong material'])
            ->assertOk();

        $this->assertSame(StoreIssueStatus::Cancelled, StoreIssue::query()->find($later['id'])->status);
        $this->assertSame('40.0000', $this->balance($this->wip));
        $this->assertLedgerMatchesBalances('after cancelling the later issue');
    }

    public function test_a_reversal_is_untouched_when_nothing_has_been_consumed(): void
    {
        $issue = $this->issueResin('300');

        $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/returns", [
            'lines' => [['store_issue_line_id' => $issue['lines'][0]['id'], 'quantity' => '120']],
        ])->assertOk();

        $this->assertSame('180.0000', $this->balance($this->wip));
        $this->assertSame('820.0000', $this->balance($this->store));
        $this->assertLedgerMatchesBalances('after an ordinary return');
    }

    // ---- (2) an over-draw lands on Production/WIP, loudly ------------------

    public function test_a_batch_consuming_more_than_was_issued_records_it_against_production_wip(): void
    {
        $this->issueResin('100');

        $metrics = $this->completeBatchConsuming('150');

        // WIP goes negative — the completion is NOT refused (a paperwork gap
        // must never become lost production, config/production.php 'stock')
        // — but the gap is recorded where it happened and says what it means.
        $this->assertSame('-50.0000', $this->balance($this->wip));
        $this->assertSame('900.0000', $this->balance($this->store));

        $this->assertCount(1, $metrics);
        $this->assertSame('50.0000', $metrics[0]['short_kg']);
        $this->assertSame($this->wip->id, $metrics[0]['warehouse_id']);
        $this->assertStringContainsString(
            'consumed more of this material than was ever issued to it',
            (string) $metrics[0]['basis'],
        );
        $this->assertLedgerMatchesBalances('after an over-consuming batch');
    }

    public function test_the_next_batch_still_draws_on_production_wip_while_it_is_over_drawn(): void
    {
        // THE HOLE THIS CLOSES. Production/WIP used to be chosen only while
        // it held a POSITIVE balance, so the second over-draw dropped
        // straight through to the store: the store's balance fell for
        // material it never issued, no shortfall was recorded anywhere, and
        // the issue went on saying the kilograms were out on the floor.
        $this->issueResin('100');
        $this->completeBatchConsuming('150');
        $this->assertSame('-50.0000', $this->balance($this->wip));

        $storeBefore = $this->balance($this->store);
        $metrics = $this->completeBatchConsuming('20');

        $this->assertSame('-70.0000', $this->balance($this->wip));
        $this->assertSame($storeBefore, $this->balance($this->store));

        // 70, not 20: short_kg is the gap the accountant has to make good —
        // requested minus the balance the decrement found under its own row
        // lock — and that balance was already 50 short. The whole standing
        // deficit is on the screen, which is the figure worth seeing.
        $this->assertSame('70.0000', $metrics[0]['short_kg']);
        $this->assertSame($this->wip->id, $metrics[0]['warehouse_id']);
        $this->assertLedgerMatchesBalances('after a second over-consuming batch');
    }

    public function test_production_wip_lets_go_once_it_is_flat_and_no_handover_is_open(): void
    {
        // The other side of the same rule, and the one that keeps it from
        // being permanent: an issue fully consumed and closed leaves nothing
        // standing, so consumption is answered exactly as it was before this
        // phase existed.
        $issue = $this->issueResin('100');
        $this->completeBatchConsuming('100');
        $this->assertSame('0.0000', $this->balance($this->wip));

        $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/complete")->assertOk();

        app(FactoryWarehouseResolver::class)->setRawMaterialWarehouseId($this->store->id);
        $this->completeBatchConsuming('30');

        $this->assertSame('0.0000', $this->balance($this->wip));
        $this->assertSame('870.0000', $this->balance($this->store));
        $this->assertLedgerMatchesBalances('after WIP let go');
    }

    // ---- helpers -----------------------------------------------------------

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function balance(Warehouse $warehouse, ?Item $item = null): string
    {
        return (string) (StockBalance::query()
            ->where('item_id', ($item ?? $this->resin)->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('quantity') ?? '0.0000');
    }

    private function assertLedgerMatchesBalances(string $step): void
    {
        $sums = [];
        foreach (StockMovement::query()->orderBy('id')->get() as $movement) {
            $sign = match ($movement->type) {
                StockMovementType::Receipt, StockMovementType::TransferIn => '1',
                StockMovementType::Issue, StockMovementType::TransferOut => '-1',
            };
            $key = "{$movement->item_id}@{$movement->warehouse_id}";
            $sums[$key] = bcadd($sums[$key] ?? '0.0000', bcmul((string) $movement->quantity, $sign, 4), 4);
        }

        $balances = StockBalance::query()->get()->keyBy(fn (StockBalance $b) => "{$b->item_id}@{$b->warehouse_id}");

        foreach ($sums as $key => $sum) {
            $this->assertTrue($balances->has($key), "{$step}: no balance row for {$key} though movements exist");
            $this->assertSame(0, bccomp($sum, (string) $balances[$key]->quantity, 4), "{$step}: {$key} ledger {$sum} vs balance {$balances[$key]->quantity}");
        }
        foreach ($balances as $key => $balance) {
            $this->assertSame(0, bccomp($sums[$key] ?? '0.0000', (string) $balance->quantity, 4), "{$step}: balance {$key} disagrees with the ledger");
        }

        $this->artisan('inventory:check-ledger')->assertExitCode(0)->run();
    }

    /** @return array<string, mixed> */
    private function issueResin(string $quantity): array
    {
        return $this->postJson('/api/v1/inventory/store-issues', [
            'received_by' => $this->supervisor->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => $quantity]],
        ])->assertCreated()->json('data');
    }

    /**
     * A consumption booked exactly as a batch books it — through the
     * completion path, so the source warehouse is the one the SERVER
     * resolves and never one this test typed.
     */
    private function consume(string $kg): void
    {
        $this->completeBatchConsuming($kg);
    }

    /** @return array<int, array<string, mixed>> the completion's own shortfall metrics */
    private function completeBatchConsuming(string $kg): array
    {
        if (! WorkCenter::query()->where('code', 'MC-01')->exists()) {
            $this->seed(CanonicalMachineSeeder::class);
        }

        $machine = WorkCenter::where('code', 'MC-01')->firstOrFail();
        $shift = Shift::query()->firstOrCreate(
            ['name' => 'Morning'],
            ['start_time' => '06:00', 'end_time' => '14:00'],
        );

        $entryId = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => '2026-08-16',
        ])->assertOk()->json('data.id');

        return $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => '100',
            'running_hours' => '8',
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'quantity_issued_kg' => $kg],
            ],
        ])->assertOk()->json('data.metrics.stock_shortfalls');
    }
}
