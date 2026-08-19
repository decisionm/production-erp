<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Services\FactoryWarehouseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * WHAT IS STANDING ON THE PRODUCTION FLOOR — and why it is read from stock.
 *
 * The floor asked to see, beside the request form, how much of each material it
 * already holds. The tempting implementation is to add up what has been issued.
 * That would be wrong, and wrong in the direction that matters: issues only
 * ever go up, so a floor built from issue history never empties, and a
 * supervisor would ask for resin they are standing next to.
 *
 * Production/WIP is a real inventory location (DEC-20260817-001), so its
 * BALANCE is the only thing that answers "what is there now" — it falls when a
 * batch consumes and when material goes back to the store.
 *
 * FC-01 lives here too: the figures are per MATERIAL and never per machine. A
 * bag belongs to no machine and no batch.
 */
class ProductionFloorStockTest extends TestCase
{
    use RefreshDatabase;

    private Item $resin;

    private Warehouse $store;

    private Warehouse $wip;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['is_active' => true]);
        foreach (['production.manage', 'inventory.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        $this->resin = Item::create([
            'sku' => 'RM-PET', 'name' => 'Relpet PET Resin', 'uom' => 'Kgs',
            'is_active' => true, 'is_production_input' => true,
        ]);

        $this->store = Warehouse::create(['code' => 'FS-RM', 'name' => 'FS Raw Material Store', 'is_active' => true, 'tally_guid' => 'fs-gd']);
        $this->wip = Warehouse::create(['code' => 'FS-WIP', 'name' => 'FS Production WIP', 'is_active' => false]);

        app(ProductionWipLocationResolver::class)->setWarehouseId($this->wip->id);
        app(FactoryWarehouseResolver::class)->setRawMaterialWarehouseId($this->store->id);

        app(StockMovementService::class)->recordReceipt(
            itemId: $this->resin->id,
            warehouseId: $this->store->id,
            quantity: '1000',
            unitCost: '1.00',
            reference: 'FS opening',
            purpose: StockMovementPurpose::Opening,
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function floor(): array
    {
        return $this->getJson('/api/v1/inventory/production-floor-stock')->assertOk()->json('data');
    }

    public function test_an_empty_floor_reports_nothing_rather_than_a_zero(): void
    {
        // Stock exists, but it is all in the STORE. The floor holds none of it,
        // and a row reading 0 would invite "why is there resin on the floor?"
        $this->assertSame([], $this->floor());
    }

    public function test_material_issued_to_production_appears_with_its_wip_quantity(): void
    {
        $this->issue('300');

        $floor = $this->floor();

        $this->assertCount(1, $floor);
        $this->assertSame('RM-PET', $floor[0]['sku']);
        $this->assertSame('Relpet PET Resin', $floor[0]['name']);
        $this->assertSame('Kgs', $floor[0]['uom']);
        $this->assertSame(0, bccomp((string) $floor[0]['quantity'], '300', 4));
    }

    /**
     * THE POINT OF THE WHOLE SERVICE. If this were built from issue history the
     * figure would still read 300 after the batch ran.
     */
    public function test_the_figure_falls_when_production_consumes_it(): void
    {
        $this->issue('300');

        app(StockMovementService::class)->recordIssue(
            itemId: $this->resin->id,
            warehouseId: $this->wip->id,
            quantity: '120',
            reference: 'FS batch',
            purpose: StockMovementPurpose::Consumption,
        );

        $floor = $this->floor();

        $this->assertSame(0, bccomp((string) $floor[0]['quantity'], '180', 4),
            'the floor must report what is STANDING there, not the sum of what was issued');
    }

    public function test_a_material_fully_consumed_leaves_the_floor_entirely(): void
    {
        $this->issue('300');

        app(StockMovementService::class)->recordIssue(
            itemId: $this->resin->id,
            warehouseId: $this->wip->id,
            quantity: '300',
            reference: 'FS batch',
            purpose: StockMovementPurpose::Consumption,
        );

        $this->assertSame([], $this->floor());
    }

    public function test_the_row_names_the_handover_it_came_from_and_both_people_on_it(): void
    {
        $this->issue('300');

        $row = $this->floor()[0];

        $this->assertNotNull($row['last_issued_at']);
        $this->assertNotNull($row['last_issue_number']);
        $this->assertNotNull($row['issued_by']);
    }

    public function test_nothing_on_the_surface_names_a_machine_or_a_day_bin(): void
    {
        $this->issue('300');

        $json = json_encode($this->floor());

        // FC-01: the figures are per material. A bag belongs to no machine.
        $this->assertStringNotContainsStringIgnoringCase('work_center', (string) $json);
        $this->assertStringNotContainsStringIgnoringCase('machine', (string) $json);
        // DEC-20260817-001: there is no Day Bin.
        $this->assertStringNotContainsStringIgnoringCase('day_bin', (string) $json);
        $this->assertStringNotContainsStringIgnoringCase('day bin', (string) $json);
    }

    /**
     * A NEGATIVE BALANCE IS THE ONE ROW THAT MUST NOT DISAPPEAR.
     *
     * This system deliberately does not refuse a batch that consumes more than
     * was issued to it, so WIP genuinely goes negative
     * (StoreIssueReversalBoundsTest pins exactly that). If the panel filtered
     * to `> 0`, the floor would see NOTHING for a material it is standing next
     * to and raise another request — the precise failure this panel exists to
     * prevent.
     */
    public function test_an_over_consumed_material_is_still_shown_rather_than_hidden(): void
    {
        $this->issue('100');

        app(StockMovementService::class)->recordIssue(
            itemId: $this->resin->id,
            warehouseId: $this->wip->id,
            quantity: '150',
            reference: 'FS over-consumption',
            // The same seam a batch completion uses. Consumption is CALCULATED,
            // not counted, so a batch can compute more than was ever issued —
            // the system records the deficit rather than refusing the shift's
            // paperwork at the end of a shift.
            allowNegative: true,
            purpose: StockMovementPurpose::Consumption,
        );

        $floor = $this->floor();

        $this->assertCount(1, $floor, 'a deficit must be visible, not filtered away');
        $this->assertSame(0, bccomp((string) $floor[0]['quantity'], '-50', 4));
    }

    /**
     * The timestamp must carry its offset. A raw "2026-08-18 07:06:13" is read
     * by a browser as LOCAL time while the value is UTC — 5h30m out here, and
     * a night-shift handover lands on the wrong calendar day.
     */
    public function test_the_handover_time_carries_its_timezone(): void
    {
        $this->issue('100');

        $when = $this->floor()[0]['last_issued_at'];

        $this->assertNotNull($when);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/',
            (string) $when,
            "last_issued_at must be ISO8601 with an offset, got: {$when}",
        );
    }

    /**
     * "The floor is empty" and "nobody has told the ERP where the floor is" are
     * different statements, and the screen must be able to tell them apart.
     */
    public function test_an_unconfigured_wip_location_is_reported_as_such(): void
    {
        app(ProductionWipLocationResolver::class)->setWarehouseId(null);

        $response = $this->getJson('/api/v1/inventory/production-floor-stock')->assertOk();

        $this->assertSame([], $response->json('data'));
        $this->assertFalse($response->json('meta.wip_configured'));
    }

    public function test_a_configured_location_says_so(): void
    {
        $this->assertTrue(
            $this->getJson('/api/v1/inventory/production-floor-stock')->assertOk()->json('meta.wip_configured'),
        );
    }

    /** FC-06: no rate, no cost, no vendor may ride along on a floor screen. */
    public function test_the_floor_surface_carries_no_rate_cost_or_supplier(): void
    {
        $this->issue('100');

        $json = strtolower((string) json_encode($this->floor()));

        foreach (['rate', 'cost', 'price', 'amount', 'vendor', 'supplier'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json, "FC-06: '{$forbidden}' must not appear on the floor panel");
        }
    }

    private function issue(string $quantity): void
    {
        $this->postJson('/api/v1/inventory/store-issues', [
            'lines' => [['item_id' => $this->resin->id, 'quantity' => $quantity]],
        ])->assertStatus(201);
    }
}
