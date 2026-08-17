<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * stock_movements.purpose (Phase 5, P5-05): WHY a movement happened, beside
 * the TYPE that says which way the quantity went. What these pin:
 *
 *   1. a movement recorded without a purpose is 'unknown' — never null, never
 *      guessed — so every existing caller of StockMovementService keeps
 *      writing exactly what it wrote before, plus one honest word;
 *   2. a purpose a caller names is stored as named, on receipts, issues and
 *      both legs of a transfer;
 *   3. the resource shows it;
 *   4. the backfill migration classifies a historical row ONLY from the
 *      reference shapes the code itself generates (one writer per shape),
 *      leaves everything else 'unknown', logs how many of each, and its
 *      down() takes the column back to null.
 */
class StockMovementPurposeTest extends TestCase
{
    use RefreshDatabase;

    private const BACKFILL = 'migrations/2026_08_17_150001_backfill_stock_movement_purpose.php';

    private Item $resin;

    private Warehouse $store;

    private Warehouse $bin;

    private StockMovementService $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resin = Item::create(['sku' => 'RES-1', 'name' => 'PET Resin', 'uom' => 'Kgs']);
        $this->store = Warehouse::create(['code' => 'RM', 'name' => 'RM Store']);
        $this->bin = Warehouse::create(['code' => 'BIN', 'name' => 'Day Bin']);
        $this->stock = app(StockMovementService::class);
    }

    public function test_a_movement_recorded_without_a_purpose_is_unknown_on_every_type(): void
    {
        $receipt = $this->stock->recordReceipt(itemId: $this->resin->id, warehouseId: $this->store->id, quantity: '100', unitCost: '90');
        $issue = $this->stock->recordIssue(itemId: $this->resin->id, warehouseId: $this->store->id, quantity: '10');
        [$out, $in] = $this->stock->recordTransfer(itemId: $this->resin->id, fromWarehouseId: $this->store->id, toWarehouseId: $this->bin->id, quantity: '5');

        foreach ([$receipt, $issue, $out, $in] as $movement) {
            $this->assertSame(StockMovementPurpose::Unknown, $movement->fresh()->purpose);
        }

        $this->assertSame(0, StockMovement::query()->whereNull('purpose')->count(), 'A recorded movement is never left without a purpose.');
    }

    public function test_a_purpose_the_caller_names_is_stored_as_named(): void
    {
        $receipt = $this->stock->recordReceipt(itemId: $this->resin->id, warehouseId: $this->store->id, quantity: '100', unitCost: '90', purpose: StockMovementPurpose::Receipt);
        $issue = $this->stock->recordIssue(itemId: $this->resin->id, warehouseId: $this->store->id, quantity: '10', purpose: StockMovementPurpose::Consumption);
        [$out, $in] = $this->stock->recordTransfer(itemId: $this->resin->id, fromWarehouseId: $this->store->id, toWarehouseId: $this->bin->id, quantity: '5', purpose: StockMovementPurpose::Adjustment);

        $this->assertSame(StockMovementPurpose::Receipt, $receipt->fresh()->purpose);
        $this->assertSame(StockMovementPurpose::Consumption, $issue->fresh()->purpose);
        $this->assertSame(StockMovementPurpose::Adjustment, $out->fresh()->purpose);
        $this->assertSame(StockMovementPurpose::Adjustment, $in->fresh()->purpose);
    }

    public function test_the_purpose_rides_the_movement_resource(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['inventory.view', 'inventory.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        $this->stock->recordReceipt(itemId: $this->resin->id, warehouseId: $this->store->id, quantity: '100', unitCost: '90', purpose: StockMovementPurpose::Opening);
        $this->stock->recordIssue(itemId: $this->resin->id, warehouseId: $this->store->id, quantity: '10');

        $this->getJson('/api/v1/inventory/stock-movements')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'issue')
            ->assertJsonPath('data.0.purpose', 'unknown')
            ->assertJsonPath('data.1.type', 'receipt')
            ->assertJsonPath('data.1.purpose', 'opening');
    }

    // ---- the backfill ------------------------------------------------------

    /**
     * A historical row, written the way rows existed before the column: no
     * purpose at all. Straight into the table so the service's own default
     * cannot pre-fill what the migration is supposed to decide.
     */
    private function legacyRow(string $type, ?string $reference, ?string $notes = null): int
    {
        return (int) DB::table('stock_movements')->insertGetId([
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->store->id,
            'type' => $type,
            'quantity' => '1.0000',
            'unit_cost' => '0.0000',
            'reference' => $reference,
            'movement_date' => '2026-08-01 10:00:00',
            'notes' => $notes,
            'purpose' => null,
            'created_at' => '2026-08-01 10:00:00',
            'updated_at' => '2026-08-01 10:00:00',
        ]);
    }

    private function purposeOf(int $id): ?string
    {
        return DB::table('stock_movements')->where('id', $id)->value('purpose');
    }

    public function test_the_backfill_classifies_only_the_generated_reference_shapes_and_leaves_the_rest_unknown(): void
    {
        Log::spy();

        // The shapes exactly one writer generates — classified.
        $grn = $this->legacyRow('receipt', 'GRN for PO #12');
        $consumption = $this->legacyRow('issue', 'SPE #7');
        $output = $this->legacyRow('receipt', 'SPE #7');
        $dispatch = $this->legacyRow('issue', 'Delivery for SO #3');
        $opening = $this->legacyRow('receipt', 'Tally opening 2026-07-31');
        $reconcileIn = $this->legacyRow('receipt', 'TALLY-RECONCILE-4');
        $reconcileOut = $this->legacyRow('issue', 'TALLY-RECONCILE-4');

        // Everything else — a reversal, a QC move, a work order, a manual
        // reference the user typed, no reference, a shape on the WRONG type,
        // a shape with trailing text — is left 'unknown', never guessed.
        $amended = $this->legacyRow('receipt', 'SPE #7 amended');
        $qc = $this->legacyRow('issue', 'QC #7');
        $qcReturned = $this->legacyRow('receipt', 'QC #7 returned');
        $workOrder = $this->legacyRow('issue', 'WO #2');
        $manual = $this->legacyRow('receipt', 'LR-1001');
        $bare = $this->legacyRow('issue', null);
        $grnOnIssue = $this->legacyRow('issue', 'GRN for PO #12');
        $dispatchOnReceipt = $this->legacyRow('receipt', 'Delivery for SO #3');
        $transferOut = $this->legacyRow('transfer_out', 'SPE #7');
        $notesOnly = $this->legacyRow('receipt', null, 'Opening balance read from Tally stock summary.');
        $trailing = $this->legacyRow('issue', 'SPE #7 (manual)');

        // A row that already carries a purpose is not re-decided.
        $decided = $this->legacyRow('receipt', 'GRN for PO #99');
        DB::table('stock_movements')->where('id', $decided)->update(['purpose' => 'adjustment']);

        (require database_path(self::BACKFILL))->up();

        $this->assertSame('receipt', $this->purposeOf($grn));
        $this->assertSame('consumption', $this->purposeOf($consumption));
        $this->assertSame('output', $this->purposeOf($output));
        $this->assertSame('dispatch', $this->purposeOf($dispatch));
        $this->assertSame('opening', $this->purposeOf($opening));
        $this->assertSame('reconcile', $this->purposeOf($reconcileIn));
        $this->assertSame('reconcile', $this->purposeOf($reconcileOut));

        foreach ([$amended, $qc, $qcReturned, $workOrder, $manual, $bare, $grnOnIssue, $dispatchOnReceipt, $transferOut, $notesOnly, $trailing] as $id) {
            $this->assertSame('unknown', $this->purposeOf($id), "row #{$id} must be left unknown");
        }

        $this->assertSame('adjustment', $this->purposeOf($decided));
        $this->assertSame(0, DB::table('stock_movements')->whereNull('purpose')->count());

        // Counted and logged, per purpose.
        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message) => str_contains($message, 'stock_movements.purpose backfill')
                && str_contains($message, 'receipt=1')
                && str_contains($message, 'consumption=1')
                && str_contains($message, 'output=1')
                && str_contains($message, 'dispatch=1')
                && str_contains($message, 'opening=1')
                && str_contains($message, 'reconcile=2')
                && str_contains($message, 'unknown=11')
                && str_contains($message, 'already set=1'))
            ->once();
    }

    public function test_the_backfill_reverses_to_the_null_column_it_found(): void
    {
        Log::spy();

        $grn = $this->legacyRow('receipt', 'GRN for PO #12');
        $manual = $this->legacyRow('receipt', 'LR-1001');

        $migration = require database_path(self::BACKFILL);
        $migration->up();
        $this->assertSame('receipt', $this->purposeOf($grn));
        $this->assertSame('unknown', $this->purposeOf($manual));

        $migration->down();
        $this->assertNull($this->purposeOf($grn));
        $this->assertNull($this->purposeOf($manual));
    }
}
