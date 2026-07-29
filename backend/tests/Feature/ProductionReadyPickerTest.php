<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ProductReadinessService;
use App\Modules\Production\Services\ShiftProductionEntryService;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Start Batch must not present an unconfigured product as production-ready.
 *
 * The failure this covers: a supervisor opens Start Batch, picks an old
 * demo/Tally master out of one flat list of every active item, and is handed
 * a wall of missing masters — weight, cycle time, cavities, packing, Tally
 * mapping, colour, recipe — for a product nobody ever set up. The list itself
 * was the bug: it made a legacy master and a fully-standardised product look
 * identical, and the supervisor only learned the difference after choosing.
 *
 * Three things are asserted here, and they are separate concerns that the old
 * code ran together:
 *
 *  1. WHICH products are set up (a factory standard exists) — the split the
 *     picker groups on.
 *  2. WHICH missing masters are real. A LOCAL- fixture has no Tally GUID by
 *     construction; reporting that as a gap is a false alarm on nearly every
 *     item, and false alarms are how the whole readiness panel stops being
 *     read. A REAL item missing its GUID is still voucher-fatal and must
 *     still fail.
 *  3. That a local fixture's approval never queues a Tally voucher, because
 *     Tally would reject it — the accountant must not be handed a permanently
 *     failing row for a product that was never meant to reach them.
 *
 * Plus colour, which the factory workbook has no reliable column for: when
 * the masters cannot answer it, the supervisor is asked and their answer is
 * frozen onto the run.
 */
class ProductionReadyPickerTest extends TestCase
{
    use RefreshDatabase;

    private Shift $shift;

    private WorkCenter $machine;

    private Warehouse $fgStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'is_active' => true]);
        $this->fgStore = Warehouse::create([
            'code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg-0001',
        ]);
    }

    private function actAsSupervisor(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.manage', 'web');
        Permission::findOrCreate('production.view', 'web');
        $user->givePermissionTo(['production.manage', 'production.view']);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * A LOCAL- fixture exactly as the product-master import fabricates one:
     * a real product the factory runs, whose Tally item does not exist yet,
     * so every figure lives on the standard and the GUID is deliberately
     * absent.
     */
    private function localFixtureItem(): Item
    {
        $item = Item::create([
            'sku' => 'LOCAL-1000ML-OVAL',
            'name' => '1000ML OVAL (LOCAL FIXTURE)',
            'uom' => 'Nos.',
            'is_active' => true,
            'tally_stock_item_guid' => null,
        ]);

        $standard = ProductionStandard::create([
            'item_id' => $item->id,
            'source_product_name' => '1000ML OVAL',
            'cavities' => 2,
            'unit_weight_grams' => '41.5000',
            'cycle_time' => '18.50',
            'status' => 'draft',
        ]);

        ProductionStandardPackaging::create([
            'production_standard_id' => $standard->id,
            'mode' => 'tray',
            'nos_per_tray' => 42,
            'nos_per_box' => 84,
            'is_default' => true,
        ]);

        return $item->fresh();
    }

    /** A real Tally-sourced product with no factory standard behind it. */
    private function unconfiguredItem(): Item
    {
        return Item::create([
            'sku' => 'BTL-1L-OVEL',
            'name' => '1 Litre Pet Bottle - Ovel',
            'uom' => 'Nos.',
            'is_active' => true,
            'tally_stock_item_guid' => 'itm-legacy-0001',
        ]);
    }

    // -----------------------------------------------------------------
    // 1. Which products the picker may present as ready
    // -----------------------------------------------------------------

    public function test_the_picker_reports_a_product_with_a_standard_as_ready_and_one_without_as_not(): void
    {
        $this->actAsSupervisor();
        $configured = $this->localFixtureItem();
        $legacy = $this->unconfiguredItem();

        $response = $this->getJson('/api/v1/production/standards/coverage')->assertOk();

        $coveredIds = array_column($response->json('data'), 'item_id');

        $this->assertContains(
            $configured->id,
            $coveredIds,
            'A product with a factory standard belongs in the "Production ready" group.',
        );
        $this->assertNotContains(
            $legacy->id,
            $coveredIds,
            'A legacy master with no standard must never be presented as production-ready.',
        );

        // The product name rides along so the picker can offer a configured
        // product of the SAME name as a replacement — the legacy-master case.
        $this->assertSame('1000ML OVAL', $response->json('data.0.source_product_name'));
    }

    public function test_coverage_lists_each_configured_product_once_however_many_variants_it_has(): void
    {
        // 500ML ROUND ships four generated variants (two cycle times × two
        // weights). The picker asks a yes/no question, so four rows for one
        // product would be four times the payload for the same one bit.
        $this->actAsSupervisor();
        $item = $this->localFixtureItem();

        foreach ([['17.80', '31.5000'], ['21.50', '36.0000'], ['21.50', '31.5000']] as [$cycle, $weight]) {
            ProductionStandard::create([
                'item_id' => $item->id,
                'source_product_name' => '1000ML OVAL',
                'cavities' => 2,
                'unit_weight_grams' => $weight,
                'cycle_time' => $cycle,
                'status' => 'unresolved',
            ]);
        }

        $rows = $this->getJson('/api/v1/production/standards/coverage')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($item->id, $rows[0]['item_id']);
    }

    public function test_an_unmatched_standard_covers_no_product(): void
    {
        // A standard the import could not match to an item names a product
        // that is not in the picker at all — it must not make some other row
        // look configured.
        $this->actAsSupervisor();
        ProductionStandard::create([
            'item_id' => null,
            'source_product_name' => 'SOMETHING THE CATALOGUE LACKS',
            'cavities' => 4,
            'status' => 'draft',
        ]);

        $this->getJson('/api/v1/production/standards/coverage')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // -----------------------------------------------------------------
    // 2. Which missing masters are real
    // -----------------------------------------------------------------

    public function test_a_local_fixture_reports_no_tally_item_failure_and_is_flagged_as_local(): void
    {
        $item = $this->localFixtureItem();

        // Enforcing, so a genuine tally_item failure would land in `blocking`
        // rather than being quietly downgraded by the watch-only default.
        config()->set('production.readiness.enforced', true);
        config()->set('production.readiness.checks.tally_item', 'block');

        $readiness = app(ProductReadinessService::class)->assess($item);

        $this->assertTrue($readiness['is_local_fixture']);
        $this->assertNotContains(
            'tally_item',
            array_column([...$readiness['blocking'], ...$readiness['warnings']], 'code'),
            'A LOCAL- fixture has no Tally GUID by construction — that is not a missing master.',
        );
    }

    public function test_a_real_item_with_no_tally_guid_still_fails_the_tally_item_check(): void
    {
        // The regression that matters: the local-fixture exemption must be
        // exactly that, not a hole. A real product missing its GUID is still
        // voucher-fatal, and Tally still rejects the whole voucher hours
        // after the shift is worked.
        $item = $this->unconfiguredItem();
        $item->update(['tally_stock_item_guid' => null]);

        config()->set('production.readiness.enforced', true);
        config()->set('production.readiness.checks.tally_item', 'block');

        $readiness = app(ProductReadinessService::class)->assess($item->fresh());

        $this->assertFalse($readiness['is_local_fixture']);
        $this->assertContains('tally_item', array_column($readiness['blocking'], 'code'));
        $this->assertFalse($readiness['ready']);
    }

    public function test_the_start_batch_preview_carries_the_local_fixture_flag_to_the_screen(): void
    {
        // The banner ("Local-only fixture — voucher posting disabled") is
        // driven from this field, so it has to survive the API boundary.
        $this->actAsSupervisor();
        $item = $this->localFixtureItem();

        $this->getJson('/api/v1/production/shift-production-entries/preview?item_id='.$item->id)
            ->assertOk()
            ->assertJsonPath('data.readiness.is_local_fixture', true);
    }

    public function test_readiness_reads_the_factory_standard_not_only_the_item_master(): void
    {
        // Before this, the same preview response quoted an expected 129 kg
        // for a product while reporting "no product weight" about it — the
        // estimation engine resolved through the factory standard and the
        // readiness gate did not. Every figure asserted here lives on the
        // standard, and nowhere on the item master.
        $this->actAsSupervisor();
        $item = $this->localFixtureItem();

        $this->assertNull($item->nominal_weight_grams);
        $this->assertNull($item->standard_cycle_time);

        $codes = array_column(
            $this->getJson('/api/v1/production/shift-production-entries/preview?item_id='.$item->id)
                ->assertOk()
                ->json('data.readiness.warnings'),
            'code',
        );

        foreach (['weight', 'cycle_time', 'cavities', 'packing'] as $resolved) {
            $this->assertNotContains(
                $resolved,
                $codes,
                "The factory standard supplies {$resolved} — the gate must judge what the run will actually use.",
            );
        }
    }

    // -----------------------------------------------------------------
    // 3. A local fixture never reaches Tally
    // -----------------------------------------------------------------

    public function test_accountant_approval_of_a_local_fixture_batch_queues_no_tally_voucher(): void
    {
        // Deliberately NOT Event::fake()'d: the guard lives behind the real
        // ShiftProductionEntryApproved listener, and faking the event would
        // make this pass with the guard deleted.
        $approver = User::factory()->create();
        $entry = $this->completedEntry($this->localFixtureItem());

        $service = app(ShiftProductionEntryService::class);
        $service->pmApprove($entry, $approver->id);
        $approved = $service->accountantApprove($entry->fresh(), $approver->id);

        $this->assertSame(ShiftProductionEntryStatus::Approved->value, $approved->status->value);
        $this->assertSame(0, TallySyncEntry::count(), 'A product Tally does not know must never be queued to it.');
        $this->assertNull($approved->fresh()->tally_sync_entry_id);
    }

    public function test_the_same_approval_on_a_real_item_does_queue_a_voucher(): void
    {
        // The negative control for the test above. Without it, that test
        // would pass just as well if approval queued nothing for ANY item.
        $approver = User::factory()->create();
        $entry = $this->completedEntry($this->unconfiguredItem());

        $service = app(ShiftProductionEntryService::class);
        $service->pmApprove($entry, $approver->id);
        $service->accountantApprove($entry->fresh(), $approver->id);

        $this->assertSame(1, TallySyncEntry::count());
    }

    public function test_a_local_fixture_is_not_swept_into_a_real_items_shift_voucher(): void
    {
        // Shift granularity aggregates every approved entry of a (date,
        // shift) into one voucher. The top-level guard alone would be
        // bypassed sideways here: the REAL item's approval does the
        // sweeping, so the fixture's quantities would ride into Tally under
        // someone else's approval.
        config(['tally-sync.voucher_granularity' => 'shift']);

        $approver = User::factory()->create();
        $service = app(ShiftProductionEntryService::class);

        $fixture = $this->completedEntry($this->localFixtureItem());
        $service->pmApprove($fixture, $approver->id);
        $service->accountantApprove($fixture->fresh(), $approver->id);

        $real = $this->completedEntry($this->unconfiguredItem());
        $service->pmApprove($real, $approver->id);
        $service->accountantApprove($real->fresh(), $approver->id);

        $voucher = TallySyncEntry::query()->sole();
        $this->assertSame([$real->id], $voucher->payload['entry_ids']);
        $this->assertNull($fixture->fresh()->tally_sync_entry_id);
    }

    public function test_the_voucher_preview_still_works_for_a_local_fixture_and_writes_nothing(): void
    {
        // Declining to POST is not the same as refusing to SHOW. The
        // accountant must still be able to see what the voucher would have
        // been — that read is what tells them the product needs creating in
        // Tally.
        $this->actAsSupervisor();
        $entry = $this->completedEntry($this->localFixtureItem());

        $this->getJson("/api/v1/production/shift-production-entries/{$entry->id}/voucher-preview")
            ->assertOk();

        $this->assertSame(0, TallySyncEntry::count());
    }

    // -----------------------------------------------------------------
    // 4. Colour is asked once and frozen
    // -----------------------------------------------------------------

    public function test_the_colour_chosen_at_start_batch_is_snapshotted_on_the_entry(): void
    {
        $this->actAsSupervisor();
        $item = $this->localFixtureItem();
        $this->assertNull($item->colour, 'The premise: the masters cannot answer this, so the supervisor is asked.');

        $response = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $this->fgStore->id,
            'colour' => 'Amber',
        ])->assertOk();

        $entry = ShiftProductionEntry::query()->findOrFail($response->json('data.id'));

        $this->assertSame('Amber', $entry->config_snapshot['colour']);
    }

    public function test_an_unanswered_colour_is_stored_as_unknown_never_defaulted(): void
    {
        // A confident wrong colour picks the wrong masterbatch and the wrong
        // amber/clear scrap item downstream. "Not known" is recoverable;
        // a defaulted "Clear" nobody chose is not.
        $this->actAsSupervisor();
        $item = $this->localFixtureItem();

        $response = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $this->fgStore->id,
        ])->assertOk();

        $entry = ShiftProductionEntry::query()->findOrFail($response->json('data.id'));

        $this->assertArrayHasKey('colour', $entry->config_snapshot);
        $this->assertNull($entry->config_snapshot['colour']);
    }

    public function test_a_colour_already_on_the_item_master_is_snapshotted_without_being_asked_for(): void
    {
        $this->actAsSupervisor();
        $item = $this->localFixtureItem();
        $item->update(['colour' => 'Clear']);

        $response = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $this->fgStore->id,
        ])->assertOk();

        $entry = ShiftProductionEntry::query()->findOrFail($response->json('data.id'));

        $this->assertSame('Clear', $entry->config_snapshot['colour']);
    }

    /** A batch run to completion, sitting pending approval. */
    private function completedEntry(Item $item): ShiftProductionEntry
    {
        return ShiftProductionEntry::create([
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $this->fgStore->id,
            'production_date' => '2026-07-29',
            'batch_number' => "20260729-M01-{$item->id}",
            'batch_status' => BatchStatus::Completed,
            'quantity_produced' => '5000',
            'quantity_scrap' => '0',
            'status' => ShiftProductionEntryStatus::Pending,
        ]);
    }
}
