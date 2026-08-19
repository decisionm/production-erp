<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A MALFORMED FIGURE IS A REFUSAL ON EVERY DOOR, NEVER A 500.
 *
 * `numeric` accepts `1e3`, `0x1A`, `INF` and `NAN`; bcmath and the decimal
 * casts accept none of them and throw. Every door that validated with
 * `numeric` alone and then did arithmetic answered a malformed figure with a
 * 500, and the predicate that fixes it drifted FIVE times across this branch
 * before it was written down once as `App\Rules\PlainDecimal`.
 *
 * THIS IS NOT A THEORETICAL SWEEP. `1e+21` is what `JSON.stringify` emits for
 * any JavaScript number at or above 1e21, and the stock screen posts straight
 * from an antd InputNumber — so a storekeeper holding a key down reaches the
 * exponent spelling without typing an `e` at all.
 *
 * The test names the DOORS rather than the rules, so a new door added without
 * the predicate fails here rather than in production.
 */
class EveryQuantityDoorRefusesAMalformedNumberTest extends TestCase
{
    use RefreshDatabase;

    /** Every spelling bcmath rejects, including the one a browser produces. */
    private const MALFORMED = ['1e3', '1e+21', '1e400', '0x1A', 'INF', 'NAN'];

    private Item $item;

    private Warehouse $warehouse;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['is_active' => true]);
        foreach (['inventory.manage', 'production.manage', 'procurement.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        $this->item = Item::create([
            'sku' => 'MD-ITEM', 'name' => 'Malformed Door Item', 'uom' => 'Kgs.',
            'is_active' => true, 'is_production_input' => true,
        ]);
        $this->warehouse = Warehouse::create(['code' => 'MD-WH', 'name' => 'MD Store', 'is_active' => true, 'tally_guid' => 'md-gd']);
        $this->vendor = Vendor::create(['code' => 'MD-V', 'name' => 'MD Supplier', 'is_active' => true]);
    }

    /** @return array<string, array{string, array<string, mixed>, string}> */
    public static function doors(): array
    {
        return [
            'stock receipt · quantity' => ['/api/v1/inventory/stock-movements/receipts', [], 'quantity'],
            'stock receipt · unit_cost' => ['/api/v1/inventory/stock-movements/receipts', [], 'unit_cost'],
            'stock issue · quantity' => ['/api/v1/inventory/stock-movements/issues', [], 'quantity'],
            'stock transfer · quantity' => ['/api/v1/inventory/stock-movements/transfers', [], 'quantity'],
        ];
    }

    #[DataProvider('doors')]
    public function test_a_stock_door_refuses_rather_than_throws(string $url, array $extra, string $field): void
    {
        foreach (self::MALFORMED as $spelling) {
            $payload = array_merge([
                'item_id' => $this->item->id,
                'warehouse_id' => $this->warehouse->id,
                'from_warehouse_id' => $this->warehouse->id,
                'to_warehouse_id' => $this->warehouse->id,
                'quantity' => '10',
                'unit_cost' => '1',
            ], $extra, [$field => $spelling]);

            $this->postJson($url, $payload)
                ->assertStatus(422, "{$url} · {$field} · {$spelling}");
        }
    }

    public function test_the_procurement_doors_refuse_rather_than_throw(): void
    {
        foreach (self::MALFORMED as $spelling) {
            $this->postJson('/api/v1/procurement/purchase-requisitions', [
                'required_by' => '2026-09-01',
                'lines' => [['item_id' => $this->item->id, 'quantity' => $spelling]],
            ])->assertStatus(422, "requisition · {$spelling}");

            foreach (['quantity', 'unit_price'] as $field) {
                $line = ['item_id' => $this->item->id, 'quantity' => '10', 'unit_price' => '5'];
                $line[$field] = $spelling;

                $this->postJson('/api/v1/procurement/purchase-orders', [
                    'vendor_id' => $this->vendor->id,
                    'order_date' => '2026-08-10',
                    'expected_date' => '2026-08-20',
                    'lines' => [$line],
                ])->assertStatus(422, "purchase order · {$field} · {$spelling}");
            }
        }
    }

    /** ...and an ordinary figure still goes through every one of them. */
    public function test_an_ordinary_figure_is_still_accepted(): void
    {
        $this->postJson('/api/v1/inventory/stock-movements/receipts', [
            'item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => '25.5000',
            'unit_cost' => '12.75',
        ])->assertCreated();

        $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $this->vendor->id,
            'order_date' => '2026-08-10',
            'expected_date' => '2026-08-20',
            'lines' => [['item_id' => $this->item->id, 'quantity' => '14.700', 'unit_price' => '5.25']],
        ])->assertCreated();
    }
}
