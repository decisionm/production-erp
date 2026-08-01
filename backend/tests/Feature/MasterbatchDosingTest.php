<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\MasterbatchDosing;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\MasterbatchDosingService;
use App\Modules\Production\Services\ProductionCalculationEngine;
use App\Modules\Production\Services\ShiftProductionEntryService;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * MASTERBATCH DOSING — the factory's 31-Jul figure as master data, and the
 * prefill it produces.
 *
 * The owner's standing rule frames every test here: the supervisor's weighed
 * kg is the truth, and a dosing only ever SUGGESTS. So the suite proves two
 * separate things and must never confuse them:
 *
 *   1. the suggestion is right (the arithmetic, the resolution, the "no
 *      figure means no prefill" case), and
 *   2. the suggestion is powerless — what was submitted is what is stored,
 *      and what is stored is what Tally receives. That second half is the
 *      money line: it is what stops a recipe from quietly becoming a
 *      measurement.
 */
class MasterbatchDosingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The factory's figure (owner, 31-Jul): amber masterbatch, 0.25 grams per
     * bottle. Written once, here, so no test silently invents a second one.
     */
    private const AMBER_GRAMS_PER_BOTTLE = '0.25';

    private Warehouse $rmStore;

    private Warehouse $fg;

    private Item $bottle;

    private Item $resin;

    private Item $amber;

    private Item $white;

    protected function setUp(): void
    {
        parent::setUp();

        // Masterbatch dosing arithmetic is what this suite pins; it approves batches
        // only to reach the posted figures. The quality gate is covered in
        // BatchQualityStageTest.
        config(['production.approvals.quality_stage_enabled' => false]);

        $this->rmStore = Warehouse::create(['code' => 'WH-RM', 'name' => 'Raw Material Store']);
        $this->fg = Warehouse::create(['code' => 'WH-FG', 'name' => 'Finished Goods']);

        // The factory's real item names and units: raw material in Kgs,
        // bottles in Nos.
        $this->bottle = Item::create([
            'sku' => 'BTL-AMBER-1', 'name' => 'Amber Bottle 500ml', 'uom' => 'NOS',
            'nominal_weight_grams' => '12.9000', 'colour' => 'AMBER',
        ]);
        $this->resin = Item::create(['sku' => 'PET-CHIPS', 'name' => 'PET Polyster Chips', 'uom' => 'Kgs']);
        $this->amber = Item::create(['sku' => 'MB-AMBER', 'name' => 'Master Batch Amber', 'uom' => 'Kgs']);
        $this->white = Item::create(['sku' => 'MB-WHITE', 'name' => 'Master Batch - Pet White', 'uom' => 'Kgs']);
    }

    private function actingAsProduction(string $permission = 'production.manage'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
        Sanctum::actingAs($user);

        return $user;
    }

    /** The amber dosing in force, set the way a person would: through the API. */
    private function setAmberDosing(string $grams = self::AMBER_GRAMS_PER_BOTTLE, ?int $productItemId = null): void
    {
        $this->postJson('/api/v1/production/masterbatch-dosings', array_filter([
            'masterbatch_item_id' => $this->amber->id,
            'product_item_id' => $productItemId,
            'grams_per_bottle' => $grams,
            'note' => 'Factory, 31 Jul — owner on WhatsApp.',
        ]))->assertOk();
    }

    /**
     * A stored/serialised kg as a 4dp string, whatever the driver handed back.
     *
     * Needed because the test database is SQLite and the live one is MySQL: a
     * decimal(15,4) column read back from SQLite gives '4', from MySQL
     * '4.0000'. Asserting the raw string would pin the test to the driver
     * rather than to the quantity, and the quantity is the thing that must not
     * move. bcmath, not floats — this is the figure Tally values stock from.
     */
    private function kg(mixed $value): string
    {
        return bcadd((string) $value, '0', 4);
    }

    private function inProgressEntry(): ShiftProductionEntry
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);

        // Enough material in the store for the issue lines to post.
        StockBalance::create(['item_id' => $this->resin->id, 'warehouse_id' => $this->rmStore->id, 'quantity' => '5000.0000', 'average_cost' => '85.0000']);
        StockBalance::create(['item_id' => $this->amber->id, 'warehouse_id' => $this->rmStore->id, 'quantity' => '500.0000', 'average_cost' => '450.0000']);

        return ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => '2026-07-31',
            'batch_number' => '20260731-MC01-001',
            'batch_status' => BatchStatus::InProgress,
            'quantity_produced' => null,
            'quantity_scrap' => '0',
        ]);
    }

    // ---------------------------------------------------------------- (1) the
    // figure is real, editable master data ------------------------------------

    public function test_a_dosing_round_trips_through_the_api(): void
    {
        $this->actingAsProduction();

        // Nothing set yet: an EMPTY list, which is the honest "no figure"
        // answer the floor must render as a blank field.
        $this->getJson('/api/v1/production/masterbatch-dosings')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->postJson('/api/v1/production/masterbatch-dosings', [
            'masterbatch_item_id' => $this->amber->id,
            'grams_per_bottle' => self::AMBER_GRAMS_PER_BOTTLE,
            'note' => 'Factory, 31 Jul — owner on WhatsApp.',
        ])
            ->assertOk()
            ->assertJsonPath('data.grams_per_bottle', '0.2500')
            ->assertJsonPath('data.masterbatch_item.name', 'Master Batch Amber')
            // Factory-wide until the factory says a bottle differs.
            ->assertJsonPath('data.product_item', null)
            ->assertJsonPath('data.scope', 'factory')
            // Provenance travels with the figure: who said it and who typed
            // it. A dosing nobody can attribute is one nobody can defend when
            // the variance is questioned weeks later.
            ->assertJsonPath('data.note', 'Factory, 31 Jul — owner on WhatsApp.')
            ->assertJsonPath('data.set_by', auth()->user()->name);

        // Read back on a fresh request: persisted, not per-request state.
        $this->getJson('/api/v1/production/masterbatch-dosings')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.grams_per_bottle', '0.2500')
            // No bottle count asked for, so no kg quoted — a kg with nothing
            // behind it is a figure the supervisor cannot check.
            ->assertJsonPath('data.0.suggested_kg', null);
    }

    public function test_correcting_the_figure_replaces_it_instead_of_stacking_a_second_row(): void
    {
        $this->actingAsProduction();
        $this->setAmberDosing();

        $this->postJson('/api/v1/production/masterbatch-dosings', [
            'masterbatch_item_id' => $this->amber->id,
            'grams_per_bottle' => '0.3',
            'note' => 'Factory revised, 5 Aug.',
        ])->assertOk()->assertJsonPath('data.grams_per_bottle', '0.3000');

        // One row, not two. Two rows for one material would leave the floor's
        // prefill depending on which one a query happened to find first.
        $this->assertSame(1, MasterbatchDosing::query()->count());
        $this->getJson('/api/v1/production/masterbatch-dosings')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.grams_per_bottle', '0.3000');
    }

    public function test_a_product_scoped_dosing_beats_the_factory_wide_one_for_that_bottle(): void
    {
        $this->actingAsProduction();
        $this->setAmberDosing();
        $this->setAmberDosing('0.4', productItemId: $this->bottle->id);

        $other = Item::create(['sku' => 'BTL-2', 'name' => 'Amber Bottle 1L', 'uom' => 'NOS']);

        // This bottle has its own figure.
        $this->getJson("/api/v1/production/masterbatch-dosings?item_id={$this->bottle->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.grams_per_bottle', '0.4000')
            ->assertJsonPath('data.0.scope', 'product');

        // Every other bottle still gets the factory-wide figure.
        $this->getJson("/api/v1/production/masterbatch-dosings?item_id={$other->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.grams_per_bottle', '0.2500')
            ->assertJsonPath('data.0.scope', 'factory');
    }

    public function test_zero_negative_and_over_precise_figures_are_refused(): void
    {
        $this->actingAsProduction();

        foreach (['0', '-0.25'] as $bad) {
            $this->postJson('/api/v1/production/masterbatch-dosings', [
                'masterbatch_item_id' => $this->amber->id,
                'grams_per_bottle' => $bad,
            ])->assertStatus(422)->assertJsonValidationErrors('grams_per_bottle');
        }

        // 5 decimal places would be silently rounded by the decimal(12,4)
        // column. Questioned, not stored behind the typist's back.
        $this->postJson('/api/v1/production/masterbatch-dosings', [
            'masterbatch_item_id' => $this->amber->id,
            'grams_per_bottle' => '0.25001',
        ])->assertStatus(422)->assertJsonValidationErrors('grams_per_bottle');

        // A dosing against the BOTTLE (Nos) would compute kg of bottles per
        // bottle. kg-family uom is this database's only raw-material signal.
        $this->postJson('/api/v1/production/masterbatch-dosings', [
            'masterbatch_item_id' => $this->bottle->id,
            'grams_per_bottle' => '0.25',
        ])->assertStatus(422)->assertJsonValidationErrors('masterbatch_item_id');

        $this->assertSame(0, MasterbatchDosing::query()->count());
    }

    public function test_reading_the_dosing_needs_only_view_but_setting_it_needs_manage(): void
    {
        // A supervisor with production.view must be able to SEE what dosing
        // applies — it drives the field in front of them.
        $this->actingAsProduction('production.view');

        $this->getJson('/api/v1/production/masterbatch-dosings')->assertOk();

        // ...but changing the factory's figure is a manage action.
        $this->postJson('/api/v1/production/masterbatch-dosings', [
            'masterbatch_item_id' => $this->amber->id,
            'grams_per_bottle' => '0.25',
        ])->assertForbidden();

        $this->assertSame(0, MasterbatchDosing::query()->count());
    }

    // ------------------------------------------------------- (2) the
    // arithmetic ---------------------------------------------------------------

    public function test_kg_for_a_known_bottle_count_is_exact_to_four_decimals(): void
    {
        // 13,333 bottles × 0.25 g = 3,333.25 g = 3.33325 kg → 3.3333 kg at
        // the 4dp the reconciliation and the Tally quantity both carry.
        //
        // WHAT A WRONG ROUNDING WOULD COST, in order of how much it matters:
        //
        //  - grams read as kg (0.25 kg/bottle) gives 3,333.25 kg instead of
        //    3.3333 kg — a 1,000× error that would post a lorry-load of
        //    masterbatch to Tally per shift and empty the stock ledger. This
        //    is the reason the ÷1000 lives in ONE method.
        //  - truncating instead of rounding half-up gives 3.3332 kg: 0.0001
        //    kg light. Trivial per shift, but it is light EVERY shift and in
        //    the same direction, so it shows as a permanent phantom shortage
        //    in the material variance the accountant is asked to explain.
        //  - rounding to 2dp (3.33 kg) loses 0.0033 kg a shift — ~2.4 kg a
        //    year at three shifts a day, on a material bought by the kg.
        $engine = app(ProductionCalculationEngine::class);
        $this->assertSame('3.3333', $engine->masterbatchKg(13333, '0.2500'));

        // Not the truncated figure, explicitly — the two differ only on this
        // kind of exact-half input, which is why it is pinned.
        $this->assertNotSame('3.3332', $engine->masterbatchKg(13333, '0.2500'));

        // A round count with no half to break: 10,000 × 0.25 g = 2.5 kg.
        $this->assertSame('2.5000', $engine->masterbatchKg(10000, '0.2500'));

        // And the same figure through the API the floor actually reads.
        $this->actingAsProduction();
        $this->setAmberDosing();

        $this->getJson("/api/v1/production/masterbatch-dosings?masterbatch_item_id={$this->amber->id}&quantity_produced=13333")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.grams_per_bottle', '0.2500')
            ->assertJsonPath('data.0.suggested_kg', '3.3333')
            // The basis travels with the figure so the screen can say
            // "3.3333 kg for 13,333 bottles" rather than showing a bare kg.
            ->assertJsonPath('data.0.bottles', 13333);
    }

    public function test_no_dosing_set_means_null_and_never_a_zero_prefill(): void
    {
        $this->actingAsProduction();
        $this->setAmberDosing();

        $dosings = app(MasterbatchDosingService::class);

        // White has no figure. Null, not 0.0000 — a zero would tell the floor
        // the factory has said white bottles need no masterbatch, and nobody
        // has said that.
        $this->assertNull($dosings->suggestionFor($this->white->id, $this->bottle->id, 13333));
        $this->assertNull(app(ProductionCalculationEngine::class)->masterbatchKg(13333, null));

        $this->getJson("/api/v1/production/masterbatch-dosings?masterbatch_item_id={$this->white->id}&quantity_produced=13333")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // The list for this product carries amber and ONLY amber: one factory
        // figure must never be copied across the other masterbatches.
        $this->getJson("/api/v1/production/masterbatch-dosings?item_id={$this->bottle->id}&quantity_produced=13333")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.masterbatch_item.id', $this->amber->id);
    }

    public function test_withdrawing_a_dosing_returns_the_floor_to_no_prefill(): void
    {
        $this->actingAsProduction();
        $this->setAmberDosing();
        $dosing = MasterbatchDosing::query()->sole();

        $this->deleteJson("/api/v1/production/masterbatch-dosings/{$dosing->id}")->assertOk();

        $this->getJson('/api/v1/production/masterbatch-dosings?quantity_produced=13333')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Soft-deleted, not erased: shifts prefilled from it while it applied
        // stay explainable.
        $this->assertSoftDeleted('masterbatch_dosings', ['id' => $dosing->id]);
    }

    // ------------- the deploy-time seed: amber only, and only if it exists ---

    /**
     * Re-runs the data migration against items that exist NOW. Under
     * RefreshDatabase the items table is empty when migrations run, so the
     * seed is a no-op during the normal test boot — which is exactly why it
     * has to be exercised deliberately here. Without this, the one line that
     * puts the factory's figure on a live server is never executed by any
     * test.
     */
    private function runAmberSeedMigration(): void
    {
        $migration = require database_path('migrations/2026_07_31_090002_seed_amber_masterbatch_dosing.php');
        $migration->up();
    }

    public function test_the_deploy_seed_sets_amber_only_and_leaves_the_other_masterbatches_unset(): void
    {
        $this->runAmberSeedMigration();

        $dosing = MasterbatchDosing::query()->sole();
        $this->assertSame($this->amber->id, (int) $dosing->masterbatch_item_id);
        $this->assertSame('0.2500', (string) $dosing->grams_per_bottle);
        // Factory-wide, because "0.25 per bottle" was said about the material,
        // not about one bottle.
        $this->assertNull($dosing->product_item_id);
        // The note names the source. The figure's authority is who said it.
        $this->assertStringContainsString('Factory', (string) $dosing->note);
        $this->assertStringContainsString('31 Jul 2026', (string) $dosing->note);
        // Nobody set it in the app, so no user is credited with having done so.
        $this->assertNull($dosing->set_by);

        // White exists and is deliberately left with NO figure: copying 0.25
        // across the other masterbatches would invent two factory figures from
        // one, which is what the owner forbade.
        $this->assertSame(0, MasterbatchDosing::query()->where('masterbatch_item_id', $this->white->id)->count());

        // Idempotent: a re-run (migrate:fresh, a re-deploy) does not stack a
        // second row.
        $this->runAmberSeedMigration();
        $this->assertSame(1, MasterbatchDosing::query()->count());
    }

    public function test_the_deploy_seed_does_nothing_when_no_amber_item_exists(): void
    {
        // An instance that spells the item differently must be able to enter
        // the figure in the app — a migration guessing which masterbatch was
        // meant is worse than no row at all.
        $this->amber->forceDelete();

        $this->runAmberSeedMigration();

        $this->assertSame(0, MasterbatchDosing::query()->count());
    }

    // ------------------- (3) the completion preview the floor already reads ---

    public function test_the_batch_preview_carries_the_dosing_and_its_kg_for_the_counted_bottles(): void
    {
        $this->actingAsProduction();
        $this->setAmberDosing();

        // The completion drawer calls this very endpoint (it is where the
        // standard's packing modes come from), passing what has been counted.
        $this->getJson("/api/v1/production/shift-production-entries/preview?item_id={$this->bottle->id}&quantity_produced=13333")
            ->assertOk()
            ->assertJsonCount(1, 'data.masterbatch_dosing')
            ->assertJsonPath('data.masterbatch_dosing.0.masterbatch_item.id', $this->amber->id)
            ->assertJsonPath('data.masterbatch_dosing.0.grams_per_bottle', '0.2500')
            ->assertJsonPath('data.masterbatch_dosing.0.suggested_kg', '3.3333')
            ->assertJsonPath('data.masterbatch_dosing.0.bottles', 13333);

        // With no dosing at all the block is an empty list, so the screen has
        // nothing to prefill from and leaves the field blank.
        MasterbatchDosing::query()->delete();
        $this->getJson("/api/v1/production/shift-production-entries/preview?item_id={$this->bottle->id}&quantity_produced=13333")
            ->assertOk()
            ->assertJsonCount(0, 'data.masterbatch_dosing');
    }

    // -------------------------------- (4) the suggestion is POWERLESS --------

    public function test_a_submitted_masterbatch_kg_is_stored_verbatim_even_when_it_contradicts_the_suggestion(): void
    {
        $this->actingAsProduction();
        $this->setAmberDosing();
        $entry = $this->inProgressEntry();

        // The suggestion for 13,333 bottles is 3.3333 kg. The supervisor
        // weighed 4.0000 kg. The submitted figure is DELIBERATELY different:
        // submitting the suggestion back would pass this test vacuously and
        // keep passing if someone later added a server-side prefill.
        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '13333',
            'running_hours' => 8,
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'warehouse_id' => $this->rmStore->id, 'quantity_issued_kg' => '172.0000'],
                ['item_id' => $this->amber->id, 'warehouse_id' => $this->rmStore->id, 'quantity_issued_kg' => '4.0000'],
            ],
        ])->assertOk();

        $stored = $entry->fresh()->materialConsumptions
            ->firstWhere('item_id', $this->amber->id);

        $this->assertNotNull($stored);
        $this->assertSame('4.0000', $this->kg($stored->quantity_issued_kg));
        // The suggestion did not land anywhere near the stored row.
        $this->assertNotSame('3.3333', $this->kg($stored->quantity_issued_kg));

        // And no extra line was invented from the dosing: two lines in, two
        // lines stored.
        $this->assertSame(2, $entry->fresh()->materialConsumptions->count());
    }

    public function test_completing_with_no_masterbatch_line_stores_no_masterbatch_at_all(): void
    {
        $this->actingAsProduction();
        $this->setAmberDosing();
        $entry = $this->inProgressEntry();

        // A dosing exists, and the supervisor submitted resin only. The
        // server must NOT helpfully add the colour: an unweighed 3.3333 kg
        // would be a recipe posing as a measurement, and it would reduce
        // Tally stock for material nobody confirmed went in.
        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '13333',
            'running_hours' => 8,
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'warehouse_id' => $this->rmStore->id, 'quantity_issued_kg' => '172.0000'],
            ],
        ])->assertOk();

        $consumptions = $entry->fresh()->materialConsumptions;
        $this->assertSame(1, $consumptions->count());
        $this->assertNull($consumptions->firstWhere('item_id', $this->amber->id));
    }

    public function test_the_tally_voucher_carries_the_submitted_masterbatch_kg_not_the_suggestion(): void
    {
        // THE MONEY LINE. Tally deducts stock and values it from this
        // quantity. If a suggestion could ever reach the voucher, the
        // factory's books would be reduced by a figure nobody weighed.
        $approver = $this->actingAsProduction();
        $this->setAmberDosing();
        $entry = $this->inProgressEntry();

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '13333',
            'running_hours' => 8,
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'warehouse_id' => $this->rmStore->id, 'quantity_issued_kg' => '172.0000'],
                ['item_id' => $this->amber->id, 'warehouse_id' => $this->rmStore->id, 'quantity_issued_kg' => '4.0000'],
            ],
        ])->assertOk();

        $service = app(ShiftProductionEntryService::class);
        // Four-eyes: the accountant gate refuses the PM's own account.
        $accountant = User::factory()->create();
        $service->pmApprove($entry->fresh(), $approver->id);
        $service->accountantApprove($entry->fresh(), $accountant->id);

        $voucher = TallySyncEntry::query()->sole();
        $amberLine = collect($voucher->payload['consumed'])->firstWhere('item', 'Master Batch Amber');

        $this->assertNotNull($amberLine, 'The voucher must carry the masterbatch the supervisor submitted');
        $this->assertSame('4.0000', $this->kg($amberLine['quantity']));
        $this->assertNotSame('3.3333', $this->kg($amberLine['quantity']));

        // The whole consumed side is the submitted set, in the submitted
        // quantities — nothing added, nothing recalculated.
        $this->assertSame(
            ['PET Polyster Chips' => '172.0000', 'Master Batch Amber' => '4.0000'],
            collect($voucher->payload['consumed'])
                ->mapWithKeys(fn (array $line) => [$line['item'] => $this->kg($line['quantity'])])
                ->all(),
        );
    }
}
