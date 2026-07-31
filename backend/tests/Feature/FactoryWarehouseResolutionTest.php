<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\FactoryDayBinService;
use App\Modules\Production\Services\FactoryWarehouseResolver;
use App\Modules\TallySync\Services\TallySyncService;
use Database\Seeders\CanonicalMachineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE FLOOR IS NEVER ASKED WHICH STORE. The owner's ruling (30-Jul): "there
 * is no need to select any store in any place. what is packing store —
 * everything happening inside the factory."
 *
 * One factory, one physical place, and — the fact that makes a silent server
 * answer safe rather than a guess — exactly ONE godown in their Tally books.
 * So when nothing has been configured there is genuinely nothing to choose
 * between, and FactoryWarehouseResolver says so.
 *
 * What these tests pin down, in the order the precedence runs:
 *   1. the app setting, when one names a live active warehouse;
 *   2. else the single Tally-linked warehouse, when there is exactly one;
 *   3. else NOTHING — a plain 422 naming the Settings fix, never a demo
 *      warehouse and never a coin flip between two candidates.
 *
 * The third rule is the one worth being loud about. A "helpful" pick between
 * two linked warehouses would book finished bottles into a location the
 * accountant's books disagree with, and nobody would find out until the
 * voucher was rejected hours after the shift ended.
 */
class FactoryWarehouseResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Item $resin;

    private Item $cap;

    private Shift $shift;

    private WorkCenter $machine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CanonicalMachineSeeder::class);
        $this->machine = WorkCenter::where('code', 'MC-01')->firstOrFail();
        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);

        $this->resin = Item::create(['sku' => 'PET-IV08', 'name' => 'Billion Pet Resin IV-0.8', 'uom' => 'Kgs.', 'is_active' => true, 'tally_stock_item_guid' => 'g1']);
        $this->cap = Item::create(['sku' => 'CAP-28', 'name' => '28mm Cap', 'uom' => 'Nos.', 'is_active' => true, 'tally_stock_item_guid' => 'g2']);

        // Fully specified: the readiness gate refuses a start long before
        // startBatch() reaches the warehouse snapshot, so an under-specified
        // product would fail these tests for an unrelated reason.
        $this->bottle = Item::create([
            'sku' => 'BTL-100-RND', 'name' => '100ML ROUND', 'uom' => 'Nos.', 'is_active' => true,
            'nominal_weight_grams' => '12.9000', 'standard_cycle_time' => '12.30', 'standard_cavities' => 5,
            'nos_per_tray' => 162, 'trays_per_box' => 5, 'nos_per_box' => 810,
            'colour' => 'Amber', 'tally_stock_item_guid' => 'g3',
        ]);

        $bom = Bom::create(['item_id' => $this->bottle->id, 'name' => 'recipe', 'version' => '1', 'is_active' => true]);
        $bom->lines()->create(['component_item_id' => $this->resin->id, 'quantity_per' => '0.0129']);
        $bom->lines()->create(['component_item_id' => $this->cap->id, 'quantity_per' => '1.0000']);

        $this->actAsSupervisor();
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
     * Start Batch WITHOUT a warehouse_id — the payload the floor now sends.
     *
     * @param  array<string, mixed>  $extra
     */
    private function startBatch(array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/production/shift-production-entries', array_merge([
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'production_date' => '2026-07-29',
        ], $extra));
    }

    /** The factory's real shape: one godown, mirrored as one linked warehouse. */
    private function soleFactoryWarehouse(): Warehouse
    {
        return Warehouse::create([
            'code' => 'SWAASHPET', 'name' => 'SWAASHPET POLYMERS PVT LTD',
            'is_active' => true, 'tally_guid' => 'gd-swaashpet',
        ]);
    }

    // ---------------------------------------------------------------
    // Precedence rule 2 — the single Tally-linked warehouse
    // ---------------------------------------------------------------

    public function test_start_batch_without_a_warehouse_lands_in_the_single_tally_linked_warehouse(): void
    {
        $factory = $this->soleFactoryWarehouse();

        $response = $this->startBatch()->assertOk();

        // Resolved silently, and RECORDED on the entry exactly as a
        // client-sent id would have been — the snapshot is the same shape
        // whoever answered the question.
        $entry = ShiftProductionEntry::findOrFail($response->json('data.id'));
        $this->assertSame($factory->id, $entry->warehouse_id);
    }

    /**
     * A deactivated warehouse is not a candidate, even with a Tally guid.
     * This is what keeps rehearsal residue out of the answer once it has
     * been retired — the row survives for its history, it just stops being
     * selectable, and the live godown remains the only candidate.
     */
    public function test_a_deactivated_tally_linked_warehouse_is_not_a_candidate(): void
    {
        $factory = $this->soleFactoryWarehouse();
        Warehouse::create([
            'code' => 'OLD-FG', 'name' => 'Retired FG Store',
            'is_active' => false, 'tally_guid' => 'gd-retired',
        ]);

        $response = $this->startBatch()->assertOk();

        $this->assertSame($factory->id, ShiftProductionEntry::findOrFail($response->json('data.id'))->warehouse_id);
    }

    // ---------------------------------------------------------------
    // Precedence rule 1 — the app setting wins
    // ---------------------------------------------------------------

    public function test_the_configured_finished_goods_setting_wins_over_the_fallback(): void
    {
        // TWO linked warehouses, so the fallback alone would decline. The
        // setting is what makes this deployment answerable at all, which is
        // precisely the case it exists for.
        $this->soleFactoryWarehouse();
        $namedFg = Warehouse::create([
            'code' => 'FG-REAL', 'name' => 'Finished Goods',
            'is_active' => true, 'tally_guid' => 'gd-fg-real',
        ]);

        app(FactoryWarehouseResolver::class)->setFinishedGoodsWarehouseId($namedFg->id);

        $response = $this->startBatch()->assertOk();

        $this->assertSame($namedFg->id, ShiftProductionEntry::findOrFail($response->json('data.id'))->warehouse_id);
    }

    // ---------------------------------------------------------------
    // Precedence rule 3 — decline, plainly
    // ---------------------------------------------------------------

    public function test_two_tally_linked_warehouses_and_no_setting_is_a_plain_422(): void
    {
        $this->soleFactoryWarehouse();
        Warehouse::create(['code' => 'FG-2', 'name' => 'Second Store', 'is_active' => true, 'tally_guid' => 'gd-2']);

        $response = $this->startBatch()
            ->assertStatus(422)
            ->assertJsonValidationErrors(['warehouse_id']);

        // The message has to name the fix, because the person reading it is
        // a supervisor mid-shift who cannot be expected to infer "an admin
        // must name a warehouse in Production settings".
        $this->assertStringContainsString('Production settings', $response->json('errors.warehouse_id.0'));

        $this->assertSame(0, ShiftProductionEntry::count());
    }

    public function test_no_tally_linked_warehouse_at_all_is_the_same_plain_422(): void
    {
        // Warehouses exist, but none is linked to Tally — a fresh instance
        // whose godowns have never been synced. Nothing may be picked from
        // them, least of all a demo row.
        Warehouse::create(['code' => 'RM-STORE', 'name' => 'Raw Material Store', 'is_active' => true]);
        Warehouse::create(['code' => 'FG-STORE', 'name' => 'Finished Goods Store', 'is_active' => true]);

        $response = $this->startBatch()
            ->assertStatus(422)
            ->assertJsonValidationErrors(['warehouse_id']);

        $this->assertStringContainsString('Production settings', $response->json('errors.warehouse_id.0'));
        $this->assertSame(0, ShiftProductionEntry::count());
    }

    // ---------------------------------------------------------------
    // Existing flows that DO send a warehouse_id are untouched
    // ---------------------------------------------------------------

    public function test_an_explicitly_sent_warehouse_is_honoured_untouched(): void
    {
        $factory = $this->soleFactoryWarehouse();
        $explicit = Warehouse::create([
            'code' => 'FG-PICK', 'name' => 'Chosen Store',
            'is_active' => true, 'tally_guid' => 'gd-pick',
        ]);

        // A setting exists AND it names a different warehouse; neither it
        // nor the fallback may override an id the caller actually sent.
        app(FactoryWarehouseResolver::class)->setFinishedGoodsWarehouseId($factory->id);

        $response = $this->startBatch(['warehouse_id' => $explicit->id])->assertOk();

        $this->assertSame($explicit->id, ShiftProductionEntry::findOrFail($response->json('data.id'))->warehouse_id);
    }

    /**
     * Making the field optional must not have weakened it. An explicit id
     * naming a RETIRED warehouse is still refused rather than quietly
     * swapped for the resolved one — the caller asked for something
     * specific and wrong, and that is worth saying out loud.
     */
    public function test_an_explicit_inactive_warehouse_is_still_refused(): void
    {
        $this->soleFactoryWarehouse();
        $retired = Warehouse::create(['code' => 'OLD', 'name' => 'Retired', 'is_active' => false]);

        $this->startBatch(['warehouse_id' => $retired->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['warehouse_id']);
    }

    // ---------------------------------------------------------------
    // Consumption lines — the same question, answered per line
    // ---------------------------------------------------------------

    public function test_consumption_lines_without_a_warehouse_resolve_to_the_bin_or_the_store(): void
    {
        $factory = $this->soleFactoryWarehouse();
        $dayBin = Warehouse::create(['code' => 'DAY-BIN', 'name' => 'Factory Day Bin', 'is_active' => true]);
        app(FactoryDayBinService::class)->setWarehouseId($dayBin->id);

        // Resin has been moved into the bin for the run; caps never pass
        // through the kg bin at all and sit in the store.
        StockBalance::create(['item_id' => $this->resin->id, 'warehouse_id' => $dayBin->id, 'quantity' => '500.0000', 'average_cost' => '85.0000']);
        StockBalance::create(['item_id' => $this->cap->id, 'warehouse_id' => $factory->id, 'quantity' => '90000.0000', 'average_cost' => '0.5000']);

        $entryId = $this->startBatch()->assertOk()->json('data.id');

        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => '8100',
            'running_hours' => '8',
            'material_consumptions' => [
                // Neither line names a store — nobody was asked.
                ['item_id' => $this->resin->id, 'quantity_issued_kg' => '105.000'],
                ['item_id' => $this->cap->id, 'quantity_issued_kg' => '8100.000'],
            ],
        ])->assertOk();

        $lines = ShiftProductionEntry::findOrFail($entryId)
            ->materialConsumptions()->get()->keyBy('item_id');

        // Issued from where the material actually IS — a fact in the stock
        // balances, never an item name and never a person's answer.
        $this->assertSame($dayBin->id, $lines[$this->resin->id]->warehouse_id);
        $this->assertSame($factory->id, $lines[$this->cap->id]->warehouse_id);

        // And the balances moved in the places that were named.
        $this->assertSame('395.0000', StockBalance::where('item_id', $this->resin->id)->where('warehouse_id', $dayBin->id)->value('quantity'));
        $this->assertSame('81900.0000', StockBalance::where('item_id', $this->cap->id)->where('warehouse_id', $factory->id)->value('quantity'));
    }

    public function test_a_consumption_line_that_names_its_own_warehouse_is_left_alone(): void
    {
        $factory = $this->soleFactoryWarehouse();
        $dayBin = Warehouse::create(['code' => 'DAY-BIN', 'name' => 'Factory Day Bin', 'is_active' => true]);
        app(FactoryDayBinService::class)->setWarehouseId($dayBin->id);

        // The bin holds resin, so the resolver WOULD have picked the bin.
        StockBalance::create(['item_id' => $this->resin->id, 'warehouse_id' => $dayBin->id, 'quantity' => '500.0000', 'average_cost' => '85.0000']);
        StockBalance::create(['item_id' => $this->resin->id, 'warehouse_id' => $factory->id, 'quantity' => '500.0000', 'average_cost' => '85.0000']);

        $entryId = $this->startBatch()->assertOk()->json('data.id');

        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => '8100',
            'running_hours' => '8',
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'warehouse_id' => $factory->id, 'quantity_issued_kg' => '105.000'],
            ],
        ])->assertOk();

        $line = ShiftProductionEntry::findOrFail($entryId)->materialConsumptions()->firstOrFail();
        $this->assertSame($factory->id, $line->warehouse_id);

        // The bin was NOT touched — an explicit answer wins over the default.
        $this->assertSame('500.0000', StockBalance::where('item_id', $this->resin->id)->where('warehouse_id', $dayBin->id)->value('quantity'));
    }

    /**
     * A consumption line that cannot be resolved declines too, naming its
     * own row — and the whole completion rolls back rather than half-landing.
     * A batch recorded as completed with a material line silently missing
     * would reconcile against Tally as material that vanished.
     */
    public function test_an_unresolvable_consumption_line_is_a_plain_422_and_completes_nothing(): void
    {
        // Two linked warehouses and no settings at all: nothing to resolve
        // a source from. The start still works because it names its store.
        $factory = $this->soleFactoryWarehouse();
        Warehouse::create(['code' => 'FG-2', 'name' => 'Second Store', 'is_active' => true, 'tally_guid' => 'gd-2']);
        StockBalance::create(['item_id' => $this->resin->id, 'warehouse_id' => $factory->id, 'quantity' => '500.0000', 'average_cost' => '85.0000']);

        $entryId = $this->startBatch(['warehouse_id' => $factory->id])->assertOk()->json('data.id');

        $response = $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => '8100',
            'running_hours' => '8',
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'quantity_issued_kg' => '105.000'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['material_consumptions.0.warehouse_id']);

        $this->assertStringContainsString(
            'Production settings',
            $response->json('errors')['material_consumptions.0.warehouse_id'][0],
        );

        // Nothing landed: the batch is still running and no stock moved.
        $entry = ShiftProductionEntry::findOrFail($entryId);
        $this->assertSame('in_progress', $entry->batch_status->value);
        $this->assertSame(0, $entry->materialConsumptions()->count());
        $this->assertSame('500.0000', StockBalance::where('item_id', $this->resin->id)->where('warehouse_id', $factory->id)->value('quantity'));
    }

    /**
     * Handover runs the same completion path, so its consumption lines get
     * the same silent answer — the outgoing supervisor is not asked either.
     */
    public function test_handover_consumption_lines_resolve_without_being_asked(): void
    {
        // The handover route only exists with traceability on (404 otherwise).
        config()->set('production.traceability_enabled', true);

        $factory = $this->soleFactoryWarehouse();
        $dayBin = Warehouse::create(['code' => 'DAY-BIN', 'name' => 'Factory Day Bin', 'is_active' => true]);
        app(FactoryDayBinService::class)->setWarehouseId($dayBin->id);
        StockBalance::create(['item_id' => $this->resin->id, 'warehouse_id' => $dayBin->id, 'quantity' => '500.0000', 'average_cost' => '85.0000']);

        $entryId = $this->startBatch()->assertOk()->json('data.id');
        $evening = Shift::create(['name' => 'Evening', 'start_time' => '14:00', 'end_time' => '22:00']);

        $response = $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/handover", [
            'shift_id' => $evening->id,
            'completion' => [
                'quantity_produced' => '5880',
                'running_hours' => '8',
                'material_consumptions' => [
                    ['item_id' => $this->resin->id, 'quantity_issued_kg' => '75.000'],
                ],
            ],
        ])->assertSuccessful();

        $line = ShiftProductionEntry::findOrFail($entryId)->materialConsumptions()->firstOrFail();
        $this->assertSame($dayBin->id, $line->warehouse_id);

        // The child segment inherits the resolved finished-goods store, so
        // the next shift is not asked the question either.
        $child = ShiftProductionEntry::findOrFail($response->json('data.id'));
        $this->assertSame($factory->id, $child->warehouse_id);
    }

    // ---------------------------------------------------------------
    // The Settings surface the 422 points at
    // ---------------------------------------------------------------

    public function test_production_settings_publishes_and_accepts_the_warehouse_roles(): void
    {
        $factory = $this->soleFactoryWarehouse();
        $rm = Warehouse::create(['code' => 'RM-REAL', 'name' => 'Raw Material', 'is_active' => true]);

        // Nothing configured yet: stored values read as null, while the
        // resolved values show what a payload would actually get today.
        $before = $this->getJson('/api/v1/production/settings')->assertOk();
        $this->assertNull($before->json('data.finished_goods_warehouse_id'));
        $this->assertSame($factory->id, $before->json('data.finished_goods_resolved_warehouse_id'));

        $this->putJson('/api/v1/production/settings/factory-warehouses', [
            'raw_material_warehouse_id' => $rm->id,
        ])->assertOk()->assertJsonPath('data.raw_material_warehouse_id', $rm->id);

        // Setting one role leaves the other exactly as it was.
        $this->assertNull($this->getJson('/api/v1/production/settings')->json('data.finished_goods_warehouse_id'));

        // An inactive warehouse is refused rather than stored as a setting
        // that would silently never take effect.
        $retired = Warehouse::create(['code' => 'OLD', 'name' => 'Retired', 'is_active' => false]);
        $this->putJson('/api/v1/production/settings/factory-warehouses', [
            'finished_goods_warehouse_id' => $retired->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['finished_goods_warehouse_id']);

        // Null clears a role, back to the fallback.
        $this->putJson('/api/v1/production/settings/factory-warehouses', [
            'raw_material_warehouse_id' => null,
        ])->assertOk()->assertJsonPath('data.raw_material_warehouse_id', null);
    }

    // ---------------------------------------------------------------
    // The accountant's books, which is what makes the silence safe
    // ---------------------------------------------------------------

    /**
     * A batch nobody was asked a warehouse question about, all the way to
     * the voucher — the end of the chain this whole class exists to make
     * safe. The silence is only defensible if what it resolves still lands
     * under the ONE godown SWAASHPET's books carry; a resolved warehouse
     * that reached Tally under some other name would be exactly the silent
     * misbooking that made the owner ban the pickers in the first place.
     *
     * GodownAliasingTest already pins the aliasing rules themselves, but it
     * hands the warehouses in explicitly. This starts from the payload the
     * floor actually sends now — no warehouse anywhere — and asserts the
     * join between the two mechanisms holds: FactoryWarehouseResolver picks,
     * TallyGodownResolver translates, and the accountant sees one godown.
     */
    public function test_a_batch_nobody_was_asked_about_still_posts_under_the_one_tally_godown(): void
    {
        $factory = $this->soleFactoryWarehouse();
        // The bin as it really is: an ERP-only child of the company godown,
        // carrying no tally_guid of its own.
        $dayBin = Warehouse::create([
            'code' => 'DAY-BIN', 'name' => 'Factory Day Bin', 'is_active' => true,
            'parent_id' => $factory->id, 'tally_parent_name' => $factory->name,
        ]);
        app(FactoryDayBinService::class)->setWarehouseId($dayBin->id);

        StockBalance::create(['item_id' => $this->resin->id, 'warehouse_id' => $dayBin->id, 'quantity' => '500.0000', 'average_cost' => '85.0000']);
        StockBalance::create(['item_id' => $this->cap->id, 'warehouse_id' => $factory->id, 'quantity' => '90000.0000', 'average_cost' => '0.5000']);

        // Not one warehouse_id in either payload — start or complete.
        $entryId = $this->startBatch()->assertOk()->json('data.id');
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => '8100',
            'running_hours' => '8',
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'quantity_issued_kg' => '105.000'],
                ['item_id' => $this->cap->id, 'quantity_issued_kg' => '8100.000'],
            ],
        ])->assertOk();

        $entry = ShiftProductionEntry::findOrFail($entryId);

        // Two different warehouses were resolved — the bin for the resin,
        // the store for the caps and the finished bottles.
        $this->assertSame($factory->id, $entry->warehouse_id);
        $sources = $entry->materialConsumptions()->get()->keyBy('item_id');
        $this->assertSame($dayBin->id, $sources[$this->resin->id]->warehouse_id);
        $this->assertSame($factory->id, $sources[$this->cap->id]->warehouse_id);

        $payload = app(TallySyncService::class)->buildBatchVoucherPayload($entry);

        // …and every one of them reaches Tally as the single godown the
        // books carry. The voucher-level 'godown' is the resolved
        // finished-goods warehouse, run through TallyGodownResolver.
        $this->assertSame($factory->name, $payload['godown']);
        foreach ($payload['consumed'] as $line) {
            $this->assertSame($factory->name, $line['godown'], "{$line['item']} posted under the wrong godown.");
        }

        // SHAPE FROZEN. The produced line carries no per-line godown on a
        // Manufacturing Journal — the voucher-level 'godown' above is what
        // the agent reads for it. Asserted so that a later "let's also name
        // the godown per produced line" cannot slip in here: the payload
        // shape is the accountant's contract, not ours to widen.
        $this->assertCount(1, $payload['produced']);
        $this->assertSame(['item', 'quantity'], array_keys($payload['produced'][0]));
    }
}
