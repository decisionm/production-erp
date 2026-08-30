<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Core\Services\AppSettingService;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A GOODS RECEIPT MAY NOT LAND IN PRODUCTION/WIP — 28-Aug audit finding 4,
 * closed by DEC-20260817-001's own definition rather than an invented rule:
 * Production/WIP holds material "physically issued to production but not
 * yet consumed", and a purchase arriving from a vendor has been issued to
 * nothing. The refusal is the request's (StoreGoodsReceiptRequest), the WIP
 * row is the resolver's (ProductionWipLocationResolver — the setting first,
 * else the sole code-'WIP' row), and every OTHER active warehouse stays
 * selectable, because which store receives which material is the
 * receiver's call, not the code's (Q59/Q64 open).
 */
class GoodsReceiptWarehouseGuardTest extends TestCase
{
    use RefreshDatabase;

    private Item $resin;

    private Vendor $vendor;

    private PurchaseOrder $order;

    private int $lineId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resin = Item::create(['sku' => 'ITEM_A', 'name' => 'ITEM_A', 'uom' => 'Kgs', 'is_active' => true]);
        $this->vendor = Vendor::create(['code' => 'VND-A', 'name' => 'Vendor Alpha']);
        $this->order = PurchaseOrder::create(['vendor_id' => $this->vendor->id, 'status' => PurchaseOrderStatus::Sent, 'order_date' => '2026-08-01']);
        $this->lineId = $this->order->lines()->create(['item_id' => $this->resin->id, 'quantity' => '1000', 'unit_price' => '1', 'quantity_received' => '0'])->id;

        $user = User::factory()->create(['is_active' => true]);
        foreach (['procurement.view', 'procurement.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);
    }

    public function test_receiving_into_the_wip_row_by_code_is_refused_and_names_the_decision(): void
    {
        $wip = Warehouse::create(['code' => 'WIP', 'name' => 'Production WIP', 'is_active' => true]);

        $this->receiveInto($wip)
            ->assertStatus(422)
            ->assertJsonPath('errors.warehouse_id.0', fn (string $message) => str_contains($message, 'DEC-20260817-001'));
    }

    public function test_the_setting_named_row_is_refused_even_without_the_canonical_code(): void
    {
        $wip = Warehouse::create(['code' => 'FLOOR', 'name' => 'Production Floor', 'is_active' => true]);
        app(AppSettingService::class)->set(ProductionWipLocationResolver::SETTING_KEY, (string) $wip->id);

        $this->receiveInto($wip)->assertStatus(422)->assertJsonValidationErrors(['warehouse_id']);
    }

    public function test_an_ordinary_store_still_receives(): void
    {
        // A WIP row exists, so the guard is live — and does not overreach.
        Warehouse::create(['code' => 'WIP', 'name' => 'Production WIP', 'is_active' => true]);
        $store = Warehouse::create(['code' => 'RM', 'name' => 'RM Store', 'is_active' => true]);

        $this->receiveInto($store)->assertSuccessful();
    }

    public function test_with_no_wip_row_anywhere_nothing_is_refused(): void
    {
        $store = Warehouse::create(['code' => 'RM', 'name' => 'RM Store', 'is_active' => true]);

        $this->receiveInto($store)->assertSuccessful();
    }

    private function receiveInto(Warehouse $warehouse)
    {
        return $this->postJson('/api/v1/procurement/goods-receipts', [
            'purchase_order_id' => $this->order->id,
            'warehouse_id' => $warehouse->id,
            'received_date' => '2026-08-28',
            'lines' => [['purchase_order_line_id' => $this->lineId, 'quantity' => '10', 'unit_cost' => '1']],
        ]);
    }
}
