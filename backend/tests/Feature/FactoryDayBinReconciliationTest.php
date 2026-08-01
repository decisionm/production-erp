<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Services\FactoryDayBinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE DAY-BIN RECONCILIATION — the owner's replacement for the per-batch
 * "unaccounted kg" figure.
 *
 * The owner's instruction, in his words: "I do not want Unaccounted kg shown
 * for each batch. We do not separately measure or issue a fixed quantity of
 * resin to each machine. Resin consumption is calculated as good production
 * kg + rejection kg + lumps kg, so the per-batch unaccounted figure will
 * normally be zero and only causes confusion. ... If we need to identify
 * missing material, check it at the central day-bin level by comparing the
 * opening balance, material loaded, total batch consumption, and actual
 * closing balance."
 *
 * So what has to be proved here is the CENTRAL arithmetic, and one thing
 * about it that is easy to get wrong and impossible to notice:
 *
 *  (a) a scripted day comes out at opening + loaded − consumed,
 *  (b) a PAST date is computed from history — and is NOT disturbed by
 *      movements that happened after it. This is the assertion with teeth:
 *      an implementation that filters "movements ON the date" instead of
 *      rolling the balance back from "movements AT OR AFTER the date"
 *      passes every other test in this file and silently reports today's
 *      opening balance as yesterday's,
 *  (c) a date with nothing to say about a material omits it,
 *  (d) every figure is a 4dp string, never a float,
 *  (e) material that left the bin by neither route is surfaced, not
 *      silently miscounted as consumption.
 *
 * Every fixture below is built from REAL stock movements, never a
 * hand-written StockBalance row: opening is DERIVED by rolling the ledger
 * back off the current balance, so a balance that disagrees with the ledger
 * would make the whole exercise meaningless.
 */
class FactoryDayBinReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $dayBin;

    private Warehouse $store;

    private Item $resin;

    private Item $masterbatch;

    private StockMovementService $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Warehouse::create(['code' => 'WH-RM', 'name' => 'Raw Material Store']);
        $this->dayBin = Warehouse::create(['code' => 'WH-DAYBIN', 'name' => 'Factory Day Bin']);

        $this->resin = Item::create(['sku' => 'PET-RESIN', 'name' => 'PET Resin', 'uom' => 'KGS']);
        $this->masterbatch = Item::create(['sku' => 'MB-BLUE', 'name' => 'Masterbatch Blue', 'uom' => 'KGS']);

        $this->stock = app(StockMovementService::class);

        app(FactoryDayBinService::class)->setWarehouseId($this->dayBin->id);

        // Plenty in the store to load from, banked well before any date
        // under test so it never lands inside a reconciliation window.
        $this->stock->recordReceipt(
            itemId: $this->resin->id,
            warehouseId: $this->store->id,
            quantity: '5000.0000',
            unitCost: '85.0000',
            movementDate: '2026-07-01 09:00:00',
        );
    }

    private function actingAsProduction(string $permission = 'production.view'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
        Sanctum::actingAs($user);

        return $user;
    }

    /** A load: the ordinary store → bin stock transfer, exactly as the floor does it. */
    private function load(string $quantity, string $at, ?Item $item = null): void
    {
        $this->stock->recordTransfer(
            itemId: ($item ?? $this->resin)->id,
            fromWarehouseId: $this->store->id,
            toWarehouseId: $this->dayBin->id,
            quantity: $quantity,
            reference: 'Day bin load',
            movementDate: $at,
        );
    }

    /** A batch's consumption line: an issue out of the bin, referenced "SPE #n". */
    private function batchIssue(string $quantity, string $at, string $reference, ?Item $item = null): void
    {
        $this->stock->recordIssue(
            itemId: ($item ?? $this->resin)->id,
            warehouseId: $this->dayBin->id,
            quantity: $quantity,
            reference: $reference,
            movementDate: $at,
        );
    }

    /**
     * The owner's scripted day, on 2026-07-30:
     * opening 100 (loaded the day before and never touched), two loads
     * (50 + 25 = 75), three batch issues (40 + 30 + 20 = 90).
     */
    private function scriptTheDay(): void
    {
        $this->load('100.0000', '2026-07-29 08:00:00');

        $this->load('50.0000', '2026-07-30 07:00:00');
        $this->batchIssue('40.0000', '2026-07-30 08:30:00', 'SPE #1');
        $this->load('25.0000', '2026-07-30 11:00:00');
        $this->batchIssue('30.0000', '2026-07-30 14:00:00', 'SPE #2');
        $this->batchIssue('20.0000', '2026-07-30 21:00:00', 'SPE #3');
    }

    private function binBalance(?Item $item = null): string
    {
        return (string) StockBalance::query()
            ->where('item_id', ($item ?? $this->resin)->id)
            ->where('warehouse_id', $this->dayBin->id)
            ->value('quantity');
    }

    private function resinRow(array $materials): array
    {
        $row = collect($materials)->firstWhere('item_id', $this->resin->id);

        $this->assertNotNull($row, 'The resin must be listed on a date it moved.');

        return $row;
    }

    // (a) the scripted day ----------------------------------------------------

    public function test_the_scripted_day_reconciles_to_opening_plus_loaded_minus_consumed(): void
    {
        $this->travelTo('2026-07-30 23:00:00');
        $this->actingAsProduction();
        $this->scriptTheDay();

        // The fixture is honest: the ledger really did leave 85 kg in the bin.
        $this->assertSame('85.0000', $this->binBalance());

        // No ?date — today, which is the day just scripted.
        $response = $this->getJson('/api/v1/production/factory-day-bin/reconciliation')->assertOk();

        $response->assertJsonPath('data.date', '2026-07-30');
        $response->assertJsonPath('data.warehouse.id', $this->dayBin->id);

        $row = $this->resinRow($response->json('data.materials'));

        $this->assertSame('100.0000', $row['opening_kg']);
        $this->assertSame('75.0000', $row['loaded_kg']);
        $this->assertSame('90.0000', $row['consumed_kg']);
        $this->assertSame('85.0000', $row['expected_closing_kg']);
        $this->assertSame('0.0000', $row['other_movements_kg']);
    }

    public function test_for_today_expected_closing_equals_the_live_balance_by_construction(): void
    {
        // Stated as a test so the tautology is on the record rather than
        // mistaken for a check: opening was derived by subtracting the very
        // movements added back here, so these two CANNOT disagree. The real
        // check is the physical count, which the server never sees.
        $this->travelTo('2026-07-30 23:00:00');
        $this->actingAsProduction();
        $this->scriptTheDay();

        $row = $this->resinRow(
            $this->getJson('/api/v1/production/factory-day-bin/reconciliation')->assertOk()->json('data.materials')
        );

        $this->assertSame($this->binBalance(), $row['expected_closing_kg']);

        // And the endpoint offers no actual/physical figure of its own — the
        // count is a person's, collected and compared by the frontend.
        $this->assertArrayNotHasKey('actual_closing_kg', $row);
        $this->assertArrayNotHasKey('unaccounted_kg', $row);
    }

    // (b) a past date, computed from history ----------------------------------

    public function test_a_past_date_is_computed_from_history_and_is_not_disturbed_by_later_movements(): void
    {
        // THE ASSERTION WITH TEETH. It is now the 31st. The 30th was the
        // scripted day; the 31st has already seen a big load and an issue.
        // Yesterday's four figures must be exactly what they were yesterday.
        $this->travelTo('2026-07-31 09:00:00');
        $this->actingAsProduction();
        $this->scriptTheDay();

        $this->load('200.0000', '2026-07-31 06:00:00');
        $this->batchIssue('60.0000', '2026-07-31 08:00:00', 'SPE #4');

        // The bin now holds 85 + 200 − 60 = 225. Rolling THAT back to the
        // 30th's opening is the whole job.
        $this->assertSame('225.0000', $this->binBalance());

        $yesterday = $this->resinRow(
            $this->getJson('/api/v1/production/factory-day-bin/reconciliation?date=2026-07-30')
                ->assertOk()
                ->assertJsonPath('data.date', '2026-07-30')
                ->json('data.materials')
        );

        $this->assertSame('100.0000', $yesterday['opening_kg']);
        $this->assertSame('75.0000', $yesterday['loaded_kg']);
        $this->assertSame('90.0000', $yesterday['consumed_kg']);
        $this->assertSame('85.0000', $yesterday['expected_closing_kg']);

        // And today picks up where yesterday closed — the two days chain.
        $today = $this->resinRow(
            $this->getJson('/api/v1/production/factory-day-bin/reconciliation?date=2026-07-31')
                ->assertOk()
                ->json('data.materials')
        );

        $this->assertSame('85.0000', $today['opening_kg']);
        $this->assertSame('200.0000', $today['loaded_kg']);
        $this->assertSame('60.0000', $today['consumed_kg']);
        $this->assertSame('225.0000', $today['expected_closing_kg']);
    }

    public function test_a_day_the_material_only_sat_in_the_bin_reports_the_carried_balance(): void
    {
        // Nothing loaded, nothing consumed, but 85 kg is in there — that is a
        // reconciliation line, not an absence. Omitting it would quietly drop
        // the material a physical count is about to find.
        $this->travelTo('2026-08-02 09:00:00');
        $this->actingAsProduction();
        $this->scriptTheDay();

        $row = $this->resinRow(
            $this->getJson('/api/v1/production/factory-day-bin/reconciliation?date=2026-07-31')
                ->assertOk()
                ->json('data.materials')
        );

        $this->assertSame('85.0000', $row['opening_kg']);
        $this->assertSame('0.0000', $row['loaded_kg']);
        $this->assertSame('0.0000', $row['consumed_kg']);
        $this->assertSame('85.0000', $row['expected_closing_kg']);
    }

    // (c) an empty date omits the material ------------------------------------

    public function test_a_date_with_no_movements_and_no_balance_omits_the_material(): void
    {
        $this->travelTo('2026-07-30 23:00:00');
        $this->actingAsProduction();
        $this->scriptTheDay();

        // Before the resin ever reached the bin: no opening, no movement.
        $response = $this->getJson('/api/v1/production/factory-day-bin/reconciliation?date=2026-06-01')
            ->assertOk()
            ->assertJsonPath('data.date', '2026-06-01');

        $this->assertSame([], $response->json('data.materials'));
    }

    public function test_a_raw_material_that_never_reached_the_bin_is_omitted_even_on_a_busy_day(): void
    {
        // Unlike the day-bin SUMMARY read — which lists every active kg item
        // at zero, because you cannot load what you cannot see — the
        // reconciliation lists only what actually moved or sat. An all-zero
        // row here is noise on a page whose one job is to make a real gap
        // visible.
        $this->travelTo('2026-07-30 23:00:00');
        $this->actingAsProduction();
        $this->scriptTheDay();

        $materials = $this->getJson('/api/v1/production/factory-day-bin/reconciliation')
            ->assertOk()
            ->json('data.materials');

        $this->assertCount(1, $materials);
        $this->assertSame($this->resin->id, $materials[0]['item_id']);
        $this->assertFalse(
            collect($materials)->contains('item_id', $this->masterbatch->id),
            'A material with no bin activity must not pad the reconciliation.'
        );
    }

    public function test_several_materials_are_listed_item_name_ordered(): void
    {
        $this->travelTo('2026-07-30 23:00:00');
        $this->actingAsProduction();
        $this->scriptTheDay();

        $this->stock->recordReceipt(
            itemId: $this->masterbatch->id,
            warehouseId: $this->store->id,
            quantity: '100.0000',
            unitCost: '210.0000',
            movementDate: '2026-07-01 09:00:00',
        );
        $this->load('12.5000', '2026-07-30 07:30:00', $this->masterbatch);
        $this->batchIssue('2.2500', '2026-07-30 15:00:00', 'SPE #2', $this->masterbatch);

        $materials = $this->getJson('/api/v1/production/factory-day-bin/reconciliation')
            ->assertOk()
            ->json('data.materials');

        // "Masterbatch Blue" before "PET Resin".
        $this->assertCount(2, $materials);
        $this->assertSame($this->masterbatch->id, $materials[0]['item_id']);
        $this->assertSame($this->resin->id, $materials[1]['item_id']);

        $this->assertSame('0.0000', $materials[0]['opening_kg']);
        $this->assertSame('12.5000', $materials[0]['loaded_kg']);
        $this->assertSame('2.2500', $materials[0]['consumed_kg']);
        $this->assertSame('10.2500', $materials[0]['expected_closing_kg']);
    }

    // (d) 4dp strings ---------------------------------------------------------

    public function test_every_figure_is_a_four_decimal_string_never_a_float(): void
    {
        $this->travelTo('2026-07-30 23:00:00');
        $this->actingAsProduction();

        $this->load('100.0000', '2026-07-29 08:00:00');
        $this->load('0.5000', '2026-07-30 07:00:00');
        $this->batchIssue('0.2500', '2026-07-30 09:00:00', 'SPE #1');

        $row = $this->resinRow(
            $this->getJson('/api/v1/production/factory-day-bin/reconciliation')->assertOk()->json('data.materials')
        );

        foreach (['opening_kg', 'loaded_kg', 'consumed_kg', 'expected_closing_kg', 'other_movements_kg'] as $field) {
            $this->assertIsString($row[$field], "{$field} must be a string, not a float.");
            $this->assertMatchesRegularExpression('/^-?\d+\.\d{4}$/', $row[$field], "{$field} must carry 4 decimals.");
        }

        $this->assertSame('100.2500', $row['expected_closing_kg']);
    }

    // (e) material that left by neither route ---------------------------------

    public function test_a_transfer_back_out_to_the_store_is_surfaced_not_counted_as_consumption(): void
    {
        // Returning unused resin to the store is one form field away from
        // happening (it is the same transfer endpoint the Day Bin page posts
        // to). It is not batch consumption, so counting it as consumed would
        // inflate the exact figure the owner reads against batch output —
        // and dropping it silently would make the physical count look wrong
        // for a reason the screen could not explain.
        $this->travelTo('2026-07-30 23:00:00');
        $this->actingAsProduction();
        $this->scriptTheDay();

        $this->stock->recordTransfer(
            itemId: $this->resin->id,
            fromWarehouseId: $this->dayBin->id,
            toWarehouseId: $this->store->id,
            quantity: '15.0000',
            reference: 'Returned to store at day end',
            movementDate: '2026-07-30 22:00:00',
        );

        $row = $this->resinRow(
            $this->getJson('/api/v1/production/factory-day-bin/reconciliation')->assertOk()->json('data.materials')
        );

        $this->assertSame('90.0000', $row['consumed_kg'], 'A return to the store is not batch consumption.');
        $this->assertSame('-15.0000', $row['other_movements_kg']);
        $this->assertSame('85.0000', $row['expected_closing_kg']);

        // The full identity: live = expected_closing + other_movements.
        $this->assertSame('70.0000', $this->binBalance());
        $this->assertSame(
            $this->binBalance(),
            bcadd($row['expected_closing_kg'], $row['other_movements_kg'], 4)
        );
    }

    // the ordinary endpoint manners -------------------------------------------

    public function test_with_no_bin_configured_it_answers_null_not_an_error(): void
    {
        $this->travelTo('2026-07-30 23:00:00');
        $this->actingAsProduction();
        app(FactoryDayBinService::class)->setWarehouseId(null);

        $response = $this->getJson('/api/v1/production/factory-day-bin/reconciliation')
            ->assertOk()
            ->assertJsonPath('data.warehouse', null)
            ->assertJsonPath('data.date', '2026-07-30');

        $this->assertSame([], $response->json('data.materials'));
    }

    public function test_a_malformed_date_is_refused_rather_than_silently_rolled_over(): void
    {
        $this->actingAsProduction();

        // Not a date at all.
        $this->getJson('/api/v1/production/factory-day-bin/reconciliation?date=yesterday')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date']);

        // Looks like one, but there is no 13th month — answering with
        // confident figures for February 2027 would be worse than refusing.
        $this->getJson('/api/v1/production/factory-day-bin/reconciliation?date=2026-13-45')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date']);

        // Not the wrong shape of the right idea either.
        $this->getJson('/api/v1/production/factory-day-bin/reconciliation?date=30-07-2026')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date']);
    }

    public function test_the_reconciliation_is_visible_to_view_only_staff(): void
    {
        // It is a read — the supervisor who takes the physical count needs it.
        $this->travelTo('2026-07-30 23:00:00');
        $this->actingAsProduction('production.view');
        $this->scriptTheDay();

        $this->getJson('/api/v1/production/factory-day-bin/reconciliation')
            ->assertOk()
            ->assertJsonPath('data.materials.0.opening_kg', '100.0000');
    }

    public function test_it_is_closed_to_staff_without_the_production_module(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('inventory.view', 'web');
        $user->givePermissionTo('inventory.view');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/production/factory-day-bin/reconciliation')->assertForbidden();
    }
}
