<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The goods-receipt register's `q` grows two more things a receiving clerk
 * actually types — the ORDER's number and an ITEM — and the register page
 * gets a real server filter for its `?grn=` deep link. What must hold, on
 * top of ProcurementListFiltersTest (number, reference, vendor, sort):
 *
 *   - "PO-12" / "po 12" / "PO12" find every receipt on that order;
 *   - a BARE number is still the receipt's own number and nothing else —
 *     it must not also match every receipt on the order with that id;
 *   - an item's SKU or name finds the receipts carrying it;
 *   - `id` is exactly one receipt and composes with `q`;
 *   - `meta.total` counts the whole filtered register; `per_page` stays
 *     1..1000 (the deep links have always relied on 1000) or 422.
 *
 * Rows are built directly (no stock, no Tally) — these are list tests.
 */
class GoodsReceiptSearchTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $relpet;

    private Vendor $capMasters;

    private Item $resin;

    private Item $cap;

    private Warehouse $rm;

    /** @var array<string, PurchaseOrder> */
    private array $orders = [];

    /** @var array<string, GoodsReceiptNote> */
    private array $receipts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingWith(['procurement.view']);

        $this->relpet = Vendor::create(['code' => 'SUP-RL', 'name' => 'Relpet Resins']);
        $this->capMasters = Vendor::create(['code' => 'SUP-CM', 'name' => 'Cap Masters']);
        $this->resin = Item::create(['sku' => 'RM-PET', 'name' => 'PET Resin', 'uom' => 'Kgs']);
        $this->cap = Item::create(['sku' => 'CAP-28', 'name' => '28mm Cap', 'uom' => 'Nos']);
        $this->rm = Warehouse::create(['code' => 'RM', 'name' => 'RM Store']);

        $this->orders['relpet'] = $this->order($this->relpet, [$this->resin]);
        $this->orders['cm'] = $this->order($this->capMasters, [$this->resin, $this->cap]);

        $this->receipts['r1'] = $this->receipt($this->orders['relpet'], '2026-08-01 09:00:00', 'DC-1', [$this->resin]);
        $this->receipts['r2'] = $this->receipt($this->orders['cm'], '2026-08-02 09:00:00', 'DC-2', [$this->cap]);
        $this->receipts['r3'] = $this->receipt($this->orders['cm'], '2026-08-03 09:00:00', 'DC-3', [$this->resin]);
    }

    public function test_q_finds_every_receipt_on_an_order_when_the_term_is_spelled_as_an_order_number(): void
    {
        $orderId = $this->orders['cm']->id;

        foreach (["PO-{$orderId}", "po {$orderId}", "PO{$orderId}", "PO#{$orderId}", " po-{$orderId} "] as $spelling) {
            $this->assertIds(['r2', 'r3'], $this->receipts, $this->list(['q' => $spelling]), "q={$spelling}");
        }

        $this->assertIds(['r1'], $this->receipts, $this->list(['q' => 'PO-'.$this->orders['relpet']->id]));
    }

    public function test_a_bare_number_is_still_the_receipts_own_number_and_never_also_the_order(): void
    {
        // The case that would blur: a receipt whose own number equals the
        // number of an order that carries OTHER receipts. Built with an
        // explicit shared id, not assumed from insertion order — each table
        // keeps its own auto-increment counter, and on MySQL neither resets
        // between tests, so "both are the second row" holds on sqlite alone
        // (it failed on the MySQL 8 job with 29 !== 27).
        $n = 1 + max((int) PurchaseOrder::max('id'), (int) GoodsReceiptNote::max('id'));

        // No digit anywhere else on these rows: `q` also reads references and
        // item names by substring, and the shared number is whatever the
        // counters happen to be, so "DC-4" or "CAP-28" could match it by
        // accident and hide the very blur this test exists to refuse.
        $blur = $this->order($this->capMasters, [$this->resin], id: $n);
        $fixtures = [
            'onBlurA' => $this->receipt($blur, '2026-08-04 09:00:00', 'DC-BLUR-A', [$this->resin], id: $n + 1),
            'onBlurB' => $this->receipt($blur, '2026-08-05 09:00:00', 'DC-BLUR-B', [$this->resin], id: $n + 2),
            'own' => $this->receipt($this->orders['relpet'], '2026-08-06 09:00:00', 'DC-OWN', [$this->resin], id: $n),
        ];
        $this->assertSame($blur->id, $fixtures['own']->id, 'fixture precondition');

        $this->assertIds(['onBlurA', 'onBlurB'], $fixtures, $this->list(['q' => "PO-{$n}"]), 'PO-{n} is the order and its receipts');
        $this->assertIds(['own'], $fixtures, $this->list(['q' => (string) $n]), 'a bare number is GRN-{n}, not also PO-{n}');
        $this->assertIds(['own'], $fixtures, $this->list(['q' => "GRN-{$n}"]));
    }

    public function test_q_finds_receipts_by_the_items_sku_or_name(): void
    {
        $this->assertIds(['r1', 'r3'], $this->receipts, $this->list(['q' => 'RM-PET']));
        $this->assertIds(['r1', 'r3'], $this->receipts, $this->list(['q' => 'rm-pet']));
        $this->assertIds(['r1', 'r3'], $this->receipts, $this->list(['q' => 'pet resin']));
        $this->assertIds(['r2'], $this->receipts, $this->list(['q' => '28mm']));
        $this->assertIds(['r2'], $this->receipts, $this->list(['q' => 'cap-28']));
        $this->assertIds([], $this->receipts, $this->list(['q' => 'zzz']));
        // The typed % and _ are characters, not wildcards.
        $this->assertIds([], $this->receipts, $this->list(['q' => '%%%']));
        $this->assertIds([], $this->receipts, $this->list(['q' => 'rm_pet']));

        // Composes with the order filter the page's ?po= link sends.
        $this->assertIds(['r3'], $this->receipts, $this->list(['q' => 'RM-PET', 'purchase_order_id' => $this->orders['cm']->id]));
    }

    public function test_id_names_one_receipt_and_the_page_meta_counts_the_whole_register(): void
    {
        $this->assertIds(['r3'], $this->receipts, $this->list(['id' => $this->receipts['r3']->id]));
        $this->assertIds([], $this->receipts, $this->list(['id' => $this->receipts['r3']->id, 'q' => '28mm']), 'id composes with q');
        $this->assertIds([], $this->receipts, $this->list(['id' => 999999]));

        $page = $this->list(['per_page' => 1]);
        $this->assertCount(1, $page->json('data'));
        $this->assertSame(3, $page->json('meta.total'), 'the total is the whole register, not the page');
        $this->assertSame(3, $page->json('meta.last_page'));
        $this->assertSame(1, $page->json('meta.per_page'));

        $this->getJson('/api/v1/procurement/goods-receipts?id=abc')->assertStatus(422)->assertJsonValidationErrors(['id']);
        $this->getJson('/api/v1/procurement/goods-receipts?id=0')->assertStatus(422)->assertJsonValidationErrors(['id']);
        $this->getJson('/api/v1/procurement/goods-receipts?per_page=0')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/v1/procurement/goods-receipts?per_page=1001')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/v1/procurement/goods-receipts?per_page=abc')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
    }

    // ---- helpers ----------------------------------------------------------------

    /**
     * @param  list<Item>  $items
     * @param  int|null  $id  an explicit primary key, for a test that needs two
     *                        documents to share a number — set on the instance,
     *                        since `id` is deliberately not fillable
     */
    private function order(Vendor $vendor, array $items, ?int $id = null): PurchaseOrder
    {
        $order = new PurchaseOrder([
            'vendor_id' => $vendor->id,
            'status' => PurchaseOrderStatus::Sent,
            'order_date' => '2026-07-20',
        ]);
        if ($id !== null) {
            $order->id = $id;
        }
        $order->save();
        foreach ($items as $item) {
            $order->lines()->create(['item_id' => $item->id, 'quantity' => '12000', 'unit_price' => '1.0000', 'quantity_received' => 0]);
        }

        return $order;
    }

    /**
     * @param  list<Item>  $items
     * @param  int|null  $id  see order()
     */
    private function receipt(PurchaseOrder $order, string $receivedAtUtc, string $reference, array $items, ?int $id = null): GoodsReceiptNote
    {
        $grn = new GoodsReceiptNote([
            'purchase_order_id' => $order->id,
            'warehouse_id' => $this->rm->id,
            'reference' => $reference,
            'received_date' => $receivedAtUtc,
        ]);
        if ($id !== null) {
            $grn->id = $id;
        }
        $grn->save();
        foreach ($items as $item) {
            $line = $order->lines()->where('item_id', $item->id)->first();
            $grn->lines()->create(['purchase_order_line_id' => $line->id, 'item_id' => $item->id, 'quantity' => '1000', 'unit_cost' => '1.0000']);
        }

        return $grn;
    }

    /** @param  list<string>  $permissions */
    private function actingWith(array $permissions): User
    {
        $this->app['auth']->forgetGuards();

        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    /** @param  array<string, mixed>  $query */
    private function list(array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/procurement/goods-receipts'.($query === [] ? '' : '?'.http_build_query($query)))->assertOk();
    }

    /**
     * @param  list<string>  $expectedKeys
     * @param  array<string, Model>  $fixtures
     */
    private function assertIds(array $expectedKeys, array $fixtures, TestResponse $response, string $message = ''): void
    {
        $expected = collect($expectedKeys)->map(fn ($key) => $fixtures[$key]->id)->sort()->values()->all();
        $actual = collect($response->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame($expected, $actual, $message);
        $this->assertSame(count($expected), $response->json('meta.total'), $message);
    }
}
