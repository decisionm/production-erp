<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * NARROWING THE LEDGER BY REFERENCE — `q` on GET /inventory/stock-movements.
 *
 * The ledger is server-paged and filtered on item, warehouse and purpose;
 * the one thing a person arrives holding is a document number — "PO #4",
 * "MR-12" — and until now the only way to its rows was to page. `q` is a
 * substring of `reference`, answered over the whole ledger, so `meta.total`
 * counts the matches and nothing on another page is hidden.
 *
 * Every figure here is synthetic.
 */
class StockMovementReferenceSearchTest extends TestCase
{
    use RefreshDatabase;

    private Item $resin;

    private Item $cap;

    private Warehouse $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Warehouse::create(['code' => 'RS-STORE', 'name' => 'RS Store', 'is_active' => true]);
        $this->resin = Item::create(['sku' => 'RS-RESIN', 'name' => 'RS Resin', 'uom' => 'KGS', 'is_active' => true]);
        $this->cap = Item::create(['sku' => 'RS-CAP', 'name' => 'RS Cap', 'uom' => 'Nos.', 'is_active' => true]);

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('inventory.view', 'web');
        $user->givePermissionTo('inventory.view');
        Sanctum::actingAs($user);

        $stock = app(StockMovementService::class);
        $stock->recordReceipt($this->resin->id, $this->store->id, '100', '10', reference: 'GRN-77 for PO-4');
        $stock->recordReceipt($this->cap->id, $this->store->id, '500', '1', reference: 'GRN-78 for PO-4');
        $stock->recordIssue($this->resin->id, $this->store->id, '25', reference: 'MR-12');
        $stock->recordReceipt($this->resin->id, $this->store->id, '5', '10');
    }

    public function test_q_narrows_the_ledger_to_references_containing_it(): void
    {
        $response = $this->getJson('/api/v1/inventory/stock-movements?q=PO-4')->assertSuccessful();

        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame(
            ['GRN-78 for PO-4', 'GRN-77 for PO-4'],
            collect($response->json('data'))->pluck('reference')->all(),
        );
    }

    public function test_q_narrows_beside_the_item_filter(): void
    {
        $response = $this->getJson('/api/v1/inventory/stock-movements?q=PO-4&item_id='.$this->cap->id)
            ->assertSuccessful();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('GRN-78 for PO-4', $response->json('data.0.reference'));
    }

    public function test_a_row_with_no_reference_never_matches(): void
    {
        $response = $this->getJson('/api/v1/inventory/stock-movements?q=MR')->assertSuccessful();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('MR-12', $response->json('data.0.reference'));
    }

    public function test_an_empty_q_is_the_whole_ledger(): void
    {
        $this->assertSame(4, $this->getJson('/api/v1/inventory/stock-movements?q=')->assertSuccessful()->json('meta.total'));
        $this->assertSame(4, $this->getJson('/api/v1/inventory/stock-movements')->assertSuccessful()->json('meta.total'));
    }

    public function test_no_match_is_a_zero_total(): void
    {
        $response = $this->getJson('/api/v1/inventory/stock-movements?q=nothing-like-this')->assertSuccessful();

        $this->assertSame([], $response->json('data'));
        $this->assertSame(0, $response->json('meta.total'));
    }

    public function test_a_malformed_q_is_refused(): void
    {
        $this->getJson('/api/v1/inventory/stock-movements?q[]=PO-4')
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');

        $this->getJson('/api/v1/inventory/stock-movements?q='.str_repeat('x', 101))
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }
}
