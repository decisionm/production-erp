<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Maintenance\Models\Asset;
use App\Modules\Maintenance\Models\Enums\MaintenanceWorkOrderStatus;
use App\Modules\Maintenance\Models\Enums\MaintenanceWorkOrderType;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftMaterialConsumption;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\FactoryDayBinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * FC-06 on the five payloads that carry the purchase rate AFTER the goods
 * receipt: a stock movement's unit_cost (a receipt movement's IS the GRN
 * rate), a stock balance's average_cost (the weighted average of those
 * rates), the factory day bin's per-material average_cost (a StockBalance
 * wrapped for the supervisor's page), a maintenance part's unit_cost
 * (drawn from stock at that rate), and a completed batch's per-line
 * material_cost (each consumption priced at its issue movement's
 * unit_cost). All five hang off finance.view / finance.manage and are
 * OMITTED (never nulled) for everyone else — exactly the
 * MaterialLotResource rule, and the same walk-the-whole-payload check
 * ProcurementRateVisibilityTest uses on the procurement side.
 *
 * Each negative case uses a user who holds ONLY the module the endpoint sits
 * under (inventory / production / maintenance) and no finance permission —
 * real role shapes, and the ones every one of these keys was open to.
 */
class StockRateVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const RATE_KEYS = ['unit_cost', 'average_cost'];

    private Item $resin;

    private Warehouse $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resin = Item::create(['sku' => 'RM-PET', 'name' => 'PET Resin', 'uom' => 'Kgs']);
        $this->store = Warehouse::create(['code' => 'RM-STORE', 'name' => 'RM Store']);
    }

    /** @param array<int, string> $permissions */
    private function actingAsWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Every key path in the payload, at any depth, whose name is one of the
     * rate keys — a walk, not a handful of guessed assertJsonMissingPath
     * calls, so a rate that reappears anywhere (a nested block, an embedded
     * movement list) fails this test instead of slipping past it.
     *
     * @return array<int, string>
     */
    private function rateKeyPaths(mixed $node, string $path = ''): array
    {
        if (! is_array($node)) {
            return [];
        }

        $found = [];
        foreach ($node as $key => $value) {
            $here = $path === '' ? (string) $key : "{$path}.{$key}";
            if (in_array($key, self::RATE_KEYS, true)) {
                $found[] = $here;
            }
            $found = [...$found, ...$this->rateKeyPaths($value, $here)];
        }

        return $found;
    }

    /** @return array<string, mixed> */
    private function manualReceiptPayload(string $unitCost = '96.5000'): array
    {
        return [
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->store->id,
            'quantity' => '1200',
            'unit_cost' => $unitCost,
            'reference' => 'Opening count',
        ];
    }

    // (a)+(b) stock movements and balances ------------------------------------

    public function test_an_inventory_only_user_never_sees_a_rate_on_movements_or_balances(): void
    {
        $this->actingAsWith(['inventory.view', 'inventory.manage']);

        // The store user records the receipt themselves — the rate is
        // write-only for them (StoreStockReceiptRequest requires it because
        // it feeds the weighted average), and the store response is a full
        // resource, so it is checked as well.
        $created = $this->postJson('/api/v1/inventory/stock-movements/receipts', $this->manualReceiptPayload())
            ->assertSuccessful()
            ->assertJsonPath('data.quantity', '1200.0000')
            ->json();
        $this->assertSame([], $this->rateKeyPaths($created), 'receipt store response leaked a rate key');

        $movements = $this->getJson('/api/v1/inventory/stock-movements')
            ->assertSuccessful()
            ->assertJsonPath('data.0.type', 'receipt')
            ->assertJsonPath('data.0.quantity', '1200.0000')
            ->json();
        $this->assertSame([], $this->rateKeyPaths($movements), 'stock-movements leaked a rate key');

        // The item detail page reads its history through the item_id filter
        // (there is no embedded movement list on GET items/{item}) — same
        // resource, checked on that path too.
        $itemHistory = $this->getJson("/api/v1/inventory/stock-movements?item_id={$this->resin->id}")
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->json();
        $this->assertSame([], $this->rateKeyPaths($itemHistory), 'item movement history leaked a rate key');

        $balances = $this->getJson('/api/v1/inventory/stock-balances')
            ->assertSuccessful()
            ->assertJsonPath('data.0.quantity', '1200.0000')
            ->json();
        $this->assertSame([], $this->rateKeyPaths($balances), 'stock-balances leaked a rate key');

        // ABSENT, not null — belt and braces on the exact paths too. A nulled
        // cost would read as "this resin cost nothing" (opening stock, which
        // legitimately has no rate, is a real null here).
        $this->assertArrayNotHasKey('unit_cost', $movements['data'][0]);
        $this->assertArrayNotHasKey('average_cost', $balances['data'][0]);
    }

    public function test_a_finance_user_sees_the_rate_on_movements_and_balances(): void
    {
        $this->actingAsWith(['inventory.view', 'inventory.manage']);
        $this->postJson('/api/v1/inventory/stock-movements/receipts', $this->manualReceiptPayload('102.7500'))
            ->assertSuccessful();

        // finance.view alone is the gate; inventory.view lets the same person
        // reach the endpoints at all.
        $this->actingAsWith(['inventory.view', 'finance.view']);

        $this->getJson('/api/v1/inventory/stock-movements')
            ->assertSuccessful()
            ->assertJsonPath('data.0.unit_cost', '102.7500');

        $this->getJson('/api/v1/inventory/stock-balances')
            ->assertSuccessful()
            ->assertJsonPath('data.0.average_cost', '102.7500');
    }

    // (c) the factory day bin --------------------------------------------------

    public function test_a_production_only_user_never_sees_a_rate_on_the_day_bin(): void
    {
        $dayBin = Warehouse::create(['code' => 'WH-DAYBIN', 'name' => 'Factory Day Bin']);
        app(FactoryDayBinService::class)->setWarehouseId($dayBin->id);

        // Material in the bin is an ordinary stock balance with a weighted
        // average — the very number the supervisor's page must not carry.
        StockBalance::create([
            'item_id' => $this->resin->id,
            'warehouse_id' => $dayBin->id,
            'quantity' => '1250.5000',
            'average_cost' => '85.0000',
        ]);

        $this->actingAsWith(['production.view', 'production.manage']);

        $bin = $this->getJson('/api/v1/production/factory-day-bin')
            ->assertOk()
            ->assertJsonPath('data.warehouse.id', $dayBin->id)
            ->assertJsonPath('data.materials.0.item_id', $this->resin->id)
            ->assertJsonPath('data.materials.0.quantity_kg', '1250.5000')
            ->json();
        $this->assertSame([], $this->rateKeyPaths($bin), 'factory-day-bin leaked a rate key');
        $this->assertArrayNotHasKey('average_cost', $bin['data']['materials'][0]);

        // The Owner/Accounts view of the same bin carries it.
        $this->actingAsWith(['production.view', 'finance.view']);

        $this->getJson('/api/v1/production/factory-day-bin')
            ->assertOk()
            ->assertJsonPath('data.materials.0.average_cost', '85.0000');
    }

    // (d) maintenance work-order parts ----------------------------------------

    public function test_a_maintenance_only_user_never_sees_a_rate_on_work_order_parts(): void
    {
        // A spare on the shelf at a known purchase rate.
        StockBalance::create([
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->store->id,
            'quantity' => '50.0000',
            'average_cost' => '410.0000',
        ]);
        $asset = Asset::create(['code' => 'MC-01', 'name' => 'Machine 1', 'status' => 'active']);
        $workOrder = MaintenanceWorkOrder::create([
            'asset_id' => $asset->id,
            'type' => MaintenanceWorkOrderType::Corrective,
            'status' => MaintenanceWorkOrderStatus::Open,
            'reported_date' => '2026-08-16',
            'labor_cost' => 0,
            'parts_cost' => 0,
            'total_cost' => 0,
        ]);

        $this->actingAsWith(['maintenance.view', 'maintenance.manage']);

        // The technician draws the part; the response is a full work-order
        // resource with the part nested — checked as well.
        $drawn = $this->postJson("/api/v1/maintenance/work-orders/{$workOrder->id}/parts", [
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->store->id,
            'quantity' => '2',
        ])
            ->assertSuccessful()
            ->assertJsonPath('data.parts.0.item.id', $this->resin->id)
            ->json();
        $this->assertSame([], $this->rateKeyPaths($drawn), 'add-part response leaked a rate key');

        $orders = $this->getJson('/api/v1/maintenance/work-orders')
            ->assertSuccessful()
            ->assertJsonPath('data.0.id', $workOrder->id)
            ->assertJsonCount(1, 'data.0.parts')
            ->json();
        $this->assertSame([], $this->rateKeyPaths($orders), 'maintenance work-orders leaked a rate key');
        $this->assertArrayNotHasKey('unit_cost', $orders['data'][0]['parts'][0]);
        // The order-level totals are the same rate one division away —
        // parts_cost / quantity on a one-part order IS unit_cost — so they
        // are absent too. labor_cost carries no purchase rate and stays.
        $this->assertArrayNotHasKey('parts_cost', $orders['data'][0]);
        $this->assertArrayNotHasKey('total_cost', $orders['data'][0]);
        $this->assertArrayHasKey('labor_cost', $orders['data'][0]);

        // finance.view alone opens it; maintenance.view reaches the endpoint.
        $this->actingAsWith(['maintenance.view', 'finance.view']);

        // The part model carries no decimal cast, so the wire form of the
        // number is the driver's (a string on MySQL, a number on SQLite) —
        // compare the value, not its spelling.
        $unitCost = $this->getJson('/api/v1/maintenance/work-orders')
            ->assertSuccessful()
            ->json('data.0.parts.0.unit_cost');
        $this->assertNotNull($unitCost);
        $this->assertSame(0, bccomp((string) $unitCost, '410.0000', 4));

        $order = $this->getJson('/api/v1/maintenance/work-orders')->json('data.0');
        $this->assertArrayHasKey('parts_cost', $order);
        $this->assertArrayHasKey('total_cost', $order);
    }

    // (e) a completed batch's material_cost lines ------------------------------

    /**
     * A completed batch that consumed 50 kg of resin issued at ₹96.50 — built
     * directly (the entry, its consumption line, the issue movement priced
     * off the receipt) rather than through the floor endpoints, because what
     * this case is about is who may read the rate, not how a batch comes to
     * exist. Returns the entry id.
     */
    private function completedBatchWithPricedConsumption(): int
    {
        $this->actingAsWith(['inventory.view', 'inventory.manage']);
        $this->postJson('/api/v1/inventory/stock-movements/receipts', $this->manualReceiptPayload('96.5000'))
            ->assertSuccessful();

        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
        $bottle = Item::create(['sku' => 'BTL-500', 'name' => '500 ml Bottle', 'uom' => 'Nos']);

        $entry = ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $bottle->id,
            'warehouse_id' => $this->store->id,
            'production_date' => '2026-08-16',
            'batch_status' => BatchStatus::Completed,
            'batch_number' => 'BATCH-1',
            'quantity_produced' => '10000',
            'status' => ShiftProductionEntryStatus::Pending,
        ]);

        ShiftMaterialConsumption::create([
            'shift_production_entry_id' => $entry->id,
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->store->id,
            'quantity_issued_kg' => '50.0000',
        ]);

        // The issue materialCost() prices the line from — keyed on the
        // "SPE #id" reference exactly as completeBatch writes it.
        app(StockMovementService::class)->recordIssue(
            itemId: $this->resin->id,
            warehouseId: $this->store->id,
            quantity: '50',
            reference: "SPE #{$entry->id}",
        );

        return $entry->id;
    }

    /** @return array<string, mixed> */
    private function entryFromIndex(int $entryId): array
    {
        $rows = $this->getJson('/api/v1/production/shift-production-entries')
            ->assertOk()
            ->json('data');

        $row = collect($rows)->firstWhere('id', $entryId);
        $this->assertNotNull($row, "entry {$entryId} missing from the shift-production-entries index");

        return $row;
    }

    public function test_a_production_only_user_never_sees_a_rate_on_a_batch_material_cost(): void
    {
        $entryId = $this->completedBatchWithPricedConsumption();

        $this->actingAsWith(['production.view', 'production.manage']);
        $row = $this->entryFromIndex($entryId);

        // The floor legitimately reads the total and the consumption lines
        // (which material, from where, how many kg)…
        $this->assertSame('4825.0000', $row['material_cost']['total_cost']);
        $this->assertCount(1, $row['material_cost']['lines']);
        $this->assertSame($this->resin->id, $row['material_cost']['lines'][0]['item_id']);
        // The consumption model carries no decimal cast, so compare the
        // value, not its spelling (same as the maintenance case above).
        $this->assertSame(0, bccomp((string) $row['material_cost']['lines'][0]['quantity_issued_kg'], '50', 4));
        // …but the per-line rate is ABSENT, not null (a null unit_cost is a
        // real answer here — "this issue was unpriced"), and so is the line's
        // own amount, which is that rate one division away.
        $this->assertArrayNotHasKey('unit_cost', $row['material_cost']['lines'][0]);
        $this->assertArrayNotHasKey('cost', $row['material_cost']['lines'][0]);
        $this->assertSame([], $this->rateKeyPaths($row['material_cost']), 'material_cost leaked a rate key');
        // batch_cost keeps its own detail gate — nothing on the whole row
        // carries a rate key for a production login.
        $this->assertSame([], $this->rateKeyPaths($row), 'shift-production-entry row leaked a rate key');

        // finance.view alone opens it; production.view reaches the endpoint.
        $this->actingAsWith(['production.view', 'finance.view']);
        $row = $this->entryFromIndex($entryId);

        $this->assertSame('96.5000', $row['material_cost']['lines'][0]['unit_cost']);
        $this->assertSame('4825.0000', $row['material_cost']['lines'][0]['cost']);
        $this->assertSame('4825.0000', $row['material_cost']['total_cost']);
    }
}
