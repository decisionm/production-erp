<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
use App\Modules\Inventory\Models\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\QualityHoldLocationResolver;
use App\Modules\Inventory\Services\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * DAMAGED FINISHED GOODS → QUALITY → SCRAP (DEC-20260901-006), walked whole.
 *
 * The owner's rule has two halves and this file is about the join between
 * them: a damaged finished good BECOMES SCRAP (DEC-20260901-002), and it gets
 * there THROUGH QUALITY, never straight out of stock (DEC-20260901-003's
 * shape, now applied to finished goods).
 *
 * THE SEPARATION IS THE POINT. The Store reports; only Quality scraps. So the
 * two acts are walked as two different logins, and the test that matters most
 * is the one asserting the Store cannot do Quality's half — a single login
 * doing both would satisfy every other assertion here while destroying the
 * rule.
 *
 * WHAT IS REUSED, DELIBERATELY. Only the door in is new. Once the goods are
 * in quality hold they are disposed of by the same endpoints that already
 * serve damaged returned material, and DamagedReturnReachesQualityTest pins
 * that behaviour. What this file adds is that finished goods reach that door
 * at all, that they leave sellable stock the moment they are reported, and
 * that the door refuses anything that is not a finished good.
 *
 * NO TALLY VOUCHER is posted or staged by any of this, and none is asked for:
 * what Tally should receive for a scrapped finished good is expressly
 * undecided (DEC-20260901-006).
 */
class DamagedFinishedGoodsReachScrapTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Item $resin;

    private Warehouse $fgStore;

    private Warehouse $qualityHold;

    private User $storeKeeper;

    private User $qualityUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fgStore = Warehouse::create(['code' => 'FG-STORE', 'name' => 'Finished Goods Store', 'is_active' => true]);
        $this->qualityHold = Warehouse::create(['code' => 'QC-HOLD', 'name' => 'Quality Hold', 'is_active' => true]);

        $this->bottle = Item::create([
            'sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos',
            'category' => ItemCategory::FinishedGood,
        ]);
        $this->resin = Item::create([
            'sku' => 'PET-RESIN', 'name' => 'PET Resin', 'uom' => 'Kgs.',
            'category' => ItemCategory::RawMaterial, 'is_production_input' => true,
        ]);

        app(QualityHoldLocationResolver::class)->setWarehouseId($this->qualityHold->id);

        foreach ([$this->bottle, $this->resin] as $item) {
            app(StockMovementService::class)->recordReceipt(
                itemId: $item->id,
                warehouseId: $this->fgStore->id,
                quantity: '100',
                unitCost: '2.50',
                reference: 'Opening',
                purpose: StockMovementPurpose::Opening,
            );
        }

        $this->storeKeeper = $this->userWith(['inventory.manage']);
        $this->qualityUser = $this->userWith(['quality.manage']);
    }

    // ── The whole loop ──────────────────────────────────────────────────────

    public function test_the_store_reports_damage_quality_confirms_it_and_the_goods_leave_stock(): void
    {
        Sanctum::actingAs($this->storeKeeper);

        $this->postJson('/api/v1/inventory/damaged-finished-goods', [
            'from_warehouse_id' => $this->fgStore->id,
            'notes' => 'Pallet crushed by the forklift',
            'lines' => [['item_id' => $this->bottle->id, 'quantity' => '12']],
        ])->assertCreated();

        // OUT OF SELLABLE STOCK THE MOMENT IT IS REPORTED — by construction,
        // because balances are per item AND warehouse. Nothing has to filter.
        $this->assertSame('88.0000', $this->balance($this->bottle, $this->fgStore));
        $this->assertSame('12.0000', $this->balance($this->bottle, $this->qualityHold));

        // BUT IT HAS NOT LEFT STOCK. Reporting is not scrapping.
        $this->assertSame(0, StockMovement::query()
            ->where('purpose', StockMovementPurpose::Scrap)->count());

        // Quality sees it on the SAME board that serves damaged returns.
        Sanctum::actingAs($this->qualityUser);
        $waiting = $this->getJson('/api/v1/quality/returned-material-holds')->assertOk()->json('data');
        $this->assertCount(1, $waiting);
        $this->assertSame($this->bottle->id, $waiting[0]['item_id']);
        $this->assertSame('12.0000', $waiting[0]['quantity']);
        $this->assertSame('Nos', $waiting[0]['uom']);

        $this->postJson('/api/v1/quality/returned-material-holds/confirm-damage', [
            'notes' => 'Necks cracked',
            'lines' => [['item_id' => $this->bottle->id, 'quantity' => '12']],
        ])->assertCreated();

        // NOW it is gone: an issue out, purpose scrap. Not moved somewhere
        // else, and nothing booked inward against a Scrap master — which is
        // why DEC-20260901-002's "which scrap item" boundary never had to be
        // answered for this flow.
        $this->assertSame('0.0000', $this->balance($this->bottle, $this->qualityHold));
        $this->assertSame('88.0000', $this->balance($this->bottle, $this->fgStore));

        $scrap = StockMovement::query()->where('purpose', StockMovementPurpose::Scrap)->sole();
        $this->assertSame(StockMovementType::Issue, $scrap->type);
        $this->assertSame('12.0000', (string) $scrap->quantity);
        $this->assertSame($this->qualityHold->id, $scrap->warehouse_id);
    }

    public function test_quality_can_release_finished_goods_that_turn_out_to_be_fine(): void
    {
        Sanctum::actingAs($this->storeKeeper);
        $this->postJson('/api/v1/inventory/damaged-finished-goods', [
            'from_warehouse_id' => $this->fgStore->id,
            'lines' => [['item_id' => $this->bottle->id, 'quantity' => '10']],
        ])->assertCreated();

        Sanctum::actingAs($this->qualityUser);
        $this->postJson('/api/v1/quality/returned-material-holds/release', [
            'to_warehouse_id' => $this->fgStore->id,
            'lines' => [['item_id' => $this->bottle->id, 'quantity' => '10']],
        ])->assertCreated();

        $this->assertSame('100.0000', $this->balance($this->bottle, $this->fgStore));
        $this->assertSame('0.0000', $this->balance($this->bottle, $this->qualityHold));
    }

    // ── The separation, which is the rule ───────────────────────────────────

    public function test_the_store_cannot_scrap_what_it_reported(): void
    {
        Sanctum::actingAs($this->storeKeeper);
        $this->postJson('/api/v1/inventory/damaged-finished-goods', [
            'from_warehouse_id' => $this->fgStore->id,
            'lines' => [['item_id' => $this->bottle->id, 'quantity' => '12']],
        ])->assertCreated();

        // Still the store's login: confirming the damage is Quality's act.
        $this->postJson('/api/v1/quality/returned-material-holds/confirm-damage', [
            'lines' => [['item_id' => $this->bottle->id, 'quantity' => '12']],
        ])->assertForbidden();

        $this->assertSame('12.0000', $this->balance($this->bottle, $this->qualityHold));
        $this->assertSame(0, StockMovement::query()->where('purpose', StockMovementPurpose::Scrap)->count());
    }

    public function test_quality_cannot_report_the_damage_itself(): void
    {
        Sanctum::actingAs($this->qualityUser);

        $this->postJson('/api/v1/inventory/damaged-finished-goods', [
            'from_warehouse_id' => $this->fgStore->id,
            'lines' => [['item_id' => $this->bottle->id, 'quantity' => '12']],
        ])->assertForbidden();
    }

    // ── The door is finished goods only ─────────────────────────────────────

    public function test_raw_material_is_refused_and_pointed_at_the_production_return(): void
    {
        Sanctum::actingAs($this->storeKeeper);

        $this->postJson('/api/v1/inventory/damaged-finished-goods', [
            'from_warehouse_id' => $this->fgStore->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '5']],
        ])->assertStatus(422)->assertJsonValidationErrors(['lines']);

        $this->assertSame('100.0000', $this->balance($this->resin, $this->fgStore));
        $this->assertSame('0.0000', $this->balance($this->resin, $this->qualityHold));
    }

    public function test_a_report_cannot_take_more_than_is_standing(): void
    {
        Sanctum::actingAs($this->storeKeeper);

        $this->postJson('/api/v1/inventory/damaged-finished-goods', [
            'from_warehouse_id' => $this->fgStore->id,
            'lines' => [['item_id' => $this->bottle->id, 'quantity' => '101']],
        ])->assertStatus(422)->assertJsonValidationErrors(['lines']);

        $this->assertSame('100.0000', $this->balance($this->bottle, $this->fgStore));
    }

    public function test_two_lines_for_one_item_cannot_each_claim_the_whole_balance(): void
    {
        Sanctum::actingAs($this->storeKeeper);

        $this->postJson('/api/v1/inventory/damaged-finished-goods', [
            'from_warehouse_id' => $this->fgStore->id,
            'lines' => [
                ['item_id' => $this->bottle->id, 'quantity' => '60'],
                ['item_id' => $this->bottle->id, 'quantity' => '60'],
            ],
        ])->assertStatus(422);

        // The whole report is refused, not half-applied.
        $this->assertSame('100.0000', $this->balance($this->bottle, $this->fgStore));
        $this->assertSame('0.0000', $this->balance($this->bottle, $this->qualityHold));
    }

    public function test_reporting_out_of_the_hold_itself_is_refused(): void
    {
        Sanctum::actingAs($this->storeKeeper);

        $this->postJson('/api/v1/inventory/damaged-finished-goods', [
            'from_warehouse_id' => $this->qualityHold->id,
            'lines' => [['item_id' => $this->bottle->id, 'quantity' => '1']],
        ])->assertStatus(422)->assertJsonValidationErrors(['from_warehouse_id']);
    }

    /** @param list<string> $permissions */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    /** 4dp always — a missing balance row is zero, and must read like every other zero. */
    private function balance(Item $item, Warehouse $warehouse): string
    {
        return bcadd((string) (StockBalance::query()
            ->where('item_id', $item->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('quantity') ?? '0'), '0', 4);
    }
}
