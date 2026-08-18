<?php

namespace Tests\Feature\Acceptance;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\MeasurementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Production\Services\FactoryWarehouseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE FACTORY DOES NOT WORK IN KILOGRAMS.
 *
 * The 12-Aug Tally evidence carries three unit strings across 43 stock items —
 * `Kgs.`, `Nos.`, `Pcs.` — and **26 of those items are COUNTED, not weighed**:
 * trays, master boxes, caps, measuring cups, tape. Treating "material" and
 * "kilogram" as the same idea is wrong for most of the catalogue.
 *
 * So this walks the SAME chain twice, once per kind, each in its own unit:
 *
 *   PO -> GRN -> RM Store -> Material Request -> Store Issue -> Production/WIP
 *
 *   · WEIGHT — `Kgs.`, decimal, modelled on Relpet (27 PO lines in the evidence)
 *   · COUNT  — `Nos.`, whole, modelled on 60 Ml Tray (33 lines, the highest-
 *     volume counted master, and never once fractional)
 *
 * Deliberately NOT tape: FC-03 and DEC-20260807-005 keep tape display-only
 * until its posting is separately scoped, so using it would exercise a path the
 * constitution forbids.
 *
 * The units are spelled exactly as Tally writes them, trailing dot and all,
 * because `items.uom` carries Tally's BASEUNITS verbatim.
 */
class TwoUnitsChainTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $store;

    private Warehouse $wip;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.traceability_enabled' => true]);

        $user = User::factory()->create(['is_active' => true]);
        foreach (['procurement.manage', 'inventory.manage', 'production.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        $this->store = Warehouse::create(['code' => 'TU-RM', 'name' => 'TU Raw Material Store', 'is_active' => true, 'tally_guid' => 'tu-gd']);
        $this->wip = Warehouse::create(['code' => 'TU-WIP', 'name' => 'TU Production WIP', 'is_active' => false]);

        app(ProductionWipLocationResolver::class)->setWarehouseId($this->wip->id);
        app(FactoryWarehouseResolver::class)->setRawMaterialWarehouseId($this->store->id);

        $this->vendor = Vendor::create(['code' => 'TU-V1', 'name' => 'TU Test Supplier', 'is_active' => true]);
    }

    public function test_the_classifier_reads_tallys_own_spellings(): void
    {
        // Verbatim, as Tally writes them into items.uom.
        $this->assertSame(MeasurementType::Weight, MeasurementType::forUom('Kgs.'));
        $this->assertSame(MeasurementType::Count, MeasurementType::forUom('Nos.'));
        $this->assertSame(MeasurementType::Count, MeasurementType::forUom('Pcs.'));

        // An unclassified unit stays UNKNOWN. It must never quietly become
        // weight — defaulting the unknown to kilograms is the whole assumption
        // this classifier exists to remove.
        $this->assertSame(MeasurementType::Unknown, MeasurementType::forUom('Roll'));
        $this->assertSame(MeasurementType::Unknown, MeasurementType::forUom(null));
        $this->assertTrue(MeasurementType::Unknown->permitsFractions(), 'refusing decimals on an unclassified unit would block real work on a guess');
    }

    /** A WEIGHT material, in kilograms, carrying decimals. */
    public function test_a_weight_material_walks_the_chain_in_kilograms(): void
    {
        $resin = $this->material('RM-RELPET', 'Relpet PET Resin', 'Kgs.');

        $this->receive($resin, '100', 'tu-kg');
        $this->assertSame(0, bccomp($this->balance($resin, $this->store), '100', 4));

        // A DECIMAL quantity — the evidence proves kg genuinely arrives and
        // moves in fractions (packing film at 14.700 kg).
        $this->requestAndIssue($resin, '37.5000');

        $this->assertSame(0, bccomp($this->balance($resin, $this->store), '62.5', 4));
        $this->assertSame(0, bccomp($this->balance($resin, $this->wip), '37.5', 4));

        $row = $this->floorRowFor($resin);
        $this->assertSame('Kgs.', $row['uom'], 'the floor panel reports the item OWN unit');
        $this->assertSame(0, bccomp((string) $row['quantity'], '37.5', 4));
    }

    /** A COUNTED material, in Nos., whole numbers only. */
    public function test_a_counted_material_walks_the_same_chain_in_nos(): void
    {
        $tray = $this->material('PKG-TRAY-60', '60 Ml Tray', 'Nos.');

        $this->receive($tray, '5000', 'tu-nos');
        $this->assertSame(0, bccomp($this->balance($tray, $this->store), '5000', 4));

        $this->requestAndIssue($tray, '1800');

        $this->assertSame(0, bccomp($this->balance($tray, $this->store), '3200', 4));
        $this->assertSame(0, bccomp($this->balance($tray, $this->wip), '1800', 4));

        $row = $this->floorRowFor($tray);
        $this->assertSame('Nos.', $row['uom'], 'a counted material is NOT reported in kilograms');
        $this->assertSame(0, bccomp((string) $row['quantity'], '1800', 4));
    }

    /** Half a tray is not a thing. */
    public function test_a_counted_material_refuses_a_fractional_request_and_issue(): void
    {
        $tray = $this->material('PKG-TRAY-60', '60 Ml Tray', 'Nos.');
        $this->receive($tray, '5000', 'tu-frac');

        $this->postJson('/api/v1/inventory/material-requests', [
            'lines' => [['item_id' => $tray->id, 'quantity' => '12.5']],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.quantity');

        $this->postJson('/api/v1/inventory/store-issues', [
            'lines' => [['item_id' => $tray->id, 'quantity' => '12.5']],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.quantity');
    }

    /** ...but a weight material keeps its decimals. */
    public function test_a_weight_material_still_accepts_a_fractional_quantity(): void
    {
        $resin = $this->material('RM-RELPET', 'Relpet PET Resin', 'Kgs.');
        $this->receive($resin, '100', 'tu-frac2');

        $this->postJson('/api/v1/inventory/material-requests', [
            'lines' => [['item_id' => $resin->id, 'quantity' => '14.7']],
        ])->assertCreated();
    }

    /** The caller does not get to rename the unit. */
    public function test_an_issue_may_not_name_a_unit_the_item_does_not_use(): void
    {
        $tray = $this->material('PKG-TRAY-60', '60 Ml Tray', 'Nos.');
        $this->receive($tray, '5000', 'tu-uom');

        $this->postJson('/api/v1/inventory/store-issues', [
            'lines' => [['item_id' => $tray->id, 'quantity' => '100', 'uom' => 'Kgs.']],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.uom');
    }

    /* ------------------------------ helpers ------------------------------ */

    private function material(string $sku, string $name, string $uom): Item
    {
        return Item::create([
            'sku' => $sku, 'name' => $name, 'uom' => $uom,
            'is_active' => true, 'is_production_input' => true,
        ]);
    }

    /** PO -> send -> GRN. No lots: bag capture is weight-only by design. */
    private function receive(Item $item, string $quantity, string $key): void
    {
        $orderId = $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $this->vendor->id,
            'order_date' => '2026-08-10',
            'expected_date' => '2026-08-20',
            'lines' => [['item_id' => $item->id, 'quantity' => $quantity, 'unit_price' => '1.00']],
        ])->assertCreated()->json('data.id');

        $lineId = $this->getJson("/api/v1/procurement/purchase-orders/{$orderId}")->assertOk()->json('data.lines.0.id');
        $this->postJson("/api/v1/procurement/purchase-orders/{$orderId}/send")->assertOk();

        $this->postJson('/api/v1/procurement/goods-receipts', [
            'receipt_key' => $key,
            'purchase_order_id' => $orderId,
            'warehouse_id' => $this->store->id,
            'reference' => 'TU-DC',
            'received_date' => '2026-08-18',
            'lines' => [['purchase_order_line_id' => $lineId, 'quantity' => $quantity]],
        ])->assertCreated();
    }

    private function requestAndIssue(Item $item, string $quantity): void
    {
        $request = $this->postJson('/api/v1/inventory/material-requests', [
            'lines' => [['item_id' => $item->id, 'quantity' => $quantity]],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/inventory/material-requests/{$request['id']}/submit")->assertOk();

        $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $request['id'],
            'lines' => [[
                'material_request_line_id' => $request['lines'][0]['id'],
                'item_id' => $item->id,
                'quantity' => $quantity,
            ]],
        ])->assertCreated();
    }

    private function floorRowFor(Item $item): array
    {
        $rows = $this->getJson('/api/v1/inventory/production-floor-stock')->assertOk()->json('data');

        return collect($rows)->firstWhere('item_id', $item->id) ?? [];
    }

    private function balance(Item $item, Warehouse $warehouse): string
    {
        return bcadd((string) (StockBalance::query()
            ->where('item_id', $item->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('quantity') ?? '0'), '0', 4);
    }
}
