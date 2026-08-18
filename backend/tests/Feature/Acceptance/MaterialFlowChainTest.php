<?php

namespace Tests\Feature\Acceptance;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Modules\Inventory\Models\Enums\MaterialRequestStatus;
use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
use App\Modules\Inventory\Models\Enums\StockMovementType;
use App\Modules\Inventory\Models\Enums\StoreIssueStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\MaterialRequest;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StoreIssue;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftMaterialConsumption;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\FactoryWarehouseResolver;
use App\Modules\TallySync\Models\TallyStockSnapshot;
use App\Modules\TallySync\Services\TallyStockReconcileService;
use Database\Seeders\CanonicalMachineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * PHASE 8 ACCEPTANCE — CHAIN B2, THE STORE → PRODUCTION MATERIAL FLOW.
 *
 *   Production Request → Store Issue → Bag Scan → Production Receipt
 *   → Batch Consumption → Remaining / Return → stock reconciliation
 *
 * Phase 7.5's acceptance (DEC-20260817-001), walked ONCE end to end through
 * the real endpoints and the real services. Assertions are on the
 * TRANSACTION MODEL — what rows exist, where the kilograms are, what each
 * document points at — never on a screen. No screen is involved anywhere in
 * this file.
 *
 * =================== THE ASSERTION THAT MATTERS MOST ========================
 *
 * THE THREE STATES NEVER COLLAPSE:
 *
 *   Store Stock ──issue──► Issued to Production (Production/WIP) ──batch──► Consumed
 *                                    │
 *                                    └──return──► Store Stock
 *
 *   · after an issue the stock is in Production/WIP and is NOT consumed —
 *     asserted as a movement census, not merely as a balance: zero movements
 *     of purpose `consumption` exist anywhere at that point;
 *   · consumption moves it OUT of Production/WIP — the consumption movement's
 *     warehouse is Production/WIP, and the STORE balance does not move at all;
 *   · a return puts it back in the STORE;
 *   · and the store's balance NEVER FALLS TWICE for the same material — the
 *     only outflows the store ever sees are the three issue_to_production
 *     transfers, totalling exactly what was handed over. A collapse of the
 *     states (an issue booked as a consumption, or a consumption booked
 *     against the store after an issue) would show up as a fourth outflow or
 *     as a consumption row at the store warehouse, and both are counted here.
 *
 * ============================ ALSO ASSERTED =================================
 *
 *   · the Phase 5 ledger invariant (stock_balances == Σ signed
 *     stock_movements per item+warehouse) AND the read-only
 *     `inventory:check-ledger` guard, green after EVERY step of the walk —
 *     not once at the end;
 *   · a consumption traces back to its ISSUE (`/store-issues/trace`), and
 *     the trace STOPS there;
 *   · FC-01, structurally and behaviourally: the ERP never claims a batch
 *     consumed a specific bag. There is no batch/entry/work-centre column on
 *     the bag scan and no bag/lot column on the batch's consumption row, so
 *     the claim is not merely unwritten — it is unwritable;
 *   · FC-01 at the request desk: a resin (common-input) request REFUSES a
 *     machine, a consumable request carries one;
 *   · an OPEN ISSUE PRODUCES NO DRIFT in the Tally stock reconciliation —
 *     Tally never saw the handover, and the reconcile must not "correct" it
 *     by receipting a second copy of material standing on the floor.
 *
 * ======================= THE RULES THIS WALK RUNS UNDER =====================
 *
 * DEV FIXTURES ONLY. RefreshDatabase, the test database, never the live
 * instance — nothing here reads, writes or counts on live.
 *
 * NO TALLY WRITE OF ANY KIND. No connection to Tally is opened. The
 * reconciliation link works on a snapshot ROW this test creates and applies
 * TallyStockReconcileService to the dev database; the second reconcile is a
 * DRY RUN (`write: false`) so not even a dev movement is written for it.
 * The PO→Tally flag is untouched (it stays off, as phpunit.xml pins it).
 *
 * NEVER INVENT A FACTORY VALUE. Every figure below is an ARBITRARY TEST
 * CONSTANT chosen so the arithmetic can be checked by hand, and every fixture
 * is prefixed `ACC-` so no later reader can mistake one for factory data
 * (the PR #128 scar: a DERIVED bag weight once reached live). Nothing in this
 * file is a measurement of anything in the real factory — not the 1,000 kg
 * opening, not the 25 kg bags, not the 120 kg the batch consumes, not the
 * bottle count. None of them may be quoted anywhere as a factory value.
 *
 * ========================== THE ARITHMETIC, BY HAND =========================
 *
 *   opening in the store                                  1,000.0000 kg
 *   request ACC-MR asks for                                 500.0000 kg
 *   issue A (typed line)                    − 300.0000  →  store 700 · WIP 300
 *   issue B (two 25 kg bag scans)           −  50.0000  →  store 650 · WIP 350
 *   batch consumption (out of WIP)          − 120.0000  →  store 650 · WIP 230
 *   return on issue A                       +  80.0000  →  store 730 · WIP 150
 *
 *   the store fell 1,000 → 650 ONCE, by exactly the 350 it handed over, and
 *   rose to 730 by exactly the 80 that came back. The 120 the batch ate never
 *   touched it.
 *
 * ========================== WHAT IS NOT RE-DERIVED HERE =====================
 *
 * This file WALKS the chain; it does not re-prove the unit contracts, which
 * are pinned by name elsewhere and are not repeated:
 *   · the three-state contract in full, the refusals that keep the location
 *     honest, the late return, FC-06 on the store surface —
 *     Inventory\StoreIssueThreeStatesTest;
 *   · the bag scan's own resolution and its QC refusals —
 *     Inventory\StoreIssueBagScanTest;
 *   · the request lifecycle, its queue filters and its permissions —
 *     Inventory\MaterialRequestLifecycleTest / QueueFiltersTest /
 *     PermissionsTest;
 *   · the whole common-input refusal matrix (every kg-family spelling) —
 *     Inventory\MaterialRequestCommonInputRefusalTest;
 *   · the reconcile's WIP folding in all its cases (real drift found, WIP
 *     posting under no known godown) — TallySync\ReconcileProductionWipTest;
 *   · a hand-inserted drift row tripping `inventory:check-ledger` —
 *     Inventory\CheckStockLedgerCommandTest.
 *
 * NO LINK OF B2 IS BLOCKED. There is one honest deviation, stated rather than
 * papered over: the ERP has NO separate "production receipt" document. The
 * receipt IS the arrival in Production/WIP with a named receiver
 * (`received_by` on the issue and on every bag scan), so link B2-4 is
 * asserted on that transaction model and no endpoint is invented for it.
 */
class MaterialFlowChainTest extends TestCase
{
    use RefreshDatabase;

    /** The one Tally godown these fixtures know. Synthetic — not the factory's company name. */
    private const GODOWN = 'ACC Acceptance Godown';

    private const PRODUCTION_DATE = '2026-08-16';

    private Item $resin;

    private Item $carton;

    private Item $bottle;

    private Warehouse $store;

    private Warehouse $wip;

    private Warehouse $fg;

    private User $floor;

    private User $storekeeper;

    protected function setUp(): void
    {
        parent::setUp();

        // The quality stage is switched off for this walk: B2 is about where
        // the MATERIAL is, and the approval chain is chain A's subject.
        config(['production.approvals.quality_stage_enabled' => false]);
        // Bag scanning only exists with traceability on — the barcode has to
        // resolve to a MaterialBag.
        config(['production.traceability_enabled' => true]);

        // ---- the locations ------------------------------------------------
        // EXACTLY ONE warehouse carries a tally_guid, and that is deliberate:
        // Production/WIP has no Tally identity of its own (as
        // AUDIT-WAREHOUSES-2026-08-17 found the live row), so it aliases to
        // the sole Tally-linked warehouse. A second guid anywhere here would
        // kill that alias and the reconcile link would be testing a different
        // question.
        $this->store = Warehouse::create([
            'code' => 'ACC-RM', 'name' => self::GODOWN,
            'is_active' => true, 'tally_guid' => 'acc-gd-company',
        ]);
        // Deliberately INACTIVE, exactly as the live WIP row is: material
        // standing in an inactive location must still be issuable and must
        // still be where consumption is drawn from.
        $this->wip = Warehouse::create([
            'code' => 'ACC-WIP', 'name' => 'ACC Production WIP', 'is_active' => false,
        ]);
        $this->fg = Warehouse::create([
            'code' => 'ACC-FG', 'name' => 'ACC Finished Goods Store', 'is_active' => true,
        ]);

        // Named, never guessed at: the walk must not depend on a code lookup.
        app(ProductionWipLocationResolver::class)->setWarehouseId($this->wip->id);
        app(FactoryWarehouseResolver::class)->setRawMaterialWarehouseId($this->store->id);
        app(FactoryWarehouseResolver::class)->setFinishedGoodsWarehouseId($this->fg->id);

        // ---- the materials -------------------------------------------------
        // A kg-family unit is what marks a COMMON INPUT in this database
        // (FC-01 / DEC-20260807-006), so the resin is Kgs and the carton Nos.
        $this->resin = Item::create(['sku' => 'ACC-MF-RESIN', 'name' => 'ACC Chain Resin', 'uom' => 'Kgs', 'is_production_input' => true]);
        $this->carton = Item::create(['sku' => 'ACC-MF-CTN', 'name' => 'ACC Chain Carton', 'uom' => 'Nos', 'is_production_input' => true]);
        $this->bottle = Item::create(['sku' => 'ACC-MF-BTL', 'name' => 'ACC Chain Bottle', 'uom' => 'Nos']);

        // Seeded THROUGH THE LEDGER, never straight into a balance row: the
        // invariant asserted after every step is "balance == Σ signed
        // movements", and a balance conjured without a movement would fail it
        // for a reason that has nothing to do with this chain.
        app(StockMovementService::class)->recordReceipt(
            itemId: $this->resin->id,
            warehouseId: $this->store->id,
            quantity: '1000',
            unitCost: '1.25',
            reference: 'ACC opening',
            purpose: StockMovementPurpose::Opening,
        );

        $this->floor = $this->userWith(['production.manage']);
        $this->storekeeper = $this->userWith(['inventory.manage']);
    }

    /**
     * Opt-in so ordinary runs stay quiet:
     *   MATERIAL_FLOW_LEDGER_REPORT=1 php artisan test --filter=MaterialFlowChain
     */
    protected function tearDown(): void
    {
        if ($this->ledgerReport !== [] && getenv('MATERIAL_FLOW_LEDGER_REPORT')) {
            $w = fn (string $t, int $n) => str_pad($t, $n);
            fwrite(STDERR, "\n".$w('STEP', 42).$w('RM STORE', 14).$w('PROD/WIP', 14).$w('FG STORE', 14)."CONSUMED\n");
            fwrite(STDERR, str_repeat('-', 98)."\n");
            foreach ($this->ledgerReport as $row) {
                fwrite(STDERR, $w($row['step'], 42).$w($row['rm'], 14).$w($row['wip'], 14).$w($row['fg'], 14).$row['consumed']."\n");
            }
            fwrite(STDERR, "\n");
        }

        parent::tearDown();
    }

    // ---- helpers ----------------------------------------------------------

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

    /** The repo's idiom for changing hands mid-walk — the floor and the store are different people. */
    private function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
    }

    private function balance(Warehouse $warehouse, ?Item $item = null): string
    {
        return bcadd((string) (StockBalance::query()
            ->where('item_id', ($item ?? $this->resin)->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('quantity') ?? '0'), '0', 4);
    }

    /**
     * THE PHASE 5 LEDGER INVARIANT, both directions, plus the read-only
     * command that guards it in production — asserted after EVERY step.
     */
    /**
     * Every step's three balances, in order, so the walk can be READ as well
     * as asserted. The owner asked to see the before/after quantities for the
     * three locations and, specifically, proof that a store issue is not a
     * production consumption — this is that table, produced by the same walk
     * the assertions run on rather than by a second script that might drift
     * from it.
     *
     * @var list<array{step: string, rm: string, wip: string, fg: string, consumed: string}>
     */
    private array $ledgerReport = [];

    private function assertLedgerGreen(string $step): void
    {
        // Recorded BEFORE the invariant is checked, so a failing step still
        // leaves its figures in the table rather than vanishing with the
        // exception.
        $this->ledgerReport[] = [
            'step' => $step,
            'rm' => $this->balance($this->store),
            'wip' => $this->balance($this->wip),
            'fg' => $this->balance($this->fg, $this->bottle),
            // The whole point of the distinction: how much has actually been
            // BOOKED as production use at this moment.
            // Scoped to THE RESIN, the material the three balance columns
            // track. Summing every consumption movement repo-wide happened to
            // be identical on this walk, but the column is headed CONSUMED
            // next to three resin balances and would have quietly started
            // meaning something else the moment a second material was
            // consumed.
            'consumed' => bcadd((string) (StockMovement::query()
                ->where('purpose', StockMovementPurpose::Consumption->value)
                ->where('item_id', $this->resin->id)
                ->sum('quantity') ?: '0'), '0', 4),
        ];

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
            $this->assertSame(0, bccomp($sum, (string) $balances[$key]->quantity, 4),
                "{$step}: {$key} ledger {$sum} vs balance {$balances[$key]->quantity}");
        }
        foreach ($balances as $key => $balance) {
            $this->assertSame(0, bccomp($sums[$key] ?? '0.0000', (string) $balance->quantity, 4),
                "{$step}: balance {$key} holds {$balance->quantity} but the ledger sums to ".($sums[$key] ?? '0.0000'));
        }

        $before = StockMovement::query()->count();
        $this->artisan('inventory:check-ledger')->assertExitCode(0)->run();
        $this->assertSame($before, StockMovement::query()->count(), "{$step}: inventory:check-ledger must not write");
    }

    /** How many movements of one purpose stand against one (item, warehouse), and their total. */
    private function census(Warehouse $warehouse, StockMovementType $type, StockMovementPurpose $purpose): array
    {
        $rows = StockMovement::query()
            ->where('item_id', $this->resin->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('type', $type->value)
            ->where('purpose', $purpose->value)
            ->orderBy('id')
            ->get();

        $total = '0.0000';
        foreach ($rows as $row) {
            $total = bcadd($total, (string) $row->quantity, 4);
        }

        return ['count' => $rows->count(), 'total' => $total];
    }

    private function bag(string $barcode, string $kg): MaterialBag
    {
        $lot = MaterialLot::query()->firstOrCreate(
            ['supplier_lot_no' => 'ACC-LOT-1'],
            [
                'item_id' => $this->resin->id,
                'received_date' => '2026-08-10',
                'bag_count' => 2,
                'total_received_kg' => '50.0000',
            ],
        );

        return $lot->bags()->create([
            'barcode' => $barcode,
            'original_kg' => $kg,
            'remaining_kg' => $kg,
            'status' => MaterialBagStatus::InStore,
            'current_warehouse_id' => $this->store->id,
        ]);
    }

    /** One reading of Tally's godown-wise closing stock — a fixture row, never a Tally connection. */
    private function snapshot(string $closing): TallyStockSnapshot
    {
        return TallyStockSnapshot::create([
            'company' => self::GODOWN,
            'as_of' => '2026-08-17',
            'lines' => [[
                'tally_item_name' => $this->resin->name,
                'godown' => self::GODOWN,
                'closing_quantity' => $closing,
                'closing_rate' => '1.2500',
                'erp_item_id' => $this->resin->id,
                'erp_item_name' => $this->resin->name,
                'erp_warehouse_id' => $this->store->id,
                'erp_warehouse_name' => $this->store->name,
                'importable' => true,
                'problems' => [],
            ]],
            'totals' => ['lines' => 1, 'importable' => 1],
            'status' => TallyStockSnapshot::STATUS_PENDING,
        ]);
    }

    // =======================================================================
    // B2-1 · THE PRODUCTION REQUEST, and FC-01 at the request desk
    // =======================================================================

    public function test_b2_1_a_resin_request_refuses_a_machine_while_a_consumable_carries_one(): void
    {
        $this->actAs($this->floor);
        $machine = WorkCenter::create(['code' => 'ACC-M9', 'name' => 'ACC Machine 9', 'is_active' => true]);

        // The factory has ONE common resin loading point piped to every
        // machine (FC-01, DEC-20260807-006). A request for resin "for machine
        // 9" describes a factory that does not exist, so it is REFUSED — not
        // silently stripped of its machine, which would file the request
        // under a different meaning than the person typed.
        $refusal = $this->postJson('/api/v1/inventory/material-requests', [
            'work_center_id' => $machine->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '250']],
        ])->assertStatus(422);

        $this->assertStringContainsString('FC-01', (string) $refusal->json('message'));
        $this->assertStringContainsString($this->resin->name, (string) $refusal->json('message'));
        $this->assertSame(0, MaterialRequest::query()->count(), 'a refusal is not a half-saved request');

        // The opposite guardrail, and it is equally load-bearing: cartons ARE
        // machine-specific and a consumable request goes through untouched.
        $accepted = $this->postJson('/api/v1/inventory/material-requests', [
            'work_center_id' => $machine->id,
            'lines' => [['item_id' => $this->carton->id, 'quantity' => '40']],
        ])->assertCreated();

        $this->assertSame($machine->id, $accepted->json('data.work_center_id'));
        $this->assertSame('Nos', $accepted->json('data.lines.0.uom'), 'the ITEM\'s unit, never the caller\'s');

        // Paperwork only: a request moves no stock in any state.
        $this->assertSame('1000.0000', $this->balance($this->store));
        $this->assertLedgerGreen('after the request desk');
    }

    // =======================================================================
    // FC-01, STRUCTURALLY: the claim is not merely unwritten, it is unwritable
    // =======================================================================

    public function test_the_erp_cannot_claim_a_batch_consumed_a_specific_bag(): void
    {
        // A bag scan says which bag left the store, to whom, and against which
        // request. It has NO machine column and NO batch column, so the trace
        // stops at the ISSUE by construction — a nullable column here would be
        // an invitation to start writing that claim again.
        $scanColumns = Schema::getColumnListing('store_issue_bag_scans');

        foreach (['work_center_id', 'shift_production_entry_id', 'batch_id', 'production_batch_id', 'entry_id'] as $forbidden) {
            $this->assertNotContains($forbidden, $scanColumns,
                "FC-01: a bag scan must not be able to name a machine or a batch ({$forbidden}).");
        }

        // And the other end of the same rule: the batch's consumption row
        // names an ITEM, a WAREHOUSE and kilograms — never a bag or a lot.
        // Consumption stays CALCULATED (FC-01, DEC-20260807-007).
        $consumptionColumns = Schema::getColumnListing('shift_material_consumptions');

        foreach (['material_bag_id', 'material_lot_id', 'barcode', 'store_issue_bag_scan_id'] as $forbidden) {
            $this->assertNotContains($forbidden, $consumptionColumns,
                "FC-01: a batch's consumption must not be able to name a bag ({$forbidden}).");
        }
    }

    // =======================================================================
    // THE WALK · B2-2 → B2-7
    // =======================================================================

    public function test_b2_2_to_b2_7_the_material_walks_from_the_store_to_the_batch_and_back(): void
    {
        $this->seed(CanonicalMachineSeeder::class);
        $machine = WorkCenter::where('code', 'MC-01')->firstOrFail();
        $shift = Shift::create(['name' => 'ACC Shift A', 'start_time' => '06:00', 'end_time' => '14:00']);

        // -------------------------------------------------------------------
        // B2-1 · THE FLOOR ASKS. 500 kg of a common input, naming no machine.
        // -------------------------------------------------------------------
        $this->actAs($this->floor);

        $request = $this->postJson('/api/v1/inventory/material-requests', [
            'shift_id' => $shift->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '500']],
        ])->assertCreated()->json('data');

        $this->assertSame(MaterialRequestStatus::Draft->value, $request['status']);
        $this->assertNull($request['work_center_id'], 'FC-01: a common-input request names no machine');
        $requestLineId = $request['lines'][0]['id'];

        // Nothing reaches the store's queue until it is submitted — and the
        // store CANNOT be handed material against a draft.
        $submitted = $this->postJson("/api/v1/inventory/material-requests/{$request['id']}/submit")
            ->assertOk()->json('data');
        $this->assertSame(MaterialRequestStatus::Submitted->value, $submitted['status']);
        $this->assertSame('500.0000', $submitted['lines'][0]['remaining_quantity']);

        $this->assertSame('1000.0000', $this->balance($this->store));
        $this->assertSame('0.0000', $this->balance($this->wip));
        $this->assertLedgerGreen('B2-1 the request');

        // -------------------------------------------------------------------
        // B2-2 · THE STORE ISSUE. 300 kg handed over — and it is NOT a
        //        consumption. The stock moves store → Production/WIP.
        // -------------------------------------------------------------------
        $this->actAs($this->storekeeper);

        $issueA = $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $request['id'],
            'received_by' => $this->floor->id,
            'notes' => 'ACC walk — first handover',
            'lines' => [[
                'item_id' => $this->resin->id,
                'quantity' => '300',
                'material_request_line_id' => $requestLineId,
                'quantity_requested' => '500',
            ]],
        ])->assertCreated()->json('data');

        $this->assertSame(StoreIssueStatus::Issued->value, $issueA['status']);
        $this->assertSame($this->store->id, $issueA['lines'][0]['from_warehouse_id']);
        $this->assertSame($this->wip->id, $issueA['lines'][0]['to_warehouse_id']);
        $this->assertSame('300.0000', $issueA['lines'][0]['quantity_issued']);
        $issueALineId = $issueA['lines'][0]['id'];

        // THE STATES, KEPT APART — the whole point of Phase 7.5.
        $this->assertSame('700.0000', $this->balance($this->store), 'the store fell by exactly what it handed over');
        $this->assertSame('300.0000', $this->balance($this->wip), 'and the kilograms are standing in Production/WIP');
        $this->assertSame(0, StockMovement::query()->where('purpose', StockMovementPurpose::Consumption->value)->count(),
            'AN ISSUE IS NOT A CONSUMPTION: no consumption movement may exist yet');

        // It is a signed TRANSFER PAIR, which is why the ledger invariant sees
        // it at all — a purpose-only "issued" flag would have been invisible.
        $out = StockMovement::query()->where('type', StockMovementType::TransferOut)->sole();
        $in = StockMovement::query()->where('type', StockMovementType::TransferIn)->sole();
        $this->assertSame(StockMovementPurpose::IssueToProduction, $out->purpose);
        $this->assertSame(StockMovementPurpose::IssueToProduction, $in->purpose);
        $this->assertSame($out->transfer_group, $in->transfer_group);

        // THE REQUEST AND THE HANDOVER ACTUALLY MEET: the store's queue must
        // not go on showing 500 as still owed against work done this morning.
        $afterFirst = MaterialRequest::query()->with('lines')->findOrFail($request['id']);
        $this->assertSame(MaterialRequestStatus::PartiallyIssued, $afterFirst->status);
        $this->assertSame('300.0000', bcadd((string) $afterFirst->lines->sole()->issued_quantity, '0', 4));
        $this->assertSame('200.0000', $afterFirst->lines->sole()->remainingQuantity());
        $this->assertSame('200.0000', $issueA['lines'][0]['quantity_remaining_on_request']);

        $this->assertLedgerGreen('B2-2 the store issue');

        // -------------------------------------------------------------------
        // B2-3 · THE BAG SCAN. Resin is handed over by scanning bags onto an
        //        open issue: two 25 kg bags, 50 kg, against the same request
        //        line. The scan records bag, lot, kg, who gave, who received,
        //        when — and stops there.
        // -------------------------------------------------------------------
        $this->bag('ACC-BAG-1', '25');
        $this->bag('ACC-BAG-2', '25');

        $issueB = $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $request['id'],
            'received_by' => $this->floor->id,
            'lines' => [],
        ])->assertCreated()->json('data');

        foreach (['ACC-BAG-1', 'ACC-BAG-2'] as $barcode) {
            $scan = $this->postJson("/api/v1/inventory/store-issues/{$issueB['id']}/bag-scans", [
                'barcode' => $barcode,
                'received_by' => $this->floor->id,
                'material_request_line_id' => $requestLineId,
            ])->assertCreated()->json('data');

            $this->assertSame('25.0000', $scan['quantity_kg']);
            $this->assertSame($this->storekeeper->id, $scan['issued_by']);
            $this->assertSame($this->floor->id, $scan['received_by']);
            // FC-01 again, on the payload this time: the scan names no machine.
            $this->assertArrayNotHasKey('work_center_id', $scan);
            $this->assertArrayNotHasKey('shift_production_entry_id', $scan);
        }

        $this->assertSame('650.0000', $this->balance($this->store));
        $this->assertSame('350.0000', $this->balance($this->wip));
        $this->assertSame(0, StockMovement::query()->where('purpose', StockMovementPurpose::Consumption->value)->count(),
            'a bag scan is a POUR RECORD, not a consumption');

        $afterScans = MaterialRequest::query()->with('lines')->findOrFail($request['id']);
        $this->assertSame(MaterialRequestStatus::PartiallyIssued, $afterScans->status);
        $this->assertSame('350.0000', bcadd((string) $afterScans->lines->sole()->issued_quantity, '0', 4));
        $this->assertSame('150.0000', $afterScans->lines->sole()->remainingQuantity());

        $this->assertLedgerGreen('B2-3 the bag scans');

        // -------------------------------------------------------------------
        // B2-4 · THE PRODUCTION RECEIPT.
        //
        // THE HONEST SHAPE OF THIS LINK: there is NO separate "production
        // receipt" document in this ERP, and none is invented here. The
        // receipt IS the arrival in Production/WIP with a NAMED RECEIVER —
        // the handover record carries both hands (issued_by, received_by),
        // every bag scan carries them too, and the kilograms are standing in
        // the location production draws from. That is the transaction model
        // the link is asserted on.
        // -------------------------------------------------------------------
        $received = $this->getJson("/api/v1/inventory/store-issues/{$issueA['id']}")->assertOk()->json('data');
        $this->assertSame($this->storekeeper->id, $received['issued_by']);
        $this->assertSame($this->floor->id, $received['received_by'], 'a handover has two hands and both are named');
        $this->assertNotNull($received['issued_at']);

        $outstanding = $this->getJson('/api/v1/inventory/store-issues/outstanding')->assertOk();
        $row = collect($outstanding->json('data'))->firstWhere('item_id', $this->resin->id);
        $this->assertSame('350.0000', $row['quantity_issued']);
        $this->assertSame('0.0000', $row['quantity_returned']);
        $this->assertSame('350.0000', $row['quantity_not_returned'], 'what the store handed over and has not had back');
        $this->assertSame('350.0000', $row['quantity_in_production_wip'], 'and what the WIP balance actually holds');
        $this->assertSame('issued_to_production', $row['state']);
        // The word that must not appear: a "consumed" column on the store's
        // own paperwork is exactly how the three states get collapsed to two.
        $this->assertArrayNotHasKey('quantity_consumed', $row);

        $this->assertLedgerGreen('B2-4 the production receipt');

        // -------------------------------------------------------------------
        // B2-7a · THE RECONCILIATION, WITH THE ISSUE OPEN.
        //
        // No Tally voucher is raised for a handover — the books only ever see
        // the CONSUMPTION, at batch approval. So Tally still holds all 1,000
        // while the ERP's STORE holds 650. Read naively that is a 350 kg
        // difference, and "correcting" it would receipt a second copy of
        // material standing on the factory floor. It must read as NO DRIFT.
        // (Asserted here, while the issue is open and nothing has been eaten,
        // because that is precisely the state the hazard lives in.)
        // -------------------------------------------------------------------
        $openIssueReconcile = app(TallyStockReconcileService::class)->apply($this->snapshot('1000.0000'), null, true);

        $this->assertSame(0, $openIssueReconcile['matched'], 'AN OPEN ISSUE MUST NOT READ AS DRIFT');
        $this->assertSame(1, $openIssueReconcile['already_equal']);
        $this->assertSame([], $openIssueReconcile['changes']);
        $this->assertSame([], $openIssueReconcile['skipped']);
        $this->assertFalse(
            StockMovement::query()->where('reference', 'like', 'TALLY-RECONCILE-%')->exists(),
            'nothing may be moved to correct material that is simply on the floor',
        );
        // And the material is REPORTED as what it is, never silently absorbed.
        $this->assertSame('ACC Production WIP', $openIssueReconcile['production_wip']['warehouse']);
        $this->assertSame(self::GODOWN, $openIssueReconcile['production_wip']['godown']);
        $this->assertSame([[
            'item_id' => $this->resin->id,
            'item' => $this->resin->name,
            'quantity' => '350.0000',
            'counted_with_godown' => true,
        ]], $openIssueReconcile['production_wip']['lines']);

        $this->assertSame('650.0000', $this->balance($this->store));
        $this->assertSame('350.0000', $this->balance($this->wip));
        $this->assertLedgerGreen('B2-7a the reconcile with an issue open');

        // -------------------------------------------------------------------
        // B2-5 · THE BATCH CONSUMPTION. The middle state empties into the
        //        third one — and the STORE is not touched again.
        // -------------------------------------------------------------------
        $this->actAs($this->floor);

        $entryId = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => self::PRODUCTION_DATE,
        ])->assertOk()->json('data.id');

        // NO warehouse_id on the consumption line: the server answers where
        // the material came out of, and after a store issue that answer is
        // Production/WIP.
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => '8000',
            'running_hours' => '8',
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'quantity_issued_kg' => '120'],
            ],
        ])->assertOk();

        $consumption = StockMovement::query()
            ->where('type', StockMovementType::Issue)
            ->where('purpose', StockMovementPurpose::Consumption->value)
            ->sole();

        $this->assertSame($this->wip->id, $consumption->warehouse_id,
            'consumption moves stock OUT OF Production/WIP, not out of the store');
        $this->assertSame('120.0000', bcadd((string) $consumption->quantity, '0', 4));

        $consumptionRow = ShiftMaterialConsumption::query()->where('shift_production_entry_id', $entryId)->sole();
        $this->assertSame($this->wip->id, (int) $consumptionRow->warehouse_id);
        $this->assertSame($this->resin->id, (int) $consumptionRow->item_id);

        // THE THREE STATES, ALL THREE VISIBLE AT ONCE AND ALL THREE DIFFERENT:
        $this->assertSame('650.0000', $this->balance($this->store), 'THE STORE DID NOT FALL A SECOND TIME');
        $this->assertSame('230.0000', $this->balance($this->wip), 'the middle state fell by exactly what was eaten');

        // The store's own paperwork does NOT fall when a batch consumes: the
        // store was never told, because consumption is the batch's own
        // calculation (FC-01). The gap between the two figures IS the
        // consumption, and both figures stay on the read.
        $this->actAs($this->storekeeper);
        $afterBatch = collect($this->getJson('/api/v1/inventory/store-issues/outstanding')->assertOk()->json('data'))
            ->firstWhere('item_id', $this->resin->id);
        $this->assertSame('350.0000', $afterBatch['quantity_not_returned']);
        $this->assertSame('230.0000', $afterBatch['quantity_in_production_wip']);
        $this->assertSame('120.0000', bcsub($afterBatch['quantity_not_returned'], $afterBatch['quantity_in_production_wip'], 4));

        $this->assertLedgerGreen('B2-5 the batch consumption');

        // -------------------------------------------------------------------
        // B2-5b · THE CONSUMPTION TRACES BACK TO ITS ISSUE — and stops there.
        // -------------------------------------------------------------------
        $trace = $this->getJson("/api/v1/inventory/store-issues/trace?item_id={$this->resin->id}")
            ->assertOk()->json('data');

        // INCLUSION as well as exclusion: BOTH handovers that put this
        // material into production are named, the typed one and the scanned
        // one — a trace that quietly dropped one would still "trace".
        $this->assertSame(
            [$issueA['issue_number'], $issueB['issue_number']],
            array_column($trace['issues'], 'issue_number'),
        );
        $this->assertSame(['ACC-BAG-1', 'ACC-BAG-2'], array_column($trace['issues'][1]['bags'], 'barcode'));
        // The bags are named as what LEFT THE STORE. The sentence the answer
        // carries is the FC-01 boundary itself: no batch is mentioned, and
        // none can be.
        $this->assertSame(
            'These issues put this material into Production/WIP before that moment. A batch\'s consumption is '
            .'calculated, so this is a location trace, never a claim that a batch used a particular bag.',
            $trace['basis'],
        );
        // Checked on the KEYS, not by scanning the prose: the basis sentence
        // itself says the word "batch" (to deny the claim), and a text scan
        // would either fail on that or have to be weakened until it proved
        // nothing. What must not exist is a FIELD tying this trace to a run.
        $this->assertSame(
            ['store_issue_id', 'issue_number', 'material_request_id', 'issued_at', 'issued_by',
                'received_by', 'quantity_issued', 'quantity_returned', 'bags'],
            array_keys($trace['issues'][1]),
            'FC-01: the trace stops at the ISSUE — no field on it may reach a batch or a machine.',
        );
        $this->assertSame(['barcode', 'material_lot_id', 'quantity_kg'], array_keys($trace['issues'][1]['bags'][0]),
            'FC-01: a scanned bag is named as what LEFT THE STORE, with nothing pointing at a run.');

        // -------------------------------------------------------------------
        // B2-6 · REMAINING / RETURN. 80 kg never went into the machine and
        //        comes home — Production/WIP → the store it came out of.
        // -------------------------------------------------------------------
        $this->postJson("/api/v1/inventory/store-issues/{$issueA['id']}/returns", [
            'lines' => [['store_issue_line_id' => $issueALineId, 'quantity' => '80']],
            'notes' => 'ACC walk — unused resin back to the store',
        ])->assertOk();

        $this->assertSame('730.0000', $this->balance($this->store), 'A RETURN PUTS IT BACK IN THE STORE');
        $this->assertSame('150.0000', $this->balance($this->wip));
        $this->assertSame(StoreIssueStatus::PartiallyReturned, StoreIssue::query()->findOrFail($issueA['id'])->status);

        $returnIn = StockMovement::query()
            ->where('type', StockMovementType::TransferIn)
            ->where('purpose', StockMovementPurpose::ReturnFromProduction->value)
            ->sole();
        $this->assertSame($this->store->id, $returnIn->warehouse_id);
        $this->assertSame('80.0000', bcadd((string) $returnIn->quantity, '0', 4));

        // A RETURN IS NOT AN UN-ISSUE. The store DID hand 350 over; 80 of it
        // came back later. The request keeps saying what happened.
        $afterReturn = MaterialRequest::query()->with('lines')->findOrFail($request['id']);
        $this->assertSame('350.0000', bcadd((string) $afterReturn->lines->sole()->issued_quantity, '0', 4));
        $this->assertSame('150.0000', $afterReturn->lines->sole()->remainingQuantity());

        $this->assertLedgerGreen('B2-6 the return');

        // -------------------------------------------------------------------
        // B2-6b · THE FALSIFIER. A return may not drain material that
        //         production has already eaten — otherwise the "return" would
        //         quietly take the OTHER issue's kilograms out of the pooled
        //         WIP balance and the arithmetic would still add up.
        // -------------------------------------------------------------------
        $overReach = $this->postJson("/api/v1/inventory/store-issues/{$issueA['id']}/returns", [
            'lines' => [['store_issue_line_id' => $issueALineId, 'quantity' => '220']],
        ])->assertStatus(422);

        $this->assertStringContainsString(
            'Production has already consumed against store issue',
            (string) $overReach->json('errors')['lines.0'][0],
        );
        $this->assertSame('730.0000', $this->balance($this->store), 'a refusal moves nothing');
        $this->assertSame('150.0000', $this->balance($this->wip));
        $this->assertLedgerGreen('B2-6b the refused return');

        // -------------------------------------------------------------------
        // THE CENSUS. "The store's balance never falls twice for the same
        // material", proved by COUNTING movements rather than by reading a
        // total that happens to look right: the store's only outflows are the
        // three issue_to_production transfers (one typed line + two bag
        // scans) totalling exactly 350, its only inflows are the opening and
        // the single 80 kg return, and NO consumption was ever booked against
        // it.
        // -------------------------------------------------------------------
        $storeOut = $this->census($this->store, StockMovementType::TransferOut, StockMovementPurpose::IssueToProduction);
        $this->assertSame(3, $storeOut['count']);
        $this->assertSame('350.0000', $storeOut['total']);

        $storeBack = $this->census($this->store, StockMovementType::TransferIn, StockMovementPurpose::ReturnFromProduction);
        $this->assertSame(1, $storeBack['count']);
        $this->assertSame('80.0000', $storeBack['total']);

        $storeConsumption = $this->census($this->store, StockMovementType::Issue, StockMovementPurpose::Consumption);
        $this->assertSame(0, $storeConsumption['count'], 'the store must never be charged a consumption after an issue');

        $wipConsumption = $this->census($this->wip, StockMovementType::Issue, StockMovementPurpose::Consumption);
        $this->assertSame(1, $wipConsumption['count']);
        $this->assertSame('120.0000', $wipConsumption['total']);

        // 1000 − 350 + 80 = 730, by the census and by the balance alike.
        $this->assertSame('730.0000', $this->balance($this->store));

        // -------------------------------------------------------------------
        // B2-7b · THE RECONCILIATION AT THE END OF THE WALK, AS A DRY RUN.
        //
        // Nothing this walk did was ever posted to Tally: no voucher is
        // raised for a handover or for a return, and the batch was not
        // approved, so the books still show the opening 1,000. The ERP now
        // holds 730 in the store and 150 standing in production — 880 — and
        // the ONLY difference is the 120 the batch ate that the books have
        // not seen. The issue and the return contributed ZERO drift.
        //
        // Run with write: false, so this proof moves nothing at all.
        // -------------------------------------------------------------------
        $movementsBefore = StockMovement::query()->count();

        $dryRun = app(TallyStockReconcileService::class)->apply($this->snapshot('1000.0000'), null, false);

        $this->assertSame([], $dryRun['skipped']);
        $this->assertCount(1, $dryRun['changes']);
        $this->assertSame('730.0000', $dryRun['changes'][0]['erp'], 'the store');
        $this->assertSame('150.0000', $dryRun['changes'][0]['production_wip'], 'standing on the floor');
        $this->assertSame('880.0000', $dryRun['changes'][0]['erp_including_wip']);
        $this->assertSame('1000.0000', $dryRun['changes'][0]['tally']);
        $this->assertSame('120.0000', $dryRun['changes'][0]['difference'],
            'the ONLY gap is the consumption the books have not seen — the issue and the return drift by nothing');

        $this->assertSame($movementsBefore, StockMovement::query()->count(), 'a dry run writes nothing');
        $this->assertSame('730.0000', $this->balance($this->store));
        $this->assertSame('150.0000', $this->balance($this->wip));
        $this->assertLedgerGreen('B2-7b the closing reconcile');
    }
}
