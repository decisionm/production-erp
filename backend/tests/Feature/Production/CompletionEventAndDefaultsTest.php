<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Events\ShiftProductionEntryApproved;
use App\Modules\Production\Events\ShiftProductionEntryCompleted;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ShiftProductionEntryService;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 5.5, WS-B — completion made durable: the parts Phase 5 did not do.
 *
 *   1. ShiftProductionEntryCompleted is a domain event, raised ONCE per
 *      completion and once per amendment, only after the completion's
 *      transaction has committed, and never on start or cancel. Nothing
 *      listening to it touches Tally — the approval event stays the one
 *      Tally trigger (the §4a gate), and this test proves the completion
 *      event enqueues nothing where the approval event would.
 *   2. The entry resource carries `packing_defaults` — the counts the
 *      completion drawer is pre-filled with, read through the ONE pack
 *      quantity reader (PackQuantityResolver::forEntry) — and
 *      `configuration_gaps`, the missing-vocabulary of the configuration the
 *      run was started against.
 *   3. CompleteBatchRequest names its fields for what they are; its RULES
 *      are unchanged (PackingLinesTest / PackingLinesPersistTest stay green).
 */
class CompletionEventAndDefaultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // These tests are about completion, not the readiness gate — the
        // start-batch arm below runs against a deliberately thin item.
        config()->set('production.readiness.enforced', false);
    }

    private function actingAsProduction(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        Permission::findOrCreate('production.manage', 'web');
        $user->givePermissionTo(['production.view', 'production.manage']);
        Sanctum::actingAs($user);

        return $user;
    }

    /** @param  array<string, mixed>  $overrides */
    private function item(string $sku = 'BTL-1', array $overrides = []): Item
    {
        return Item::create([
            'sku' => $sku, 'name' => 'Bottle '.$sku, 'uom' => 'Nos.',
            'nominal_weight_grams' => '12.9000',
            'standard_cycle_time' => '12.30', 'standard_cavities' => 5,
            'tally_stock_item_guid' => 'itm-'.$sku,
            ...$overrides,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $packagings
     * @param  array<string, mixed>  $overrides
     */
    private function standardFor(Item $item, array $packagings, array $overrides = []): ProductionStandard
    {
        $standard = ProductionStandard::create([
            'item_id' => $item->id,
            'source_product_name' => $item->name,
            'cavities' => 5,
            'unit_weight_grams' => '12.9000',
            'cycle_time' => '12.30',
            'status' => 'approved',
            'source' => 'completion-event-test',
            ...$overrides,
        ]);

        foreach ($packagings as $packaging) {
            $standard->packagings()->create($packaging);
        }

        return $standard->fresh('packagings');
    }

    /** @return array{0: Shift, 1: WorkCenter, 2: Warehouse} */
    private function floor(): array
    {
        return [
            Shift::firstOrCreate(['name' => 'Morning'], ['start_time' => '06:00', 'end_time' => '14:00']),
            WorkCenter::firstOrCreate(['code' => 'MC-01'], ['name' => 'Machine 1']),
            Warehouse::firstOrCreate(['code' => 'FG'], ['name' => 'FG Store']),
        ];
    }

    private function inProgressEntry(
        Item $item,
        ?ProductionStandard $standard = null,
        ?ProductionStandardPackaging $packaging = null,
    ): ShiftProductionEntry {
        [$shift, $machine, $warehouse] = $this->floor();

        return ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'production_standard_id' => $standard?->id,
            'production_standard_packaging_id' => $packaging?->id,
            'packaging_mode' => $packaging?->mode,
            'production_date' => '2026-08-17',
            'batch_number' => '20260817-M01-'.str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT),
            'batch_status' => BatchStatus::InProgress,
            'quantity_produced' => null,
            'quantity_scrap' => '0',
            'standard_cycle_time' => '12.30',
            'standard_cavities' => 5,
            'active_cavities' => 5,
        ]);
    }

    /** A tray packing: 230/tray, 5 trays/carton, 1150/carton. */
    private function trayPackaging(): array
    {
        return ['mode' => ProductionStandardPackaging::MODE_TRAY,
            'nos_per_tray' => 230, 'trays_per_box' => 5, 'nos_per_box' => 1150];
    }

    /** One tray carton, counted straight — the smallest complete payload. */
    private function oneCarton(): array
    {
        return [
            'quantity_produced' => '1150',
            'no_of_box' => 1,
            'nos_per_box' => 1150,
            'packing_lines' => [[
                'mode' => 'tray', 'boxes' => 1, 'nos_per_box' => 1150,
                'loose_inner' => 0, 'nos_per_inner' => 230,
                'derived_pieces' => 1150, 'actual_pieces' => 1150,
            ]],
        ];
    }

    // ================================================================ event ===

    public function test_completing_a_batch_raises_the_completed_event_once_and_only_after_the_commit(): void
    {
        $this->actingAsProduction();
        $item = $this->item('BTL-TRAY');
        $standard = $this->standardFor($item, [$this->trayPackaging()]);
        $entry = $this->inProgressEntry($item, $standard, $standard->packagings->first());

        // A REAL listener, not a fake: it records the transaction depth at
        // the moment the event reaches it. The completion's own transaction
        // must already be gone — anything listening (a printer, a notifier,
        // a report) would otherwise read a batch the database may still roll
        // back.
        $outsideAnyCompletion = DB::transactionLevel();
        $seen = [];
        Event::listen(ShiftProductionEntryCompleted::class, function (ShiftProductionEntryCompleted $event) use (&$seen) {
            $seen[] = [
                'entry_id' => $event->entry->id,
                'batch_status' => $event->entry->batch_status,
                'quantity_produced' => (float) $event->entry->quantity_produced,
                'transaction_level' => DB::transactionLevel(),
                'stored_status' => ShiftProductionEntry::query()->whereKey($event->entry->id)->value('batch_status'),
            ];
        });

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", $this->oneCarton())
            ->assertOk()
            ->assertJsonPath('data.batch_status', 'completed');

        $this->assertCount(1, $seen, 'one completion, one event');
        $this->assertSame($entry->id, $seen[0]['entry_id']);
        $this->assertSame(BatchStatus::Completed, $seen[0]['batch_status'], 'the event carries the COMPLETED entry, not the row as it was loaded');
        $this->assertSame(1150.0, $seen[0]['quantity_produced']);
        $this->assertSame($outsideAnyCompletion, $seen[0]['transaction_level'], 'raised after the completion transaction committed, not inside it');
        $this->assertSame(BatchStatus::Completed, $seen[0]['stored_status']);
    }

    public function test_an_amendment_raises_the_event_again_once_with_the_corrected_figures(): void
    {
        $this->actingAsProduction();
        $item = $this->item('BTL-TRAY');
        $standard = $this->standardFor($item, [$this->trayPackaging()]);
        $entry = $this->inProgressEntry($item, $standard, $standard->packagings->first());

        Event::fake([ShiftProductionEntryCompleted::class]);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", $this->oneCarton())
            ->assertOk();

        Event::assertDispatchedTimes(ShiftProductionEntryCompleted::class, 1);

        // The correction: it was two cartons after all.
        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/amend", [
            'quantity_produced' => '2300',
            'no_of_box' => 2,
            'nos_per_box' => 1150,
            'packing_lines' => [[
                'mode' => 'tray', 'boxes' => 2, 'nos_per_box' => 1150,
                'loose_inner' => 0, 'nos_per_inner' => 230,
                'derived_pieces' => 2300, 'actual_pieces' => 2300,
            ]],
            'amendment_reason' => 'second carton was on the other pallet',
        ])->assertOk();

        Event::assertDispatchedTimes(ShiftProductionEntryCompleted::class, 2);
        Event::assertDispatched(
            ShiftProductionEntryCompleted::class,
            fn (ShiftProductionEntryCompleted $event) => $event->entry->id === $entry->id
                && (float) $event->entry->quantity_produced === 2300.0
                && $event->entry->batch_status === BatchStatus::Completed,
        );
    }

    public function test_neither_start_nor_cancel_raises_it(): void
    {
        $this->actingAsProduction();
        [$shift, $machine, $warehouse] = $this->floor();
        $item = $this->item('BTL-START');

        Event::fake([ShiftProductionEntryCompleted::class]);

        // Start.
        $started = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => '2026-08-17',
        ])->assertOk()->json('data.id');

        Event::assertNotDispatched(ShiftProductionEntryCompleted::class);

        // Cancel a running batch.
        $this->postJson("/api/v1/production/shift-production-entries/{$started}/cancel", [
            'reason' => 'started on the wrong machine',
        ])->assertOk()->assertJsonPath('data.batch_status', 'cancelled');

        Event::assertNotDispatched(ShiftProductionEntryCompleted::class);

        // Cancel a COMPLETED batch (allowed before quality): the completion
        // raised its one event; the cancellation adds none.
        $entry = $this->inProgressEntry($item);
        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '840', 'nos_per_tray' => 84, 'no_of_trays' => 10,
        ])->assertOk();
        Event::assertDispatchedTimes(ShiftProductionEntryCompleted::class, 1);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/cancel", [
            'reason' => 'test batch, counted by mistake',
        ])->assertOk()->assertJsonPath('data.batch_status', 'cancelled');
        Event::assertDispatchedTimes(ShiftProductionEntryCompleted::class, 1);
    }

    public function test_a_completion_whose_enclosing_transaction_rolls_back_raises_nothing(): void
    {
        // completeBatch() also runs INSIDE larger transactions — an
        // amendment's, a handover's — and those can fail after it returns.
        // The event must ride the OUTER commit: dispatched at the inner
        // return, a listener would be told about a completion the database
        // then rolled back. Modelled directly: a wrapping transaction that
        // fails once the completion has returned.
        $user = $this->actingAsProduction();
        $item = $this->item('BTL-ROLLBACK');
        $standard = $this->standardFor($item, [$this->trayPackaging()]);
        $entry = $this->inProgressEntry($item, $standard, $standard->packagings->first());

        Event::fake([ShiftProductionEntryCompleted::class]);

        try {
            DB::transaction(function () use ($entry, $user) {
                $completed = app(ShiftProductionEntryService::class)->completeBatch($entry, $this->oneCarton(), $user->id);
                $this->assertSame(BatchStatus::Completed, $completed->batch_status, 'the inner completion itself succeeded');

                throw new RuntimeException('the outer work failed after the completion returned');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('the outer work failed after the completion returned', $e->getMessage());
        }

        $this->assertSame(BatchStatus::InProgress, $entry->fresh()->batch_status, 'rolled back with the outer transaction');
        Event::assertNotDispatched(ShiftProductionEntryCompleted::class);
    }

    public function test_the_completion_event_touches_tally_in_no_way(): void
    {
        // Batch-granularity vouchers, so the CONTROL below enqueues one row
        // per approved batch and the count is unambiguous.
        config(['tally-sync.voucher_granularity' => 'batch']);

        $this->actingAsProduction();
        [, , $warehouse] = $this->floor();
        $warehouse->forceFill(['tally_guid' => 'gd-fg'])->save();
        $item = $this->item('BTL-TALLY');
        $standard = $this->standardFor($item, [$this->trayPackaging()]);
        $entry = $this->inProgressEntry($item, $standard, $standard->packagings->first());

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", $this->oneCarton())
            ->assertOk();

        // The completion itself — with the real event and every registered
        // listener — enqueued nothing.
        $this->assertSame(0, TallySyncEntry::query()->count(), 'a completion is not a Tally trigger');

        // Nor does the event on its own, dispatched again by hand.
        $completed = $entry->fresh(['item', 'warehouse', 'materialConsumptions']);
        event(new ShiftProductionEntryCompleted($completed));
        $this->assertSame(0, TallySyncEntry::query()->count(), 'no listener on the completion event enqueues a voucher');

        // CONTROL: the same entry, through the approval event, DOES enqueue —
        // so the zero above is a fact about the completion event, not about
        // a fixture that could never post.
        event(new ShiftProductionEntryApproved($completed));
        $this->assertSame(1, TallySyncEntry::query()->count(), 'the approval event stays the Tally trigger');
    }

    // ============================================================= resource ===

    public function test_packing_defaults_are_the_frozen_packaging_counts_before_completion_and_the_typed_ones_after(): void
    {
        $this->actingAsProduction();
        // The master says 520/box; the run froze a 1150/box tray packing.
        $item = $this->item('BTL-TRAY', ['nos_per_box' => 520]);
        $standard = $this->standardFor($item, [$this->trayPackaging()]);
        $entry = $this->inProgressEntry($item, $standard, $standard->packagings->first());

        // Before completion: the packaging row's counts, labelled as such —
        // what the completion drawer pre-fills.
        $this->getJson('/api/v1/production/shift-production-entries/active')
            ->assertOk()
            ->assertJsonPath('data.0.id', $entry->id)
            ->assertJsonPath('data.0.packing_defaults.nos_per_box', 1150)
            ->assertJsonPath('data.0.packing_defaults.nos_per_tray', 230)
            ->assertJsonPath('data.0.packing_defaults.trays_per_box', 5)
            ->assertJsonPath('data.0.packing_defaults.nos_per_pouch', null)
            ->assertJsonPath('data.0.packing_defaults.pouches_per_box', null)
            ->assertJsonPath('data.0.packing_defaults.source', 'packaging')
            ->assertJsonPath('data.0.packing_defaults.sources.nos_per_box', 'packaging')
            ->assertJsonPath('data.0.packing_defaults.sources.nos_per_pouch', 'none');

        // A run with no packaging row falls through to the item master.
        $plain = $this->item('BTL-PLAIN', ['nos_per_box' => 520, 'nos_per_tray' => 104]);
        WorkCenter::firstOrCreate(['code' => 'MC-02'], ['name' => 'Machine 2']);
        $plainEntry = ShiftProductionEntry::create([
            ...$entry->only(['shift_id', 'warehouse_id', 'production_date', 'quantity_scrap']),
            'work_center_id' => WorkCenter::where('code', 'MC-02')->value('id'),
            'item_id' => $plain->id,
            'batch_number' => '20260817-M02-001',
            'batch_status' => BatchStatus::InProgress,
        ]);
        $this->getJson('/api/v1/production/shift-production-entries/active')
            ->assertOk()
            ->assertJsonPath('data.0.id', $plainEntry->id)
            ->assertJsonPath('data.0.packing_defaults.nos_per_box', 520)
            ->assertJsonPath('data.0.packing_defaults.nos_per_tray', 104)
            ->assertJsonPath('data.0.packing_defaults.trays_per_box', null)
            ->assertJsonPath('data.0.packing_defaults.source', 'item');

        // After completion: the counts the supervisor typed win — the amend
        // drawer opens on what was recorded, never on the master again.
        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '1100',
            'no_of_box' => 1,
            'nos_per_box' => 1100,
            'nos_per_tray' => 220,
            'packing_lines' => [[
                'mode' => 'tray', 'boxes' => 1, 'nos_per_box' => 1100,
                'loose_inner' => 0, 'nos_per_inner' => 220,
                'derived_pieces' => 1100, 'actual_pieces' => 1100,
            ]],
        ])
            ->assertOk()
            ->assertJsonPath('data.packing_defaults.nos_per_box', 1100)
            ->assertJsonPath('data.packing_defaults.nos_per_tray', 220)
            // No entry column for trays_per_box: the packaging row still answers it.
            ->assertJsonPath('data.packing_defaults.trays_per_box', 5)
            ->assertJsonPath('data.packing_defaults.source', 'entry')
            ->assertJsonPath('data.packing_defaults.sources.trays_per_box', 'packaging');
    }

    public function test_configuration_gaps_name_what_the_run_was_started_without(): void
    {
        $this->actingAsProduction();

        // (a) Fully configured: figures on the standard, a complete packing,
        //     a Tally-identified product.
        $ready = $this->item('BTL-READY');
        $readyStd = $this->standardFor($ready, [$this->trayPackaging()]);
        $readyEntry = $this->inProgressEntry($ready, $readyStd, $readyStd->packagings->first());

        $this->getJson('/api/v1/production/shift-production-entries/active')
            ->assertOk()
            ->assertJsonPath('data.0.id', $readyEntry->id)
            ->assertJsonPath('data.0.configuration_gaps.complete', true)
            ->assertJsonPath('data.0.configuration_gaps.missing', [])
            // Not frozen at Start (yet): computed now, from the frozen
            // standard/packaging ids, and the payload says so.
            ->assertJsonPath('data.0.configuration_gaps.source', 'live');
        $readyEntry->delete();

        // (b) An incomplete tray packing (no pieces per tray) on a product
        //     with no Tally identity: the words are the vocabulary's, in its
        //     order.
        $thin = $this->item('BTL-THIN', ['tally_stock_item_guid' => null]);
        $thinStd = $this->standardFor($thin, [[
            'mode' => ProductionStandardPackaging::MODE_TRAY, 'trays_per_box' => 5, 'nos_per_box' => 1150,
        ]]);
        $thinEntry = $this->inProgressEntry($thin, $thinStd, $thinStd->packagings->first());

        $this->getJson('/api/v1/production/shift-production-entries/active')
            ->assertOk()
            ->assertJsonPath('data.0.id', $thinEntry->id)
            ->assertJsonPath('data.0.configuration_gaps.complete', false)
            ->assertJsonPath('data.0.configuration_gaps.missing', ['counts', 'tally_identity']);
        $thinEntry->delete();

        // (c) No standard at all.
        $bare = $this->item('BTL-BARE', ['tally_stock_item_guid' => null]);
        $bareEntry = $this->inProgressEntry($bare);
        $this->getJson('/api/v1/production/shift-production-entries/active')
            ->assertOk()
            ->assertJsonPath('data.0.id', $bareEntry->id)
            ->assertJsonPath('data.0.configuration_gaps.missing', ['standard', 'tally_identity']);
        $bareEntry->delete();

        // (d) The gaps are the RUN's, not the standard's union: a sibling
        //     packing that is incomplete does not mark a batch that ran on
        //     the complete one.
        $twin = $this->item('BTL-TWIN');
        $twinStd = $this->standardFor($twin, [
            $this->trayPackaging(),
            ['mode' => ProductionStandardPackaging::MODE_POUCH, 'pouches_per_box' => 5, 'nos_per_box' => 1225],
        ]);
        $twinEntry = $this->inProgressEntry($twin, $twinStd, $twinStd->packagings->firstWhere('mode', 'tray'));
        $this->getJson('/api/v1/production/shift-production-entries/active')
            ->assertOk()
            ->assertJsonPath('data.0.id', $twinEntry->id)
            ->assertJsonPath('data.0.configuration_gaps.missing', []);
        $twinEntry->delete();

        // (e) The identity that posts is the packaging's own item when it has
        //     one (DEC-20260810-003): a real item on the packing makes the
        //     run complete even though the product's own row is a fixture.
        $fixture = $this->item('LOCAL-FIX', ['tally_stock_item_guid' => null, 'is_local_fixture' => true]);
        $real = $this->item('BTL-REAL-PACK');
        $fixStd = $this->standardFor($fixture, [[...$this->trayPackaging(), 'item_id' => $real->id]]);
        $fixEntry = $this->inProgressEntry($fixture, $fixStd, $fixStd->packagings->first());
        $this->getJson('/api/v1/production/shift-production-entries/active')
            ->assertOk()
            ->assertJsonPath('data.0.id', $fixEntry->id)
            ->assertJsonPath('data.0.configuration_gaps.missing', []);
        $fixEntry->delete();

        // (f) A snapshot frozen at Start, when one exists, outranks the live
        //     computation — history never restates itself because a master
        //     was fixed later.
        $frozen = $this->item('BTL-FROZEN');
        $frozenStd = $this->standardFor($frozen, [$this->trayPackaging()]);
        $frozenEntry = $this->inProgressEntry($frozen, $frozenStd, $frozenStd->packagings->first());
        $frozenEntry->config_snapshot = ['configuration_gaps' => ['complete' => false, 'missing' => ['cycle_time']]];
        $frozenEntry->save();
        $this->getJson('/api/v1/production/shift-production-entries/active')
            ->assertOk()
            ->assertJsonPath('data.0.id', $frozenEntry->id)
            ->assertJsonPath('data.0.configuration_gaps.complete', false)
            ->assertJsonPath('data.0.configuration_gaps.missing', ['cycle_time'])
            ->assertJsonPath('data.0.configuration_gaps.source', 'snapshot');
    }

    /**
     * FROZEN AT START, through the REAL start path. Before this, nothing
     * wrote config_snapshot['configuration_gaps']: every read was 'live',
     * so a finished batch's "config incomplete" tag restated itself the
     * moment a master was fixed — the exact retroactive restatement the
     * calculation_version stamp exists to prevent. Now POST start writes
     * runStatus() for the frozen standard/packaging, the resource says
     * source 'snapshot', and a later master edit moves nothing.
     */
    public function test_start_freezes_the_configuration_gaps_and_a_later_master_fix_never_restates_the_batch(): void
    {
        $this->actingAsProduction();
        [$shift, $machine, $warehouse] = $this->floor();

        // A product with no Tally identity and a tray packing missing its
        // per-tray count: the run starts with two named gaps.
        $thin = $this->item('BTL-THIN', ['tally_stock_item_guid' => null]);
        $thinStd = $this->standardFor($thin, [[
            'mode' => ProductionStandardPackaging::MODE_TRAY, 'trays_per_box' => 5, 'nos_per_box' => 1150,
        ]]);
        $packaging = $thinStd->packagings->first();

        $started = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $thin->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => '2026-08-17',
            'production_standard_id' => $thinStd->id,
        ])->assertOk()
            ->assertJsonPath('data.configuration_gaps.source', 'snapshot')
            ->assertJsonPath('data.configuration_gaps.complete', false)
            ->assertJsonPath('data.configuration_gaps.missing', ['counts', 'tally_identity']);
        $entryId = $started->json('data.id');

        // The snapshot is on the row itself, in the run's frozen record.
        $entry = ShiftProductionEntry::query()->findOrFail($entryId);
        $this->assertSame(['counts', 'tally_identity'], $entry->config_snapshot['configuration_gaps']['missing']);
        $this->assertSame('incomplete', $entry->config_snapshot['configuration_gaps']['state']);

        // (An incomplete packing is never resolved for a run — the resolver
        // offers complete rows only — so this run froze the standard and no
        // packaging, and its verdict was the standard's, over its one row.)
        $this->assertNull($entry->production_standard_packaging_id);

        // The masters are fixed AFTER the run started.
        $thin->forceFill(['tally_stock_item_guid' => 'itm-thin-fixed'])->save();
        $packaging->forceFill(['nos_per_tray' => 230])->save();
        $this->assertTrue($packaging->fresh()->isComplete(), 'the packaging is complete NOW');

        // The batch still says what it was started without — on every door.
        $this->getJson('/api/v1/production/shift-production-entries/active')
            ->assertOk()
            ->assertJsonPath('data.0.id', $entryId)
            ->assertJsonPath('data.0.configuration_gaps.source', 'snapshot')
            ->assertJsonPath('data.0.configuration_gaps.missing', ['counts', 'tally_identity']);

        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => '1150', 'no_of_box' => 1, 'nos_per_box' => 1150,
        ])->assertOk()
            ->assertJsonPath('data.configuration_gaps.source', 'snapshot')
            ->assertJsonPath('data.configuration_gaps.missing', ['counts', 'tally_identity']);

        $this->getJson('/api/v1/production/shift-production-entries?status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.id', $entryId)
            ->assertJsonPath('data.0.configuration_gaps.source', 'snapshot')
            ->assertJsonPath('data.0.configuration_gaps.missing', ['counts', 'tally_identity']);

        // CONTROL: a batch started NOW, against the fixed masters, is complete
        // — the snapshot is a fact about each run, not a stale cache.
        WorkCenter::firstOrCreate(['code' => 'MC-02'], ['name' => 'Machine 2']);
        $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $shift->id,
            'work_center_id' => WorkCenter::where('code', 'MC-02')->value('id'),
            'item_id' => $thin->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => '2026-08-17',
            'production_standard_id' => $thinStd->id,
        ])->assertOk()
            ->assertJsonPath('data.configuration_gaps.source', 'snapshot')
            ->assertJsonPath('data.configuration_gaps.complete', true)
            ->assertJsonPath('data.configuration_gaps.missing', []);
    }

    /**
     * A run from before Start froze the verdict (no snapshot on the row) is
     * still judged live from its frozen ids, and SAYS so — and, being live,
     * it does follow a later master fix. That is the honest reading for a
     * row that never recorded what it started with; it is not the reading
     * new rows get.
     */
    public function test_a_run_started_before_the_snapshot_existed_stays_live_and_says_so(): void
    {
        $this->actingAsProduction();
        $thin = $this->item('BTL-LEGACY', ['tally_stock_item_guid' => null]);
        $thinStd = $this->standardFor($thin, [$this->trayPackaging()]);
        // A row created without going through startBatch — as every entry
        // before this change was, as far as this key is concerned.
        $entry = $this->inProgressEntry($thin, $thinStd, $thinStd->packagings->first());
        $this->assertArrayNotHasKey('configuration_gaps', $entry->config_snapshot ?? []);

        $this->getJson('/api/v1/production/shift-production-entries/active')
            ->assertOk()
            ->assertJsonPath('data.0.id', $entry->id)
            ->assertJsonPath('data.0.configuration_gaps.source', 'live')
            ->assertJsonPath('data.0.configuration_gaps.missing', ['tally_identity']);

        $thin->forceFill(['tally_stock_item_guid' => 'itm-legacy-fixed'])->save();

        $this->getJson('/api/v1/production/shift-production-entries/active')
            ->assertOk()
            ->assertJsonPath('data.0.configuration_gaps.source', 'live')
            ->assertJsonPath('data.0.configuration_gaps.missing', []);
    }

    /**
     * A handover child is a segment of the SAME run: it carries its parent's
     * frozen verdict verbatim (source 'snapshot'), never a fresh judgment of
     * masters that may have moved since — and never the "no standard" a
     * bare child row would read live, since the child freezes no ids of
     * its own.
     */
    public function test_a_handover_child_copies_its_parents_frozen_configuration_gaps(): void
    {
        config(['production.traceability_enabled' => true]);
        $this->actingAsProduction();
        [$shift, $machine, $warehouse] = $this->floor();
        $evening = Shift::firstOrCreate(['name' => 'Evening'], ['start_time' => '14:00', 'end_time' => '22:00']);

        $thin = $this->item('BTL-THIN', ['tally_stock_item_guid' => null]);
        $thinStd = $this->standardFor($thin, [$this->trayPackaging()]);

        $parentId = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $thin->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => '2026-08-17',
            'production_standard_id' => $thinStd->id,
        ])->assertOk()
            ->assertJsonPath('data.configuration_gaps.missing', ['tally_identity'])
            ->json('data.id');

        // Fixed between the start and the handover.
        $thin->forceFill(['tally_stock_item_guid' => 'itm-thin-fixed'])->save();

        $this->postJson("/api/v1/production/shift-production-entries/{$parentId}/handover", [
            'shift_id' => $evening->id,
            'completion' => ['quantity_produced' => '1150', 'no_of_box' => 1, 'nos_per_box' => 1150],
        ])->assertSuccessful()
            ->assertJsonPath('data.parent_entry_id', $parentId)
            ->assertJsonPath('data.configuration_gaps.source', 'snapshot')
            ->assertJsonPath('data.configuration_gaps.complete', false)
            ->assertJsonPath('data.configuration_gaps.missing', ['tally_identity']);

        $child = ShiftProductionEntry::query()->where('parent_entry_id', $parentId)->firstOrFail();
        $this->assertSame(
            ShiftProductionEntry::query()->findOrFail($parentId)->config_snapshot['configuration_gaps'],
            $child->config_snapshot['configuration_gaps'],
        );
    }

    /**
     * A parent from before the snapshot existed carries no verdict to copy.
     * Its child is judged ONCE, at the handover, from the ids the parent
     * froze — the child freezes none of its own, and read live it would say
     * "no standard" about a run that has one — and that verdict is frozen
     * on the child. The parent itself keeps reading live.
     */
    public function test_a_handover_child_of_a_pre_snapshot_parent_is_judged_once_from_the_parents_frozen_ids(): void
    {
        config(['production.traceability_enabled' => true]);
        $this->actingAsProduction();
        $evening = Shift::firstOrCreate(['name' => 'Evening'], ['start_time' => '14:00', 'end_time' => '22:00']);

        $thin = $this->item('BTL-THIN', ['tally_stock_item_guid' => null]);
        $thinStd = $this->standardFor($thin, [$this->trayPackaging()]);
        $parent = $this->inProgressEntry($thin, $thinStd, $thinStd->packagings->first());
        $this->assertArrayNotHasKey('configuration_gaps', $parent->config_snapshot ?? []);

        $this->postJson("/api/v1/production/shift-production-entries/{$parent->id}/handover", [
            'shift_id' => $evening->id,
            'completion' => ['quantity_produced' => '1150', 'no_of_box' => 1, 'nos_per_box' => 1150],
        ])->assertSuccessful()
            ->assertJsonPath('data.configuration_gaps.source', 'snapshot')
            ->assertJsonPath('data.configuration_gaps.missing', ['tally_identity']);

        // The parent, having no snapshot, is still read live — and says so.
        $this->getJson('/api/v1/production/shift-production-entries?status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.id', $parent->id)
            ->assertJsonPath('data.0.configuration_gaps.source', 'live')
            ->assertJsonPath('data.0.configuration_gaps.missing', ['tally_identity']);
    }

    public function test_the_gaps_ride_the_paginated_list_and_the_completion_response_too(): void
    {
        $this->actingAsProduction();
        $thin = $this->item('BTL-THIN', ['tally_stock_item_guid' => null]);
        $thinStd = $this->standardFor($thin, [$this->trayPackaging()]);
        $entry = $this->inProgressEntry($thin, $thinStd, $thinStd->packagings->first());

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", $this->oneCarton())
            ->assertOk()
            ->assertJsonPath('data.configuration_gaps.missing', ['tally_identity'])
            ->assertJsonPath('data.packing_defaults.nos_per_box', 1150);

        $this->getJson('/api/v1/production/shift-production-entries?status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.id', $entry->id)
            ->assertJsonPath('data.0.configuration_gaps.missing', ['tally_identity'])
            ->assertJsonPath('data.0.packing_defaults.source', 'entry');
    }

    // ============================================================== request ===

    public function test_the_completion_request_names_its_fields_for_what_they_are(): void
    {
        // The rules are the same rules (the packing-lines suites pin them);
        // what changes is the name a refusal calls the field by.
        $this->actingAsProduction();
        $entry = $this->inProgressEntry($this->item('BTL-NAMES'));

        $response = $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '0',
            'nos_per_box' => -1,
            'running_hours' => '25',
        ])->assertStatus(422)->assertJsonValidationErrors(['quantity_produced', 'nos_per_box', 'running_hours']);

        $errors = $response->json('errors');
        $this->assertStringContainsString('pieces produced', $errors['quantity_produced'][0]);
        $this->assertStringContainsString('pieces per box', $errors['nos_per_box'][0]);
        $this->assertStringContainsString('running hours', $errors['running_hours'][0]);
    }
}
