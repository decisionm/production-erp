<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Events\GoodsReceiptNoteReceived;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use App\Modules\Procurement\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * FC-06 on the two Procurement payloads: purchase rates are Owner/Accounts
 * data, gated on finance.view/finance.manage and OMITTED (never nulled) for
 * everyone else — exactly the MaterialLotResource rule.
 *
 * The user under test is PROCUREMENT-ONLY: procurement.view + procurement.manage
 * and no finance permission. `procurement` and `finance` are independent
 * catalog entries, so this is a real role shape, and it was the one no test
 * covered while both line resources served the rate unconditionally.
 */
class ProcurementRateVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const RATE_KEYS = ['unit_price', 'unit_cost'];

    protected function setUp(): void
    {
        parent::setUp();

        // Traceability on so a lot nests under the GRN line: the payload then
        // carries the gated child inside the (previously ungated) parent, and
        // the walk below has to clear both.
        config(['production.traceability_enabled' => true]);
        Event::fake([GoodsReceiptNoteReceived::class]);
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

    /** @return array{0: PurchaseOrder, 1: PurchaseOrderLine, 2: Warehouse} */
    private function purchase(string $unitPrice = '96.5000'): array
    {
        $item = Item::create(['sku' => 'RM-PET', 'name' => 'PET Resin', 'uom' => 'Kgs']);
        $warehouse = Warehouse::create(['code' => 'RM-STORE', 'name' => 'RM Store']);
        $vendor = Vendor::create(['code' => 'SUP-1', 'name' => 'Resin Supplier']);
        $order = PurchaseOrder::create([
            'vendor_id' => $vendor->id,
            'status' => PurchaseOrderStatus::Sent,
            'order_date' => '2026-08-02',
        ]);
        $line = $order->lines()->create([
            'item_id' => $item->id,
            'quantity' => '12000',
            'unit_price' => $unitPrice,
            'quantity_received' => '0',
        ]);

        return [$order, $line, $warehouse];
    }

    /** @return array<string, mixed> */
    private function receiptPayload(
        PurchaseOrder $order,
        PurchaseOrderLine $line,
        Warehouse $warehouse,
        ?string $unitCost = null,
    ): array {
        $lineData = [
            'purchase_order_line_id' => $line->id,
            'quantity' => '12000',
            'lots' => [[
                'supplier_lot_no' => 'SUP-LOT-88',
                'bag_count' => 10,
                'bag_weight_kg' => '1200',
            ]],
        ];

        if ($unitCost !== null) {
            $lineData['unit_cost'] = $unitCost;
        }

        return [
            'receipt_key' => 'receipt-20260816-001',
            'purchase_order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'reference' => 'GRN-RATE-001',
            'received_date' => '2026-08-16',
            'lines' => [$lineData],
        ];
    }

    /**
     * Every key path in the payload, at any depth, whose name is one of the
     * rate keys. Written as a walk (not assertJsonMissingPath on a handful of
     * guessed paths) so a rate that reappears anywhere — a new nested block,
     * a renamed relation — fails this test instead of slipping past it.
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

    public function test_a_procurement_only_user_never_sees_a_rate_on_orders_or_receipts(): void
    {
        $this->actingAsWith(['procurement.view', 'procurement.manage']);
        [$order, $line, $warehouse] = $this->purchase();

        // The receipt is created by the same procurement-only user; the store
        // response is a full resource too, so it is checked as well.
        $created = $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload($order, $line, $warehouse))
            ->assertSuccessful()
            ->json();
        $this->assertSame([], $this->rateKeyPaths($created), 'GRN store response leaked a rate key');

        $orders = $this->getJson('/api/v1/procurement/purchase-orders')
            ->assertSuccessful()
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.lines.0.quantity', '12000.0000')
            ->json();
        $this->assertSame([], $this->rateKeyPaths($orders), 'purchase-orders leaked a rate key');

        $receipts = $this->getJson('/api/v1/procurement/goods-receipts')
            ->assertSuccessful()
            ->assertJsonPath('data.0.lines.0.quantity', '12000.0000')
            ->assertJsonPath('data.0.lines.0.material_lots.0.supplier_lot_no', 'SUP-LOT-88')
            ->json();
        $this->assertSame([], $this->rateKeyPaths($receipts), 'goods-receipts leaked a rate key');

        // ABSENT, not null — belt and braces on the exact paths too. A nulled
        // rate would read as "this resin cost nothing".
        $this->assertArrayNotHasKey('unit_price', $orders['data'][0]['lines'][0]);
        $this->assertArrayNotHasKey('unit_cost', $receipts['data'][0]['lines'][0]);
    }

    public function test_a_finance_user_sees_the_rate_on_orders_and_receipts(): void
    {
        $this->actingAsWith(['procurement.view', 'procurement.manage']);
        [$order, $line, $warehouse] = $this->purchase();
        $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload($order, $line, $warehouse, '102.7500'))
            ->assertSuccessful();

        // finance.view alone is the gate; procurement.view lets the same
        // person reach the endpoints at all.
        $this->actingAsWith(['procurement.view', 'finance.view']);

        $this->getJson('/api/v1/procurement/purchase-orders')
            ->assertSuccessful()
            ->assertJsonPath('data.0.lines.0.unit_price', '96.5000');

        $this->getJson('/api/v1/procurement/goods-receipts')
            ->assertSuccessful()
            ->assertJsonPath('data.0.lines.0.unit_cost', '102.7500')
            // The nested lot agrees with its parent line — the same number,
            // visible to the same eyes.
            ->assertJsonPath('data.0.lines.0.material_lots.0.receipt_rate_per_kg', '102.7500');
    }

    public function test_a_procurement_only_user_can_still_receive_and_the_server_defaults_the_rate_from_the_order(): void
    {
        $this->actingAsWith(['procurement.view', 'procurement.manage']);
        [$order, $line, $warehouse] = $this->purchase('96.5000');

        // No unit_cost in the payload: the frontend does not send one when
        // the PO rate was never shown to this user (GoodsReceiptsPage), and
        // the server defaults it from the PO line (GoodsReceiptService).
        $payload = $this->receiptPayload($order, $line, $warehouse);
        $this->assertArrayNotHasKey('unit_cost', $payload['lines'][0]);

        $this->postJson('/api/v1/procurement/goods-receipts', $payload)
            ->assertSuccessful()
            ->assertJsonMissingPath('data.lines.0.unit_cost');

        // Read from the database, not the response — the response rightly
        // hides the number from the user who just caused it to be recorded.
        $grnLine = GoodsReceiptNoteLine::query()->sole();
        $this->assertSame('96.5000', $grnLine->unit_cost);
        $this->assertSame($line->id, $grnLine->purchase_order_line_id);
    }
}
