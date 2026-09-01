<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftMaterialConsumption;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\FactoryWarehouseResolver;
use Database\Seeders\CanonicalMachineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * WHEN THE RUN CONSUMES SOMETHING IT WAS NOT PLANNED ON.
 *
 * The case is ordinary and the factory has stated it: the 100 ml cartons ran
 * out, so today's run goes in a 90 ml box. What must never happen is that one
 * material quietly stands in for another — the substitution reaching a Tally
 * Stock Journal with nothing on the batch to say it happened.
 *
 * Until this change `material_consumptions.*.item_id` was a bare
 * `exists:items,id`. A finished bottle, a scrap grade or a spare part could be
 * booked as this run's own input, by anyone who can complete a batch, and be
 * carried onto a voucher as one.
 *
 * Four rules, one per test below:
 *
 *  1. A material outside the list is refused outright, naming the row.
 *  2. A material on the list but not planned for this run is an ADDED line: it
 *     needs a reason AND an authorised user, and is refused without either.
 *  3. An added line is ADDITIVE — the material it stood in for keeps its own
 *     line at its own quantity, untouched. The owner's answer, 01-Sep-2026.
 *  4. The dropdown a screen renders IS the list the server refuses against, so
 *     a screen cannot offer a choice the completion would then reject.
 */
class AddedConsumptionLineTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Item $resin;

    private Item $plannedCarton;

    private Item $substituteCarton;

    private Item $spare;

    private Warehouse $store;

    private Shift $shift;

    private WorkCenter $machine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CanonicalMachineSeeder::class);
        $this->machine = WorkCenter::where('code', 'MC-01')->firstOrFail();
        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);

        $this->store = Warehouse::create(['code' => 'RM-STORE', 'name' => 'Store', 'is_active' => true, 'tally_guid' => 'gd-store']);
        app(FactoryWarehouseResolver::class)->setFinishedGoodsWarehouseId($this->store->id);
        app(FactoryWarehouseResolver::class)->setRawMaterialWarehouseId($this->store->id);
        app(FactoryWarehouseResolver::class)->setPackingMaterialWarehouseId($this->store->id);

        $this->resin = Item::create([
            'sku' => 'RELPET', 'name' => 'Relpet', 'uom' => 'Kgs.', 'is_active' => true,
            'category' => ItemCategory::RawMaterial->value, 'tally_stock_item_guid' => 'g1',
        ]);
        $this->plannedCarton = Item::create([
            'sku' => 'BOX-100', 'name' => '100 Ml Master Box', 'uom' => 'Nos', 'is_active' => true,
            'category' => ItemCategory::PackingMaterial->value, 'tally_stock_item_guid' => 'g2',
        ]);
        // On the list, not on the plan — the substitute the factory reaches for.
        $this->substituteCarton = Item::create([
            'sku' => 'BOX-90', 'name' => '90 Ml Master Box', 'uom' => 'Nos', 'is_active' => true,
            'category' => ItemCategory::PackingMaterial->value, 'tally_stock_item_guid' => 'g3',
        ]);
        // Not a production input at all.
        $this->spare = Item::create([
            'sku' => 'SERVO-AMP', 'name' => 'Servo Amplifier', 'uom' => 'Nos', 'is_active' => true,
            'category' => ItemCategory::SpareTooling->value, 'tally_stock_item_guid' => 'g4',
        ]);

        $this->bottle = Item::create([
            'sku' => 'BTL-100-RND', 'name' => '100ML ROUND', 'uom' => 'Nos.', 'is_active' => true,
            'category' => ItemCategory::FinishedGood->value,
            'nominal_weight_grams' => '12.9000', 'standard_cycle_time' => '12.30', 'standard_cavities' => 5,
            'nos_per_tray' => 162, 'trays_per_box' => 5, 'nos_per_box' => 810,
            'colour' => 'Amber', 'tally_stock_item_guid' => 'g5',
        ]);

        $bom = Bom::create(['item_id' => $this->bottle->id, 'name' => 'recipe', 'version' => '1', 'is_active' => true]);
        $bom->lines()->create(['component_item_id' => $this->resin->id, 'quantity_per' => '0.0129']);
        $bom->lines()->create(['component_item_id' => $this->plannedCarton->id, 'quantity_per' => '0.0012']);
    }

    // ---- (1) outside the list ---------------------------------------------

    /** The bottle this run is making is not one of its own inputs. */
    public function test_the_product_being_made_cannot_be_booked_as_its_own_input(): void
    {
        $this->actingAsAuthorisedSupervisor();
        $entry = $this->startBatch();

        $this->complete($entry, [
            ['item_id' => $this->resin->id, 'quantity_issued_kg' => '15.09'],
            ['item_id' => $this->bottle->id, 'quantity_issued_kg' => '15', 'added_reason' => 'Rework'],
        ])->assertStatus(422)->assertJsonValidationErrors('material_consumptions.1.item_id');

        $this->assertSame(0, ShiftMaterialConsumption::query()->count());
    }

    /** Any other finished good is refused too — none appears on an OUT line in the factory's own vouchers. */
    public function test_another_products_finished_bottle_is_refused_as_an_input(): void
    {
        $other = Item::create([
            'sku' => 'BTL-200-RND', 'name' => '200ML ROUND', 'uom' => 'Nos.', 'is_active' => true,
            'category' => ItemCategory::FinishedGood->value, 'tally_stock_item_guid' => 'g6',
        ]);

        $this->actingAsAuthorisedSupervisor();
        $entry = $this->startBatch();

        $this->complete($entry, [
            ['item_id' => $other->id, 'quantity_issued_kg' => '15', 'added_reason' => 'Regrind'],
        ])->assertStatus(422)->assertJsonValidationErrors('material_consumptions.0.item_id');
    }

    /**
     * A SPARE PART IS STILL ACCEPTED, AND THAT IS DELIBERATE — it is the shape
     * of an open owner question, not an oversight.
     *
     * DEC-20260827-001 classified the catalogue and then said in terms that the
     * classification switches on NO enforcement: which categories each document
     * may use is Q59 and stays open. Refusing a spare here would answer Q59 on
     * a completion screen. What the floor gets instead is the honest half: it
     * is off-plan, so it needs a reason and an authorised user, and it is
     * visible on the batch as an added line rather than silent.
     *
     * When Q59 is answered, this test is the one that changes.
     */
    public function test_an_off_plan_spare_is_recorded_with_a_reason_rather_than_refused_while_q59_is_open(): void
    {
        $this->actingAsAuthorisedSupervisor();
        $entry = $this->startBatch();

        $this->complete($entry, [
            ['item_id' => $this->resin->id, 'quantity_issued_kg' => '15.09'],
            ['item_id' => $this->spare->id, 'quantity_issued_kg' => '1', 'added_reason' => 'Fitted a new amp'],
        ])->assertOk();

        $spareLine = ShiftMaterialConsumption::query()->where('item_id', $this->spare->id)->sole();
        $this->assertSame('Fitted a new amp', $spareLine->added_reason);
    }

    // ---- (2) on the list, not on the plan ----------------------------------

    public function test_an_unplanned_material_needs_a_reason(): void
    {
        $this->actingAsAuthorisedSupervisor();
        $entry = $this->startBatch();

        $this->complete($entry, [
            ['item_id' => $this->substituteCarton->id, 'quantity_issued_kg' => '14'],
        ])->assertStatus(422)->assertJsonValidationErrors('material_consumptions.0.added_reason');
    }

    public function test_an_unplanned_material_needs_an_authorised_user(): void
    {
        // A supervisor who may complete a batch, and nothing more.
        $this->actingAsSupervisor();
        $entry = $this->startBatch();

        $this->complete($entry, [
            ['item_id' => $this->substituteCarton->id, 'quantity_issued_kg' => '14', 'added_reason' => '100 ml boxes ran out'],
        ])->assertStatus(422)->assertJsonValidationErrors('material_consumptions.0.item_id');

        $this->assertSame(0, ShiftMaterialConsumption::query()->count());
    }

    public function test_a_planned_material_needs_neither(): void
    {
        $this->actingAsSupervisor();
        $entry = $this->startBatch();

        $this->complete($entry, [
            ['item_id' => $this->resin->id, 'quantity_issued_kg' => '15.09'],
            ['item_id' => $this->plannedCarton->id, 'quantity_issued_kg' => '14'],
        ])->assertOk();

        $this->assertSame(2, ShiftMaterialConsumption::query()->count());
        $this->assertSame(
            0,
            ShiftMaterialConsumption::query()->whereNotNull('added_reason')->count(),
            'An expected material is not an added line.',
        );
    }

    // ---- (3) additive, never a silent substitution -------------------------

    public function test_an_added_line_stands_beside_the_material_it_replaced_and_never_reduces_it(): void
    {
        $authorised = $this->actingAsAuthorisedSupervisor();
        $entry = $this->startBatch();

        // The floor ran short: 4 of the planned cartons, 10 of the substitute.
        $this->complete($entry, [
            ['item_id' => $this->resin->id, 'quantity_issued_kg' => '15.09'],
            ['item_id' => $this->plannedCarton->id, 'quantity_issued_kg' => '4'],
            [
                'item_id' => $this->substituteCarton->id,
                'quantity_issued_kg' => '10',
                'added_reason' => '100 ml boxes ran out at 11:20; packed the rest in 90 ml',
            ],
        ])->assertOk();

        $lines = ShiftMaterialConsumption::query()->get()->keyBy('item_id');
        $this->assertCount(3, $lines);

        // THE POINT OF THE WHOLE PATH: the planned carton keeps its own line at
        // its own quantity. Nothing reduced it, nothing cleared it, and both
        // materials will reach the voucher exactly as recorded.
        $this->assertSame(0, bccomp((string) $lines[$this->plannedCarton->id]->quantity_issued_kg, '4', 4));
        $this->assertNull($lines[$this->plannedCarton->id]->added_reason);

        $added = $lines[$this->substituteCarton->id];
        $this->assertSame(0, bccomp((string) $added->quantity_issued_kg, '10', 4));
        $this->assertSame('100 ml boxes ran out at 11:20; packed the rest in 90 ml', $added->added_reason);
        $this->assertSame($authorised->id, (int) $added->added_by);
    }

    // ---- (4) the dropdown is the refusal set -------------------------------

    public function test_the_dropdown_offers_exactly_what_the_completion_will_accept(): void
    {
        $this->actingAsAuthorisedSupervisor();
        $entry = $this->startBatch();

        $body = $this->getJson("/api/v1/production/shift-production-entries/{$entry->id}/consumable-materials")
            ->assertOk()->json('data');

        $offered = collect($body['options'])->pluck('item_id')->all();

        $this->assertContains($this->resin->id, $offered);
        $this->assertContains($this->plannedCarton->id, $offered);
        $this->assertContains($this->substituteCarton->id, $offered);
        $this->assertNotContains($this->bottle->id, $offered, 'A run does not consume the bottle it is making.');

        // And the drawer can tell the planned ones apart, so it knows which
        // rows will ask for a reason before the supervisor types one.
        $expected = collect($body['options'])->where('is_expected', true)->pluck('item_id')->all();
        $this->assertEqualsCanonicalizing([$this->resin->id, $this->plannedCarton->id], $expected);

        $this->assertTrue($body['may_add_unplanned']);
    }

    public function test_the_dropdown_tells_an_ordinary_supervisor_they_may_not_add_a_line(): void
    {
        $this->actingAsSupervisor();
        $entry = $this->startBatch();

        $this->getJson("/api/v1/production/shift-production-entries/{$entry->id}/consumable-materials")
            ->assertOk()->assertJsonPath('data.may_add_unplanned', false);
    }

    // ---- helpers -----------------------------------------------------------

    private function actingAsSupervisor(): User
    {
        return $this->actAs(['production.view', 'production.manage', 'inventory.view', 'inventory.manage']);
    }

    private function actingAsAuthorisedSupervisor(): User
    {
        return $this->actAs([
            'production.view', 'production.manage', 'inventory.view', 'inventory.manage',
            'consumption-substitute.manage',
        ]);
    }

    /** @param array<int, string> $permissions */
    private function actAs(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);
        Sanctum::actingAs($user);

        return $user;
    }

    private function startBatch(): ShiftProductionEntry
    {
        $id = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->store->id,
            'production_date' => '2026-09-01',
        ])->assertOk()->json('data.id');

        return ShiftProductionEntry::findOrFail($id);
    }

    /** @param array<int, array<string, mixed>> $consumptions */
    private function complete(ShiftProductionEntry $entry, array $consumptions): TestResponse
    {
        return $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => 11340,
            'nos_per_box' => 810,
            'no_of_box' => 14,
            'material_consumptions' => $consumptions,
        ]);
    }
}
