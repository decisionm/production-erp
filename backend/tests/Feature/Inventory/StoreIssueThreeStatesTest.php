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
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
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
 * THE THREE STATES (Phase 7.5, WS-B). A STORE ISSUE IS NOT A CONSUMPTION.
 *
 *   Store Stock → Issued to Production → Consumed
 *                        ↳ Returned to Store
 *
 * The middle state is a LOCATION, not a flag: DEC-20260817-001 names the
 * factory's logical locations Raw Material Store → Production/WIP →
 * Finished Goods Store, and Production/WIP IS the place holding material
 * physically handed to production and not yet consumed. So the issue is a
 * signed transfer PAIR (purpose issue_to_production), the batch's
 * consumption is the same issue it always was but sourced FROM
 * Production/WIP, and unused material goes back on a second transfer pair
 * (purpose return_from_production).
 *
 * That choice is what keeps `inventory:check-ledger` meaningful: it signs
 * by movement TYPE, so a purpose-only "issued" flag would have been
 * invisible to it and would have created no second state at all.
 *
 * Nothing here assumes a daily cadence. Resin may be issued weekly,
 * fortnightly or when the pile looks low; film and cartons may be issued
 * every day. An issue is outstanding until it is returned or completed,
 * never until midnight.
 */
class StoreIssueThreeStatesTest extends TestCase
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
        // The WIP row the factory already has (DEC-20260817-001: reuse it,
        // never mint a synonym). Deliberately INACTIVE here: the live rows
        // were deactivated by the 01-Aug "one place" migration, and material
        // standing in an inactive location must still be issuable and still
        // be where consumption is drawn from — otherwise stock strands.
        $this->wip = Warehouse::create(['code' => 'WIP', 'name' => 'Work In Progress', 'is_active' => false]);
        $this->fg = Warehouse::create(['code' => 'FG-STORE', 'name' => 'Finished Goods Store', 'is_active' => true]);

        $this->resin = Item::create(['sku' => 'PET-RESIN', 'name' => 'PET Resin', 'uom' => 'KGS', 'is_production_input' => true]);
        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml Bottle', 'uom' => 'Nos']);

        // Seeded through the LEDGER, not straight into a balance row: the
        // invariant asserted after every step below is "balance == Σ signed
        // movements", and a balance conjured without a movement would fail
        // it for a reason that has nothing to do with this phase.
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

    /** @return array<string, string> */
    private function ledgerSums(): array
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

        return $sums;
    }

    private function assertLedgerMatchesBalances(string $step): void
    {
        $sums = $this->ledgerSums();
        $balances = StockBalance::query()->get()->keyBy(fn (StockBalance $b) => "{$b->item_id}@{$b->warehouse_id}");

        foreach ($sums as $key => $sum) {
            $this->assertTrue($balances->has($key), "{$step}: no balance row for {$key} though movements exist");
            $this->assertSame(0, bccomp($sum, (string) $balances[$key]->quantity, 4), "{$step}: {$key} ledger {$sum} vs balance {$balances[$key]->quantity}");
        }
        foreach ($balances as $key => $balance) {
            $this->assertSame(0, bccomp($sums[$key] ?? '0.0000', (string) $balance->quantity, 4), "{$step}: balance {$key} holds {$balance->quantity} but the ledger sums to ".($sums[$key] ?? '0.0000'));
        }

        $before = StockMovement::query()->count();
        $this->artisan('inventory:check-ledger')->assertExitCode(0)->run();
        $this->assertSame($before, StockMovement::query()->count(), "{$step}: the check must not write");
    }

    /** @param  array<string, mixed>  $overrides */
    private function issueResin(string $quantity = '300', array $overrides = []): array
    {
        return $this->postJson('/api/v1/inventory/store-issues', array_merge([
            'received_by' => $this->supervisor->id,
            'lines' => [[
                'item_id' => $this->resin->id,
                'quantity' => $quantity,
            ]],
        ], $overrides))->json('data') ?? [];
    }

    // (a) the issue is a transfer into Production/WIP, never a consumption ----

    public function test_a_store_issue_moves_stock_to_production_wip_and_is_not_a_consumption(): void
    {
        $response = $this->postJson('/api/v1/inventory/store-issues', [
            'received_by' => $this->supervisor->id,
            'notes' => 'Weekly resin top-up',
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '300']],
        ])->assertCreated();

        $issue = StoreIssue::query()->with('lines')->sole();

        $this->assertSame(StoreIssueStatus::Issued, $issue->status);
        $this->assertSame($this->storeKeeper->id, $issue->issued_by);
        $this->assertSame($this->supervisor->id, $issue->received_by);
        $this->assertNotNull($issue->issued_at);
        $this->assertSame('300.0000', (string) $issue->lines->sole()->quantity_issued);
        $this->assertSame($this->store->id, $issue->lines->sole()->from_warehouse_id);
        $this->assertSame($this->wip->id, $issue->lines->sole()->to_warehouse_id);

        // Stock moved between two locations. It was NOT consumed.
        $this->assertSame('700.0000', $this->balance($this->store));
        $this->assertSame('300.0000', $this->balance($this->wip));

        $this->assertSame(0, StockMovement::query()->where('purpose', StockMovementPurpose::Consumption->value)->count());
        $out = StockMovement::query()->where('type', StockMovementType::TransferOut)->sole();
        $in = StockMovement::query()->where('type', StockMovementType::TransferIn)->sole();
        $this->assertSame(StockMovementPurpose::IssueToProduction, $out->purpose);
        $this->assertSame(StockMovementPurpose::IssueToProduction, $in->purpose);
        $this->assertSame($out->transfer_group, $in->transfer_group);
        $this->assertSame("Store issue {$issue->issue_number}", (string) $out->reference);

        $this->assertSame($issue->id, $response->json('data.id'));
        $this->assertLedgerMatchesBalances('after the issue');
    }

    // (b) the state is visible and named, and never reads as consumed --------

    public function test_an_issue_is_visible_as_issued_to_production_and_not_as_consumed(): void
    {
        $this->issueResin('300');

        $outstanding = $this->getJson('/api/v1/inventory/store-issues/outstanding')->assertOk()->json('data');

        $this->assertCount(1, $outstanding);
        $this->assertSame($this->resin->id, $outstanding[0]['item_id']);
        $this->assertSame('300.0000', $outstanding[0]['quantity_issued']);
        $this->assertSame('0.0000', $outstanding[0]['quantity_returned']);
        // Both sides, named apart: what the store has not had back, and what
        // the Production/WIP balance actually holds. Before any batch runs
        // they agree; after one they will not, and that is the point.
        $this->assertSame('300.0000', $outstanding[0]['quantity_not_returned']);
        $this->assertSame('300.0000', $outstanding[0]['quantity_in_production_wip']);
        $this->assertSame('issued_to_production', $outstanding[0]['state']);
        // The word matters: nobody reading this may mistake it for consumption.
        $this->assertArrayNotHasKey('quantity_consumed', $outstanding[0]);
    }

    // (c) partial fulfilment and the recomputed remaining --------------------

    public function test_a_partial_issue_leaves_a_remaining_quantity_and_a_second_issue_closes_it(): void
    {
        $first = $this->postJson('/api/v1/inventory/store-issues', [
            'received_by' => $this->supervisor->id,
            'lines' => [[
                'item_id' => $this->resin->id,
                'quantity' => '200',
                'material_request_line_id' => 41,
                'quantity_requested' => '500',
            ]],
        ])->assertCreated()->json('data');

        $this->assertSame('300.0000', $first['lines'][0]['quantity_remaining_on_request']);

        $second = $this->postJson('/api/v1/inventory/store-issues', [
            'received_by' => $this->supervisor->id,
            'lines' => [[
                'item_id' => $this->resin->id,
                'quantity' => '300',
                'material_request_line_id' => 41,
                'quantity_requested' => '500',
            ]],
        ])->assertCreated()->json('data');

        $this->assertSame('0.0000', $second['lines'][0]['quantity_remaining_on_request']);
        $this->assertSame('500.0000', $this->balance($this->wip));
        $this->assertLedgerMatchesBalances('after two partial issues');
    }

    // (d) the return restores store stock and never invents kg ---------------

    public function test_a_return_restores_store_stock_and_refuses_to_invent_kilograms(): void
    {
        $issue = $this->issueResin('300');
        $lineId = $issue['lines'][0]['id'];

        $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/returns", [
            'lines' => [['store_issue_line_id' => $lineId, 'quantity' => '400']],
        ])->assertStatus(422);

        // The error is keyed by the line that could not be honoured, in
        // Laravel's own array-field shape ("lines.0"), so the screen can put
        // the sentence on the row it belongs to.
        $this->assertSame(
            'Only 300.0000 of this line is standing with production — a return of 400.0000 would invent material that was never issued.',
            $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/returns", [
                'lines' => [['store_issue_line_id' => $lineId, 'quantity' => '400']],
            ])->json('errors')['lines.0'][0],
        );

        // The refusal moved nothing.
        $this->assertSame('300.0000', $this->balance($this->wip));

        $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/returns", [
            'lines' => [['store_issue_line_id' => $lineId, 'quantity' => '120']],
        ])->assertOk();

        $this->assertSame('820.0000', $this->balance($this->store));
        $this->assertSame('180.0000', $this->balance($this->wip));

        $returnOut = StockMovement::query()
            ->where('type', StockMovementType::TransferOut)
            ->where('purpose', StockMovementPurpose::ReturnFromProduction->value)
            ->sole();
        $this->assertSame($this->wip->id, $returnOut->warehouse_id);
        $this->assertSame('120.0000', (string) $returnOut->quantity);

        $this->assertSame(StoreIssueStatus::PartiallyReturned, StoreIssue::query()->sole()->status);
        $this->assertLedgerMatchesBalances('after the return');

        // Returning the rest closes the issue as fully returned.
        $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/returns", [
            'lines' => [['store_issue_line_id' => $lineId, 'quantity' => '180']],
        ])->assertOk();

        $this->assertSame(StoreIssueStatus::Returned, StoreIssue::query()->sole()->status);
        $this->assertSame('1000.0000', $this->balance($this->store));
        $this->assertSame('0.0000', $this->balance($this->wip));
        $this->assertLedgerMatchesBalances('after the closing return');
    }

    // (e) completion and cancellation ---------------------------------------

    public function test_an_issue_can_be_completed_and_a_completed_issue_is_no_longer_outstanding(): void
    {
        $issue = $this->issueResin('300');

        $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/complete")->assertOk();

        $this->assertSame(StoreIssueStatus::Completed, StoreIssue::query()->sole()->status);

        // THE STORE HAS STOPPED WAITING — AND 300 kg IS STILL IN PRODUCTION.
        // Both are true, and the read says both. Showing only the first
        // would collapse "issued to production" into "consumed", which is
        // the one thing this phase exists to prevent.
        $reconciliation = $this->getJson('/api/v1/inventory/store-issues/outstanding')->assertOk();
        $row = $reconciliation->json('data.0');
        $this->assertSame('0.0000', $row['quantity_not_returned']);
        $this->assertSame('300.0000', $row['quantity_in_production_wip']);
        $this->assertStringContainsString('production has consumed', (string) $reconciliation->json('basis'));

        // Completing accounts for the issue; it does NOT move stock, because
        // consumption is booked by the batch and only by the batch.
        $this->assertSame(3, StockMovement::query()->count(), 'the opening receipt and the issue transfer pair');
        $this->assertLedgerMatchesBalances('after completion');

        $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/complete")->assertStatus(422);
    }

    public function test_a_cancellation_returns_everything_and_is_refused_once_material_has_come_back(): void
    {
        $issue = $this->issueResin('300');

        $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/cancel", [
            'reason' => 'Handed to the wrong machine area',
        ])->assertOk();

        $cancelled = StoreIssue::query()->sole();
        $this->assertSame(StoreIssueStatus::Cancelled, $cancelled->status);
        $this->assertSame('Handed to the wrong machine area', $cancelled->cancellation_reason);
        $this->assertSame('1000.0000', $this->balance($this->store));
        $this->assertSame('0.0000', $this->balance($this->wip));
        $this->assertLedgerMatchesBalances('after cancellation');

        // A second issue, partly returned, can no longer be cancelled: the
        // reversal it would post is not a fact any more.
        $second = $this->issueResin('100');
        $this->postJson("/api/v1/inventory/store-issues/{$second['id']}/returns", [
            'lines' => [['store_issue_line_id' => $second['lines'][0]['id'], 'quantity' => '40']],
        ])->assertOk();

        $this->postJson("/api/v1/inventory/store-issues/{$second['id']}/cancel", ['reason' => 'Changed my mind'])
            ->assertStatus(422);
        $this->assertLedgerMatchesBalances('after the refused cancellation');
    }

    // (f) consumption is sourced from Production/WIP and traces to its issue --

    public function test_a_batch_consumes_from_production_wip_and_the_consumption_traces_to_its_issue(): void
    {
        $this->seed(CanonicalMachineSeeder::class);
        $machine = WorkCenter::where('code', 'MC-01')->firstOrFail();
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);

        $issue = $this->issueResin('300');

        $entryId = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => '2026-08-16',
        ])->assertOk()->json('data.id');

        // NO warehouse_id on the consumption line: the server answers where
        // the material came out of, and after an issue that answer is
        // Production/WIP.
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => '8100',
            'running_hours' => '8',
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'quantity_issued_kg' => '118.998'],
            ],
        ])->assertOk();

        $consumption = StockMovement::query()
            ->where('type', StockMovementType::Issue)
            ->where('purpose', StockMovementPurpose::Consumption->value)
            ->sole();

        $this->assertSame($this->wip->id, $consumption->warehouse_id);
        $this->assertSame('181.0020', $this->balance($this->wip));

        // THE RECONCILIATION the acceptance asks for: the store is still
        // owed 300 back (it was never told about the batch), the ledger says
        // 181.0020 is left standing. The gap is exactly what the batch
        // calculated it consumed, and both figures are on the screen.
        $row = $this->getJson('/api/v1/inventory/store-issues/outstanding')->assertOk()->json('data.0');
        $this->assertSame('300.0000', $row['quantity_not_returned']);
        $this->assertSame('181.0020', $row['quantity_in_production_wip']);
        $this->assertSame(
            '118.9980',
            bcsub($row['quantity_not_returned'], $row['quantity_in_production_wip'], 4),
        );
        $this->assertSame('700.0000', $this->balance($this->store));
        $this->assertLedgerMatchesBalances('after the batch');

        // The trace: which issues put this material into production. It stops
        // AT THE ISSUE — it never claims this batch used these bags (FC-01).
        $trace = $this->getJson("/api/v1/inventory/store-issues/trace?item_id={$this->resin->id}")
            ->assertOk()->json('data');

        $this->assertSame([$issue['issue_number']], array_column($trace['issues'], 'issue_number'));
        $this->assertSame(
            'These issues put this material into Production/WIP before that moment. A batch\'s consumption is '
            .'calculated, so this is a location trace, never a claim that a batch used a particular bag.',
            $trace['basis'],
        );
    }

    // (f2) the WIP row is older than this phase — it does not win on its own -

    public function test_a_batch_that_was_issued_nothing_consumes_exactly_where_it_did_before(): void
    {
        $this->seed(CanonicalMachineSeeder::class);
        $machine = WorkCenter::where('code', 'MC-01')->firstOrFail();
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);

        // The rehearsal residue the warehouse audit found: the WIP row
        // already carries balances that no store issue ever put there.
        app(StockMovementService::class)->recordReceipt(
            itemId: $this->resin->id,
            warehouseId: $this->wip->id,
            quantity: '400',
            unitCost: '85',
            reference: 'Rehearsal residue',
            purpose: StockMovementPurpose::Opening,
        );
        app(FactoryWarehouseResolver::class)->setRawMaterialWarehouseId($this->store->id);

        $entryId = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => '2026-08-16',
        ])->assertOk()->json('data.id');

        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => '8100',
            'running_hours' => '8',
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'quantity_issued_kg' => '118.998'],
            ],
        ])->assertOk();

        // NOT the WIP row. Nothing was handed over, so nothing may be drawn
        // out of the handover location — the batch consumes from the store
        // exactly as it did before this phase existed.
        $consumption = StockMovement::query()
            ->where('type', StockMovementType::Issue)
            ->where('purpose', StockMovementPurpose::Consumption->value)
            ->sole();
        $this->assertSame($this->store->id, $consumption->warehouse_id);
        $this->assertSame('400.0000', $this->balance($this->wip));
        $this->assertLedgerMatchesBalances('after a batch that was issued nothing');
    }

    // (f3) material can still come home after the paperwork was closed -------

    public function test_material_returned_after_the_issue_was_completed_still_gets_home(): void
    {
        $issue = $this->issueResin('300');
        $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/complete")->assertOk();

        // A day later, half a pallet comes back. Completing moved no stock,
        // so the kilograms are still in Production/WIP and refusing this
        // would strand them there with nothing able to record them home.
        $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/returns", [
            'lines' => [['store_issue_line_id' => $issue['lines'][0]['id'], 'quantity' => '50']],
        ])->assertOk();

        $this->assertSame('750.0000', $this->balance($this->store));
        $this->assertSame('250.0000', $this->balance($this->wip));
        // The status keeps saying what happened: the store HAD stopped
        // waiting. A late return does not rewrite that.
        $this->assertSame(StoreIssueStatus::Completed, StoreIssue::query()->sole()->status);
        $this->assertLedgerMatchesBalances('after a late return');

        // And it still cannot invent kilograms: only 250 of the 300 are left.
        $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/returns", [
            'lines' => [['store_issue_line_id' => $issue['lines'][0]['id'], 'quantity' => '300']],
        ])->assertStatus(422);
        $this->assertSame('250.0000', $this->balance($this->wip));
    }

    // (g) refusals that keep the location honest -----------------------------

    public function test_an_issue_is_refused_when_no_production_wip_location_can_be_resolved(): void
    {
        $this->wip->forceDelete();

        $this->postJson('/api/v1/inventory/store-issues', [
            'received_by' => $this->supervisor->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '10']],
        ])->assertStatus(422)->assertJsonPath('errors.production_wip.0', ProductionWipLocationResolver::UNRESOLVED_MESSAGE);

        $this->assertSame(1, StockMovement::query()->count(), 'only the opening receipt exists');
    }

    public function test_an_issue_out_of_the_wip_location_itself_is_refused(): void
    {
        app(ProductionWipLocationResolver::class)->setWarehouseId($this->store->id);

        $this->postJson('/api/v1/inventory/store-issues', [
            'received_by' => $this->supervisor->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '10']],
        ])->assertStatus(422);

        $this->assertSame(
            'Raw Material Store is already the Production/WIP location — material cannot be issued from production to itself.',
            $this->postJson('/api/v1/inventory/store-issues', [
                'received_by' => $this->supervisor->id,
                'lines' => [['item_id' => $this->resin->id, 'quantity' => '10']],
            ])->json('errors')['lines.0'][0],
        );

        $this->assertSame(1, StockMovement::query()->count(), 'only the opening receipt exists');
    }

    public function test_the_store_cannot_issue_material_it_does_not_have(): void
    {
        $this->postJson('/api/v1/inventory/store-issues', [
            'received_by' => $this->supervisor->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '5000']],
        ])->assertStatus(422);

        $this->assertSame('1000.0000', $this->balance($this->store));
        $this->assertSame(1, StockMovement::query()->count(), 'only the opening receipt exists');
    }

    // (h) no daily cadence is assumed anywhere -------------------------------

    public function test_an_issue_raised_weeks_ago_is_still_outstanding_today(): void
    {
        $issue = $this->issueResin('300');
        StoreIssue::query()->whereKey($issue['id'])->update([
            'issued_at' => now()->subDays(23),
            'created_at' => now()->subDays(23),
        ]);

        $outstanding = $this->getJson('/api/v1/inventory/store-issues/outstanding')->assertOk()->json('data');
        $this->assertSame('300.0000', $outstanding[0]['quantity_not_returned']);
        $this->assertSame('300.0000', $outstanding[0]['quantity_in_production_wip']);

        $index = $this->getJson('/api/v1/inventory/store-issues')->assertOk()->json('data');
        $this->assertCount(1, $index);
    }

    // (i) FC-06: money never reaches a store reader --------------------------

    public function test_the_issue_never_shows_a_rate_or_an_amount_to_a_store_reader(): void
    {
        $issue = $this->issueResin('300');

        $shown = json_encode($this->getJson("/api/v1/inventory/store-issues/{$issue['id']}")->assertOk()->json());

        foreach (['unit_cost', 'average_cost', 'rate', 'amount', 'vendor', 'supplier_name', '85.0000'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $shown, "FC-06: \"{$forbidden}\" must not reach a store reader.");
        }
    }

    // (j) a plain read cannot write -----------------------------------------

    public function test_a_view_only_login_can_read_the_queue_but_never_issue(): void
    {
        Sanctum::actingAs($this->userWith(['inventory.view']));

        $this->getJson('/api/v1/inventory/store-issues')->assertOk();
        $this->postJson('/api/v1/inventory/store-issues', [
            'received_by' => $this->supervisor->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '10']],
        ])->assertForbidden();
    }

    // (k) the stock service still refuses a same-place transfer --------------

    public function test_the_ledger_is_untouched_by_a_refused_issue(): void
    {
        $before = app(StockMovementService::class)->totalOnHand($this->resin->id);

        $this->postJson('/api/v1/inventory/store-issues', [
            'received_by' => $this->supervisor->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '0']],
        ])->assertStatus(422);

        $this->assertSame($before, app(StockMovementService::class)->totalOnHand($this->resin->id));
    }
}
