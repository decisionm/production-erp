<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use Database\Seeders\CanonicalMachineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Starting a batch when the machine's bin bay does not hold enough material.
 *
 * The shape of the rule, stated once here because it is the thing that is
 * easy to get backwards:
 *
 *   The shortage is a UI-side PROMPT, not a server-side GATE. The Start
 *   Batch dialog shows the shortfall and refuses its own OK button until
 *   the supervisor ticks "start anyway" and types a reason — but the API
 *   RECORDS the answer rather than refusing the start. A bay that is
 *   mid-load, a bin count that has not been taken yet, or a material the
 *   ledger simply does not track are all ordinary and none of them should
 *   be able to stop a machine the floor can legitimately run. Refusing
 *   here would turn a paperwork gap into lost production.
 *
 * So: with a reason, the reason is on the entry. Without one, the batch
 * still starts.
 */
class MaterialShortageOverrideTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Item $resin;

    private Item $cap;

    private Warehouse $fg;

    private Shift $shift;

    private WorkCenter $machine;

    protected function setUp(): void
    {
        parent::setUp();

        // The bin bay only exists with traceability on — availability is a
        // 404 otherwise, which is exactly why the frontend gate must fail
        // open rather than closed.
        config()->set('production.traceability_enabled', true);

        $this->seed(CanonicalMachineSeeder::class);
        $this->machine = WorkCenter::where('code', 'MC-01')->firstOrFail();
        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);

        Warehouse::create(['code' => 'RM', 'name' => 'RM Store', 'is_active' => true, 'tally_guid' => 'gd-rm']);
        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg']);

        // Kgs → day-bin tracked. Nos → a consumable the bin never holds.
        $this->resin = Item::create(['sku' => 'PET-IV08', 'name' => 'Billion Pet Resin IV-0.8', 'uom' => 'Kgs.', 'is_active' => true, 'tally_stock_item_guid' => 'g1']);
        $this->cap = Item::create(['sku' => 'CAP-28', 'name' => '28mm Cap', 'uom' => 'Nos.', 'is_active' => true, 'tally_stock_item_guid' => 'g2']);

        // Fully specified on purpose: the readiness gate refuses a start
        // before startBatch() ever reaches the snapshot write, so an
        // under-specified product would fail these tests for a reason that
        // has nothing to do with material shortage.
        $this->bottle = Item::create([
            'sku' => 'BTL-100-RND', 'name' => '100ML ROUND', 'uom' => 'Nos.', 'is_active' => true,
            'nominal_weight_grams' => '12.9000', 'standard_cycle_time' => '12.30', 'standard_cavities' => 5,
            'nos_per_tray' => 162, 'trays_per_box' => 5, 'nos_per_box' => 810,
            'colour' => 'Amber', 'tally_stock_item_guid' => 'g3',
        ]);

        $bom = Bom::create(['item_id' => $this->bottle->id, 'name' => 'recipe', 'version' => '1', 'is_active' => true]);
        $bom->lines()->create(['component_item_id' => $this->resin->id, 'quantity_per' => '0.0129']);
        $bom->lines()->create(['component_item_id' => $this->cap->id, 'quantity_per' => '1.0000']);
    }

    private function actAsSupervisor(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['production.view', 'production.manage', 'inventory.view', 'inventory.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo(['production.view', 'production.manage', 'inventory.view', 'inventory.manage']);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function startBatch(array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/production/shift-production-entries', array_merge([
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => '2026-07-29',
        ], $extra));
    }

    /**
     * The contract the Start Batch dialog's shortage predicate is built on:
     * a mass component reports a real shortfall, and a Nos consumable
     * reports NULL — it never sits in the bin, so "short" is meaningless
     * for it and must never be shown as one.
     */
    public function test_an_empty_bin_is_short_on_mass_components_and_silent_on_nos_consumables(): void
    {
        $this->actAsSupervisor();

        $components = $this->getJson('/api/v1/production/bin-bay/availability?'.http_build_query([
            'work_center_id' => $this->machine->id,
            'product_item_id' => $this->bottle->id,
            'expected_pieces' => 11705,
        ]))->assertOk()->json('data.requirement.components');

        $resinLine = collect($components)->firstWhere('item_id', $this->resin->id);
        $capLine = collect($components)->firstWhere('item_id', $this->cap->id);

        $this->assertTrue($resinLine['is_mass']);
        $this->assertSame('0.0000', $resinLine['available_quantity'], 'Nothing has been loaded into this bay.');
        $this->assertGreaterThan(0, (float) $resinLine['shortage_quantity']);

        $this->assertFalse($capLine['is_mass']);
        $this->assertNull(
            $capLine['shortage_quantity'],
            'A Nos consumable is not bin-tracked — it must never be presented as short.',
        );
    }

    /** The override reason is recorded against the entry that started short. */
    public function test_starting_with_an_empty_bin_records_the_override_reason(): void
    {
        $supervisor = $this->actAsSupervisor();

        $entryId = $this->startBatch([
            'material_shortage_override_reason' => 'Bay is weighing the next lot in now — resin lands within the hour.',
        ])->assertOk()->json('data.id');

        // Read back from a fresh model, not the create-time instance: the
        // point is that it PERSISTED, not that it was passed in.
        $snapshot = ShiftProductionEntry::findOrFail($entryId)->config_snapshot;

        $this->assertSame(
            'Bay is weighing the next lot in now — resin lands within the hour.',
            $snapshot['material_shortage_override_reason'],
        );
        // Audited: who waved it through, not just that someone did.
        $this->assertSame($supervisor->id, $snapshot['material_shortage_override_by']);

        // The standards-override columns are untouched. They carry this
        // run's cycle-time/cavity deviation and must not pick up a second
        // meaning from the material override.
        $entry = ShiftProductionEntry::findOrFail($entryId);
        $this->assertNull($entry->override_reason);
        $this->assertNull($entry->override_by);
    }

    /**
     * No reason, still a batch.
     *
     * The tick-box and the reason are a UI GUARD in the Start Batch dialog.
     * The server deliberately does not enforce them: it records what it is
     * given and refuses nothing, so an older client, a direct API caller,
     * or a bay that is simply mid-load can never be locked out of starting
     * a machine that is ready to run.
     */
    public function test_starting_short_without_a_reason_still_succeeds(): void
    {
        $this->actAsSupervisor();

        $entryId = $this->startBatch()->assertOk()->json('data.id');

        $snapshot = ShiftProductionEntry::findOrFail($entryId)->config_snapshot;

        $this->assertNull($snapshot['material_shortage_override_reason']);
        $this->assertNull($snapshot['material_shortage_override_by']);
    }

    /** A blank string is not a reason — it must not read as one downstream. */
    public function test_a_whitespace_only_reason_is_stored_as_no_reason(): void
    {
        $this->actAsSupervisor();

        $entryId = $this->startBatch(['material_shortage_override_reason' => '   '])
            ->assertOk()->json('data.id');

        $snapshot = ShiftProductionEntry::findOrFail($entryId)->config_snapshot;

        $this->assertNull($snapshot['material_shortage_override_reason']);
        $this->assertNull($snapshot['material_shortage_override_by']);
    }

    /** Retrievable long after the fact, from the entry itself. */
    public function test_the_reason_is_retrievable_after_the_batch_has_started(): void
    {
        $this->actAsSupervisor();

        $entryId = $this->startBatch(['material_shortage_override_reason' => 'MB drum swap in progress.'])
            ->assertOk()->json('data.id');

        // Re-queried from scratch, as a later reader (approval desk, audit
        // trail) would: nothing about the reason depends on still holding
        // the object that created it.
        $snapshot = ShiftProductionEntry::query()->whereKey($entryId)->value('config_snapshot');

        $this->assertSame('MB drum swap in progress.', $snapshot['material_shortage_override_reason']);
    }
}
