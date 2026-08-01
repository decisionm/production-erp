<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\MaterialCostVersionKind;
use App\Modules\Inventory\Models\Enums\MaterialLotRateSource;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialCostVersion;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Events\GoodsReceiptNoteReceived;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use App\Modules\Procurement\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Rate provenance on material lots + the append-only cost-version history.
 *
 * The invariants under test are the owner's, not the framework's:
 *   - a GRN rate is PROVISIONAL, and revising it never rewrites the original;
 *   - opening stock has NO rate, and the system says so instead of guessing;
 *   - a cost version is inert — it moves no stock and re-prices no batch;
 *   - purchase rates are Owner/Accounts data, invisible to the store.
 */
class MaterialLotCostVersionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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

    /** @return array{0: PurchaseOrder, 1: PurchaseOrderLine, 2: Item, 3: Warehouse} */
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

        return [$order, $line, $item, $warehouse];
    }

    /** @return array<string, mixed> */
    private function receiptPayload(
        PurchaseOrder $order,
        PurchaseOrderLine $line,
        Warehouse $warehouse,
        ?string $unitCost = '102.7500',
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
            'receipt_key' => 'receipt-20260802-001',
            'purchase_order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'reference' => 'GRN-COST-001',
            'received_date' => '2026-08-02',
            'lines' => [$lineData],
        ];
    }

    /**
     * Re-run both cost migrations against data that already exists, which is
     * exactly the situation on the live server: lots and GRNs are already
     * there when `php artisan migrate` runs. down() then up(), versions
     * table first so the foreign key never dangles.
     */
    private function rerunCostMigrations(): void
    {
        $rates = require database_path('migrations/2026_08_02_100001_add_receipt_rate_to_material_lots.php');
        $versions = require database_path('migrations/2026_08_02_100002_create_material_cost_versions_table.php');

        $versions->down();
        $rates->down();
        $rates->up();
        $versions->up();
    }

    public function test_backfill_stamps_grn_rates_and_leaves_opening_stock_lots_honestly_null(): void
    {
        $this->actingAsWith(['procurement.manage', 'inventory.view', 'inventory.manage']);
        [$order, $line, $item, $warehouse] = $this->purchase();

        $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload($order, $line, $warehouse))
            ->assertSuccessful();

        $purchased = MaterialLot::query()->sole();

        // An opening-stock lot: real bags, real kilos, no purchase behind it.
        $opening = MaterialLot::create([
            'item_id' => $item->id,
            'supplier_lot_no' => 'OPENING',
            'received_date' => '2026-07-01',
            'bag_count' => 1,
            'bag_weight_kg' => '500',
            'total_received_kg' => '500',
        ]);

        $this->rerunCostMigrations();

        $this->assertSame('102.7500', $purchased->fresh()->receiptRatePerKg());
        $this->assertSame(MaterialLotRateSource::Grn, $purchased->fresh()->rate_source);

        // The point of the whole column: unknown stays unknown. A zero here
        // would tell Sales this resin was free.
        $this->assertNull($opening->fresh()->receiptRatePerKg());
        $this->assertNull($opening->fresh()->rate_source);
        $this->assertNull($opening->fresh()->currentRatePerKg());

        // Backfill opens the history at the original rate, and claims no
        // author — nobody typed this, it arrived with a deploy.
        $version = MaterialCostVersion::query()->where('material_lot_id', $purchased->id)->sole();
        $this->assertSame('102.7500', (string) $version->rate_per_kg);
        $this->assertSame(MaterialCostVersionKind::Receipt, $version->kind);
        $this->assertNull($version->created_by);
        $this->assertNull($version->supersedes_id);

        $this->assertSame(
            0,
            MaterialCostVersion::query()->where('material_lot_id', $opening->id)->count(),
            'A lot with no rate must not get a receipt version inventing one.',
        );
    }

    public function test_backfill_reaches_the_purchase_order_when_the_receipt_line_link_is_gone(): void
    {
        $this->actingAsWith(['procurement.manage', 'inventory.view', 'inventory.manage']);
        [$order, $line, , $warehouse] = $this->purchase(unitPrice: '88.0000');

        // A receipt that named no price of its own: the GRN line took the
        // ordered price, so both routes lead to the same number.
        $this->postJson(
            '/api/v1/procurement/goods-receipts',
            $this->receiptPayload($order, $line, $warehouse, unitCost: null),
        )->assertSuccessful();

        $lot = MaterialLot::query()->sole();

        // The state material_lots.goods_receipt_note_line_id->nullOnDelete()
        // produces for real: the receipt line is gone, so the lot still
        // knows its GRN but nothing on that GRN carries a rate for its item.
        // The purchase order is then the only surviving number.
        DB::table('material_lots')->where('id', $lot->id)->update(['goods_receipt_note_line_id' => null]);
        DB::table('goods_receipt_note_lines')->delete();

        $this->rerunCostMigrations();

        $this->assertSame('88.0000', $lot->fresh()->receiptRatePerKg());
        $this->assertSame(
            MaterialLotRateSource::Po,
            $lot->fresh()->rate_source,
            "'po' means the rate had to be reached from the order because no GRN line rate survived.",
        );
    }

    public function test_a_new_goods_receipt_stamps_the_rate_and_opens_the_receipt_cost_version(): void
    {
        $user = $this->actingAsWith(['procurement.manage', 'inventory.view', 'inventory.manage']);
        [$order, $line, , $warehouse] = $this->purchase();

        $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload($order, $line, $warehouse))
            ->assertSuccessful();

        $lot = MaterialLot::query()->sole();

        $this->assertSame('102.7500', $lot->receiptRatePerKg());
        $this->assertSame(MaterialLotRateSource::Grn, $lot->rate_source);
        $this->assertSame('102.7500', $lot->currentRatePerKg());
        $this->assertFalse($lot->hasRevisions());

        $version = MaterialCostVersion::query()->sole();
        $this->assertSame($lot->id, $version->material_lot_id);
        $this->assertSame('102.7500', (string) $version->rate_per_kg);
        $this->assertSame(MaterialCostVersionKind::Receipt, $version->kind);
        // A live receipt DOES have an author — unlike the backfill.
        $this->assertSame($user->id, $version->created_by);
        $this->assertNull($version->supersedes_id);
    }

    public function test_a_receipt_that_names_no_price_records_that_it_defaulted_from_the_order(): void
    {
        $this->actingAsWith(['procurement.manage', 'inventory.view', 'inventory.manage']);
        [$order, $line, , $warehouse] = $this->purchase(unitPrice: '77.2500');

        $this->postJson(
            '/api/v1/procurement/goods-receipts',
            $this->receiptPayload($order, $line, $warehouse, unitCost: null),
        )->assertSuccessful();

        $lot = MaterialLot::query()->sole();

        $this->assertSame('77.2500', $lot->receiptRatePerKg());
        // The number physically came off the GRN line either way, so the
        // column says 'grn' — the same thing the backfill would say for an
        // identical row. The nuance lives in the version's note.
        $this->assertSame(MaterialLotRateSource::Grn, $lot->rate_source);
        $this->assertStringContainsString(
            'defaulted from the purchase order',
            (string) MaterialCostVersion::query()->sole()->note,
        );
    }

    public function test_appending_a_version_preserves_the_original_rate_and_moves_the_current_one(): void
    {
        $this->actingAsWith(['procurement.manage', 'inventory.view', 'inventory.manage']);
        [$order, $line, , $warehouse] = $this->purchase();
        $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload($order, $line, $warehouse))
            ->assertSuccessful();

        $lot = MaterialLot::query()->sole();
        $receiptVersionId = MaterialCostVersion::query()->sole()->id;
        $rawReceiptRate = DB::table('material_lots')->where('id', $lot->id)->value('receipt_rate_per_kg');

        $finance = $this->actingAsWith(['finance.manage']);

        $invoice = $this->postJson("/api/v1/inventory/material-lots/{$lot->id}/cost-versions", [
            'rate_per_kg' => '105.4000',
            'kind' => 'invoice',
            'note' => 'Supplier invoice INV-4471 priced this lot above the receipt rate.',
        ])->assertCreated()
            ->assertJsonPath('data.kind', 'invoice')
            ->assertJsonPath('data.rate_per_kg', '105.4000')
            ->assertJsonPath('data.supersedes_id', $receiptVersionId)
            ->assertJsonPath('data.created_by.id', $finance->id);

        $this->assertStringContainsString(
            'does not change stock balances',
            (string) $invoice->json('note'),
            'Every response must say plainly that a cost version moves nothing.',
        );

        $this->postJson("/api/v1/inventory/material-lots/{$lot->id}/cost-versions", [
            'rate_per_kg' => '107.9000',
            'kind' => 'landed_cost',
            'note' => 'Freight and unloading allocated across the lot.',
        ])->assertCreated()->assertJsonPath('data.supersedes_id', $invoice->json('data.id'));

        $lot->refresh();

        // THE RULE: the original receipt rate never moves, in the column or
        // in its version row — compared raw, straight out of the database.
        $this->assertSame(
            $rawReceiptRate,
            DB::table('material_lots')->where('id', $lot->id)->value('receipt_rate_per_kg'),
        );
        $this->assertSame('102.7500', $lot->receiptRatePerKg());
        $this->assertSame('102.7500', (string) MaterialCostVersion::query()->find($receiptVersionId)->rate_per_kg);

        // …while the current rate follows the newest version.
        $this->assertSame('107.9000', $lot->currentRatePerKg());
        $this->assertTrue($lot->hasRevisions());
        $this->assertSame(3, MaterialCostVersion::query()->count());

        $this->getJson("/api/v1/inventory/material-lots/{$lot->id}/cost-versions")
            ->assertSuccessful()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.kind', 'receipt')
            ->assertJsonPath('data.2.kind', 'landed_cost');
    }

    /**
     * Every table a cost version must not disturb: the valuation ledger the
     * Accounts team approved, the physical bags, and — because the owner's
     * rule names it — the batch-consumption record. A closed batch is never
     * silently re-costed because a freight bill turned up.
     *
     * @return array<string, array<int, object>>
     */
    private function physicalWorld(): array
    {
        $tables = [
            'stock_balances', 'stock_movements', 'material_bags', 'material_lots',
            'day_bin_movements', 'shift_material_consumptions',
        ];

        $world = [];

        foreach ($tables as $table) {
            $world[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }

        return $world;
    }

    public function test_a_cost_version_moves_no_stock_whatsoever(): void
    {
        $this->actingAsWith(['procurement.manage', 'inventory.view', 'inventory.manage']);
        [$order, $line, , $warehouse] = $this->purchase();
        $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload($order, $line, $warehouse))
            ->assertSuccessful();

        $lot = MaterialLot::query()->sole();

        $before = $this->physicalWorld();

        $this->actingAsWith(['finance.manage']);
        $this->postJson("/api/v1/inventory/material-lots/{$lot->id}/cost-versions", [
            'rate_per_kg' => '131.0000',
            'kind' => 'correction',
            'note' => 'Rate was keyed against the wrong supplier lot.',
        ])->assertCreated();

        $this->assertEquals(
            $before,
            $this->physicalWorld(),
            'A cost version is a recorded fact. It must leave the physical world byte-identical.',
        );
    }

    public function test_the_store_never_sees_a_purchase_rate_on_a_lot(): void
    {
        $this->actingAsWith(['procurement.manage', 'inventory.view', 'inventory.manage']);
        [$order, $line, , $warehouse] = $this->purchase();
        $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload($order, $line, $warehouse))
            ->assertSuccessful();

        $lot = MaterialLot::query()->sole();

        // A store user, in a database where the finance permissions do not
        // even exist as rows. The lot must render exactly as it always did.
        $this->actingAsWith(['inventory.view']);

        $payload = $this->getJson("/api/v1/inventory/material-lots/{$lot->id}")
            ->assertSuccessful()
            ->json('data');

        foreach (['receipt_rate_per_kg', 'rate_source', 'current_rate_per_kg', 'has_revisions'] as $key) {
            // MISSING, not null: null is a real answer here (opening stock),
            // and a nulled field would read as "this resin cost nothing".
            $this->assertArrayNotHasKey($key, $payload);
        }

        // Everything the store actually needs is still there.
        $this->assertSame('SUP-LOT-88', $payload['supplier_lot_no']);
        $this->assertCount(10, $payload['bags']);

        $this->getJson('/api/v1/inventory/material-lots')
            ->assertSuccessful()
            ->assertJsonMissingPath('data.0.receipt_rate_per_kg')
            ->assertJsonMissingPath('data.0.current_rate_per_kg');
    }

    public function test_a_finance_user_sees_the_rate_and_its_provenance_on_the_lot(): void
    {
        $this->actingAsWith(['procurement.manage', 'inventory.view', 'inventory.manage']);
        [$order, $line, , $warehouse] = $this->purchase();
        $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload($order, $line, $warehouse))
            ->assertSuccessful();

        $lot = MaterialLot::query()->sole();

        // finance.view alone is enough to read; inventory.view lets the same
        // person reach the lot endpoint at all.
        $this->actingAsWith(['inventory.view', 'finance.view']);

        $this->getJson("/api/v1/inventory/material-lots/{$lot->id}")
            ->assertSuccessful()
            ->assertJsonPath('data.receipt_rate_per_kg', '102.7500')
            ->assertJsonPath('data.rate_source', 'grn')
            ->assertJsonPath('data.current_rate_per_kg', '102.7500')
            ->assertJsonPath('data.has_revisions', false);
    }

    public function test_cost_version_endpoints_are_closed_to_inventory_only_users(): void
    {
        $this->actingAsWith(['procurement.manage', 'inventory.view', 'inventory.manage']);
        [$order, $line, , $warehouse] = $this->purchase();
        $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload($order, $line, $warehouse))
            ->assertSuccessful();

        $lot = MaterialLot::query()->sole();

        // Full run of the store's own module — and still no business with
        // supplier rates.
        $this->actingAsWith(['inventory.view', 'inventory.manage']);

        $this->getJson("/api/v1/inventory/material-lots/{$lot->id}/cost-versions")->assertForbidden();
        $this->postJson("/api/v1/inventory/material-lots/{$lot->id}/cost-versions", [
            'rate_per_kg' => '999.0000',
            'kind' => 'correction',
            'note' => 'The store should not be able to reprice a purchase.',
        ])->assertForbidden();

        $this->assertSame(1, MaterialCostVersion::query()->count());

        // finance.view reads but cannot write: module middleware requires
        // manage for any verb that is not a read.
        $this->actingAsWith(['finance.view']);
        $this->getJson("/api/v1/inventory/material-lots/{$lot->id}/cost-versions")->assertSuccessful();
        $this->postJson("/api/v1/inventory/material-lots/{$lot->id}/cost-versions", [
            'rate_per_kg' => '999.0000',
            'kind' => 'correction',
            'note' => 'Read access is not write access.',
        ])->assertForbidden();

        $this->assertSame(1, MaterialCostVersion::query()->count());
    }

    public function test_a_version_needs_a_real_reason_and_cannot_forge_a_second_original(): void
    {
        $this->actingAsWith(['procurement.manage', 'inventory.view', 'inventory.manage']);
        [$order, $line, , $warehouse] = $this->purchase();
        $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload($order, $line, $warehouse))
            ->assertSuccessful();

        $lot = MaterialLot::query()->sole();
        $this->actingAsWith(['finance.manage']);
        $url = "/api/v1/inventory/material-lots/{$lot->id}/cost-versions";

        $this->postJson($url, ['rate_per_kg' => '105.0000', 'kind' => 'invoice'])
            ->assertJsonValidationErrors('note');

        $this->postJson($url, ['rate_per_kg' => '105.0000', 'kind' => 'invoice', 'note' => 'ok'])
            ->assertJsonValidationErrors('note');

        // 'receipt' is the system's word, not a person's — a second
        // "original" would destroy the one rate nothing may move.
        $this->postJson($url, [
            'rate_per_kg' => '105.0000',
            'kind' => 'receipt',
            'note' => 'Trying to file a second original receipt rate.',
        ])->assertJsonValidationErrors('kind');

        $this->postJson($url, [
            'rate_per_kg' => '0',
            'kind' => 'invoice',
            'note' => 'A free lot of resin does not exist.',
        ])->assertJsonValidationErrors('rate_per_kg');

        $this->assertSame(1, MaterialCostVersion::query()->count());
    }

    public function test_the_history_is_append_only_with_no_edit_or_delete_route(): void
    {
        $this->actingAsWith(['procurement.manage', 'inventory.view', 'inventory.manage']);
        [$order, $line, , $warehouse] = $this->purchase();
        $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload($order, $line, $warehouse))
            ->assertSuccessful();

        $lot = MaterialLot::query()->sole();
        $version = MaterialCostVersion::query()->sole();
        $this->actingAsWith(['finance.manage']);

        $base = "/api/v1/inventory/material-lots/{$lot->id}/cost-versions";

        // The collection URL exists for GET and POST only — every mutating
        // verb is refused by the router (405), never dispatched.
        $this->putJson($base, ['rate_per_kg' => '1.0000'])->assertMethodNotAllowed();
        $this->patchJson($base, ['rate_per_kg' => '1.0000'])->assertMethodNotAllowed();
        $this->deleteJson($base)->assertMethodNotAllowed();

        // And a per-version URL does not exist at all: there is nowhere to
        // address an individual row to change it.
        $item = "{$base}/{$version->id}";
        $this->putJson($item, ['rate_per_kg' => '1.0000'])->assertNotFound();
        $this->patchJson($item, ['rate_per_kg' => '1.0000'])->assertNotFound();
        $this->deleteJson($item)->assertNotFound();

        $this->assertSame('102.7500', (string) $version->fresh()->rate_per_kg);
    }
}
