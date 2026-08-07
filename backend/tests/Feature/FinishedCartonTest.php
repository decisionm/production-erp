<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\FinishedCarton;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * CARTON IDENTITIES AND DISPATCH BY SCAN: a completed batch's packed count
 * becomes permanent carton barcodes (full boxes + one labelled partial),
 * idempotent to regenerate, reprintable as a read, traceable back to the
 * batch, and scanned at dispatch so the delivery is built from the boxes
 * that physically left.
 */
class FinishedCartonTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Warehouse $fg;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['is_active' => true]);
        foreach (['production.view', 'production.manage', 'sales.manage', 'sales.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml Bottle', 'uom' => 'Nos']);
        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store']);
    }

    private function completedEntry(string $packed = '2160', ?string $perBox = '600'): ShiftProductionEntry
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'is_active' => true]);

        return ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => '2026-08-02',
            'batch_number' => '20260802-M01-001',
            'status' => ShiftProductionEntryStatus::Pending,
            'batch_status' => BatchStatus::Completed,
            'quantity_produced' => $packed,
            'nos_per_box' => $perBox,
        ]);
    }

    public function test_a_completed_batch_becomes_full_cartons_plus_one_labelled_partial(): void
    {
        $entry = $this->completedEntry('2160', '600');

        $cartons = $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/cartons")
            ->assertSuccessful()
            ->json('data');

        // 2160 @ 600/box = 3 full + 560-piece partial... 2160-1800=360.
        $this->assertCount(4, $cartons);
        $this->assertSame('20260802-M01-001-C01', $cartons[0]['carton_no']);
        $this->assertFalse($cartons[0]['is_partial']);
        $this->assertSame('360.0000', $cartons[3]['pieces']);
        $this->assertTrue($cartons[3]['is_partial']);

        // Idempotent: generating again mints nothing new.
        $again = $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/cartons")
            ->assertSuccessful()->json('data');
        $this->assertCount(4, $again);
        $this->assertSame(4, FinishedCarton::query()->count());
    }

    public function test_a_scanned_carton_traces_back_to_its_batch(): void
    {
        $entry = $this->completedEntry();
        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/cartons")->assertSuccessful();

        $carton = $this->getJson('/api/v1/production/cartons/20260802-M01-001-C02')
            ->assertSuccessful()
            ->json('data');

        $this->assertSame('20260802-M01-001', $carton['batch']['batch_number']);
        $this->assertSame('Machine 1', $carton['batch']['machine']);
        $this->assertSame('in_stock', $carton['status']);

        $this->getJson('/api/v1/production/cartons/NO-SUCH-BOX')->assertStatus(422);
    }

    public function test_dispatch_by_scan_builds_the_delivery_from_the_boxes_that_left(): void
    {
        $entry = $this->completedEntry();
        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/cartons")->assertSuccessful();

        // FG stock backs the delivery issue.
        app(StockMovementService::class)->recordReceipt(
            itemId: $this->bottle->id, warehouseId: $this->fg->id,
            quantity: '2160', unitCost: '2.50', reference: 'seed',
        );

        $customer = Customer::create(['code' => 'CUST-1', 'name' => 'Aqua Traders']);
        $order = SalesOrder::create([
            'customer_id' => $customer->id,
            'status' => SalesOrderStatus::Confirmed,
            'order_date' => '2026-08-02',
        ]);
        $order->lines()->create([
            'item_id' => $this->bottle->id, 'quantity' => '2000',
            'unit_price' => '4.50', 'quantity_delivered' => 0,
        ]);

        // Two full cartons scanned — 1,200 pieces derived, boxes stamped.
        $delivery = $this->postJson('/api/v1/sales/deliveries', [
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->fg->id,
            'carton_codes' => ['20260802-M01-001-C01', '20260802-M01-001-C02'],
        ])->assertSuccessful()->json('data');

        $this->assertSame('1200.0000', (string) $order->lines()->first()->fresh()->quantity_delivered);
        $dispatched = FinishedCarton::query()->where('status', FinishedCarton::STATUS_DISPATCHED)->get();
        $this->assertCount(2, $dispatched);
        $this->assertTrue($dispatched->every(fn ($carton) => $carton->delivery_id === $delivery['id']));

        // The same box cannot leave twice, and a foreign box is named.
        $this->postJson('/api/v1/sales/deliveries', [
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->fg->id,
            'carton_codes' => ['20260802-M01-001-C01'],
        ])->assertStatus(422);
    }

    /**
     * THE ENRICHED LABEL (DEC-20260807-009): net weight = pieces × the run's
     * resolved unit weight — the exact figure the server computes every
     * stored kilogram from (DEC-20260805-005) — plus the run's nos-per-box
     * and the batch spine, on the generate response AND the reprint read.
     * sales_order is null until a real batch→order linkage exists; there is
     * deliberately NO gross weight — no tare exists in the data (Q15).
     */
    public function test_labels_carry_net_weight_from_the_runs_resolved_unit_weight(): void
    {
        $entry = $this->completedEntry('2160', '600');
        $entry->update(['config_snapshot' => ['unit_weight_grams' => '12.5']]);

        $cartons = $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/cartons")
            ->assertSuccessful()->json('data');

        // 600 pieces × 12.5 g = 7.500 kg; the 360-piece partial = 4.500 kg.
        $this->assertSame('7.500', $cartons[0]['net_weight_kg']);
        $this->assertSame('4.500', $cartons[3]['net_weight_kg']);
        $this->assertEquals(600, (float) $cartons[0]['batch']['nos_per_box']);
        $this->assertSame('20260802-M01-001', $cartons[0]['batch']['batch_number']);
        $this->assertSame('Machine 1', $cartons[0]['batch']['machine']);
        $this->assertNull($cartons[0]['sales_order']);
        $this->assertArrayNotHasKey('gross_weight_kg', $cartons[0]);

        // The reprint read says exactly the same things.
        $reprint = $this->getJson("/api/v1/production/shift-production-entries/{$entry->id}/cartons")
            ->assertSuccessful()->json('data');
        $this->assertSame('7.500', $reprint[0]['net_weight_kg']);
        $this->assertSame('20260802-M01-001', $reprint[0]['batch']['batch_number']);
    }

    public function test_a_run_with_no_resolved_weight_prints_no_weight_rather_than_inventing_one(): void
    {
        $entry = $this->completedEntry('2160', '600');

        $cartons = $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/cartons")
            ->assertSuccessful()->json('data');

        // No frozen snapshot weight, no item-master weight: the label carries
        // no weight line at all — a missing figure is never interpolated.
        $this->assertNull($cartons[0]['net_weight_kg']);
    }
}
