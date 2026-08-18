<?php

namespace Tests\Feature\Acceptance;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Models\DowntimeReason;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ConfigurationStatus;
use App\Modules\Production\Models\ProductionConfiguration;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\CecReportService;
use App\Modules\Production\Services\ProductionCalculationEngine;
use App\Modules\Production\Services\ShiftSummaryService;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Models\TallySyncSnapshot;
use App\Modules\TallySync\Services\AgentTokenService;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * PHASE 8 — ACCEPTANCE CHAIN A: THE OPERATOR WORKFLOW.
 *
 * The walk documented in docs/engineering/ACCEPTANCE-CHAIN.md, executed:
 *
 *   SKU configured once → Shift Floor asks nothing already known → Start
 *   Batch → Complete Batch (expected · actual · good · reject · packs ·
 *   downtime · efficiency) → Completed Today → Shift Summary → CEC →
 *   the Tally shift voucher (release gate · agent ack · snapshot).
 *
 * The assertions are on the TRANSACTION MODEL, never on a screen. Nothing
 * here touches the live instance, no Tally write of any kind happens (the
 * agent leg is the LOCAL ack/snapshot API answering a test token), and no
 * factory value is invented: every cycle time, cavity count, unit weight
 * and pack size below is an ARBITRARY TEST CONSTANT chosen so the
 * arithmetic can be checked by hand. They are not measurements of anything
 * in SWAASHPET POLYMERS and must never be read as such — every fixture is
 * prefixed ACC- to say so.
 *
 * WHY THESE FIXTURES. The chain is only worth walking if each link can
 * FAIL. So the day carries, on purpose:
 *
 *   ACC-B1  Shift A · ACC-M1 · configured machine → completed, checked,
 *           PM-approved, ACCOUNTANT-approved   → the voucher's only member
 *   ACC-B2  Shift A · ACC-M2 · no machine configuration, so the product
 *           standard governs → completed, checked (QC rejects 50),
 *           PM-approved, NOT accountant-approved
 *           → counts in Completed Today and the Shift Summary, and must
 *             NOT appear in the voucher
 *   ACC-B3  Shift B · ACC-M1 → completed and fully approved
 *           → its own shift voucher; must not contaminate Shift A's
 *   ACC-B4  Shift A · ACC-M2 → still running
 *   ACC-B5  Shift A · ACC-M1 → cancelled
 *   ACC-B6  the day BEFORE · Shift A · ACC-M1 → completed
 *   ACC-B7  Shift A · ACC-M1 → completed and fully approved
 *           → the SECOND member of Shift A's voucher, so "exactly the
 *             approved entries" has to get inclusion right as well as
 *             exclusion
 *
 * Three configuration tiers carry DIFFERENT figures, so "the run used the
 * recorded standard" is distinguishable from "the run used a fallback":
 *
 *   item master            CT 20.00 · 2 cavities · 18.0000 g · 490 / box
 *   product standard       CT 16.00 · 3 cavities · 15.0000 g · 500 / box
 *   machine configuration  CT 12.30 · 4 cavities · 12.0000 g
 */
class OperatorChainTest extends TestCase
{
    use RefreshDatabase;

    /** The factory day under test — historical, so every shift has ended. */
    private const DATE = '2026-08-03';

    private const DAY_BEFORE = '2026-08-02';

    /** The frozen clock: a fortnight after the day, so shift-end is never the variable. */
    private const NOW = '2026-08-17 10:00:00';

    private Shift $shiftA;

    private Shift $shiftB;

    private WorkCenter $m1;

    private WorkCenter $m2;

    private Item $bottle;

    /** A product with nothing recorded about it — the negative control for the floor's questions. */
    private Item $gapBottle;

    private Item $resin;

    private Warehouse $fg;

    private Warehouse $rm;

    private ProductionStandard $standard;

    private ProductionConfiguration $configuration;

    private DowntimeReason $powerCut;

    private ?string $agentToken = null;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::NOW);

        // The chain is walked under the PACKAGED settings, not a test-only
        // relaxation: the quality stage is on, vouchers aggregate per shift,
        // and the release gate holds for the packaged idle window.
        config()->set('production.approvals.quality_stage_enabled', true);
        config()->set('tally-sync.voucher_granularity', 'shift');
        config()->set('tally-sync.release_idle_minutes', 15);

        $this->shiftA = Shift::create(['name' => 'ACC Shift A', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $this->shiftB = Shift::create(['name' => 'ACC Shift B', 'start_time' => '14:00', 'end_time' => '22:00', 'is_active' => true]);

        $this->m1 = WorkCenter::create(['code' => 'ACC-M1', 'name' => 'ACC Machine 1', 'display_sequence' => 1, 'is_active' => true]);
        $this->m2 = WorkCenter::create(['code' => 'ACC-M2', 'name' => 'ACC Machine 2', 'display_sequence' => 2, 'is_active' => true]);

        $this->fg = Warehouse::create(['code' => 'ACC-FG', 'name' => 'ACC FG Store', 'is_active' => true, 'tally_guid' => 'acc-gd-fg']);
        $this->rm = Warehouse::create(['code' => 'ACC-RM', 'name' => 'ACC RM Store', 'is_active' => true, 'tally_guid' => 'acc-gd-rm']);

        // TIER 3 — the item master's own figures.
        $this->bottle = Item::create([
            'sku' => 'ACC-BTL-1', 'name' => 'ACC Test Bottle 1', 'uom' => 'Nos.', 'is_active' => true,
            'colour' => 'Amber', 'standard_cycle_time' => '20.00', 'standard_cavities' => 2,
            'nominal_weight_grams' => '18.0000', 'nos_per_box' => 490,
            'tally_stock_item_guid' => 'acc-itm-btl1',
        ]);
        $this->resin = Item::create([
            'sku' => 'ACC-RES-1', 'name' => 'ACC Test Resin', 'uom' => 'Kgs.', 'is_active' => true,
            'tally_stock_item_guid' => 'acc-itm-res1',
        ]);
        // Nothing is recorded about this one — it is the falsifier for
        // "the floor asks nothing already known".
        $this->gapBottle = Item::create([
            'sku' => 'ACC-BTL-GAP', 'name' => 'ACC Unconfigured Bottle', 'uom' => 'Nos.', 'is_active' => true,
        ]);

        // TIER 2 — the recorded product standard, approved.
        $this->standard = ProductionStandard::create([
            'item_id' => $this->bottle->id,
            'source_product_name' => 'ACC Test Bottle 1',
            'cavities' => 3,
            'unit_weight_grams' => '15.0000',
            'cycle_time' => '16.00',
            'status' => 'approved',
        ]);
        ProductionStandardPackaging::create([
            'production_standard_id' => $this->standard->id, 'mode' => 'tray',
            'nos_per_tray' => 100, 'trays_per_box' => 5, 'nos_per_box' => 500, 'is_default' => true,
        ]);

        // TIER 1 — the machine's own approved configuration, ACC-M1 only.
        $this->configuration = ProductionConfiguration::create([
            'work_center_id' => $this->m1->id,
            'item_id' => $this->bottle->id,
            'colour' => 'Amber',
            'default_cycle_time' => '12.30',
            'default_cavities' => 4,
            'unit_weight_grams' => '12.0000',
            'status' => ConfigurationStatus::Approved,
            'source' => 'ACC-ACCEPTANCE-FIXTURE',
        ]);

        $this->powerCut = DowntimeReason::create([
            'code' => 'ACC-DT-POWER', 'category' => 'Utilities', 'description' => 'ACC power outage',
            'planning_type' => 'unplanned', 'reduces_runtime' => true,
            'requires_note' => true, 'selectable_at_start' => true, 'is_active' => true,
        ]);

        // Raw material on hand, so a completion that books consumption is
        // never refused for a reason that has nothing to do with this chain.
        app(StockMovementService::class)->recordReceipt(
            itemId: $this->resin->id, warehouseId: $this->rm->id,
            quantity: '5000', unitCost: '0', reference: 'ACC-OPENING',
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // =================================================================
    // People. Four-eyes is real: every stage is a different account.
    // =================================================================

    private function actAs(string ...$roles): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $permissions = ['production.view', 'production.manage', 'inventory.view', 'inventory.manage', 'quality.view', 'quality.manage'];
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);
        foreach ($roles as $role) {
            $user->assignRole(Role::findOrCreate($role, 'web'));
        }
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);

        return $user;
    }

    private function asAgent(): static
    {
        if ($this->agentToken === null) {
            $this->agentToken = app(AgentTokenService::class)->issueToken('acc-factory-pc')['plainTextToken'];
        }
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->agentToken);
    }

    // =================================================================
    // The steps of the walk, each one exactly what the floor does.
    // =================================================================

    /** @return array<string, mixed> */
    private function preview(Item $item, WorkCenter $machine, ?Shift $shift = null): array
    {
        return $this->getJson('/api/v1/production/shift-production-entries/preview?'.http_build_query([
            'item_id' => $item->id,
            'work_center_id' => $machine->id,
            'warehouse_id' => $this->fg->id,
            'shift_id' => ($shift ?? $this->shiftA)->id,
        ]))->assertOk()->json('data');
    }

    private function startBatch(Shift $shift, WorkCenter $machine, string $date = self::DATE): int
    {
        return $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => $date,
        ])->assertOk()->json('data.id');
    }

    /** @param  array<string, mixed>  $extra */
    private function complete(int $entryId, int $boxes, int $perBox, array $extra = []): array
    {
        return $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", array_merge([
            'quantity_produced' => (string) ($boxes * $perBox),
            'no_of_box' => $boxes,
            'nos_per_box' => $perBox,
            'running_hours' => '8',
            'material_consumptions' => [[
                'item_id' => $this->resin->id,
                'warehouse_id' => $this->rm->id,
                'quantity_issued_kg' => '100.0000',
            ]],
        ], $extra))->assertOk()->json('data');
    }

    /** @param  array<string, mixed>  $counts */
    private function qualityCheck(int $entryId, array $counts): array
    {
        $this->actAs();

        return $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/quality-check", $counts)
            ->assertOk()->json('data');
    }

    private function pmApprove(int $entryId): void
    {
        $this->actAs('Plant Manager');
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/pm-approve")->assertOk();
    }

    private function accountantApprove(int $entryId): void
    {
        $this->actAs('Accounts');
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/accountant-approve")->assertOk();
    }

    /** @return list<int> */
    private function completedToday(string $date = self::DATE): array
    {
        $ids = array_column(
            $this->getJson('/api/v1/production/shift-production-entries?'.http_build_query([
                'production_date' => $date, 'batch_status' => 'completed', 'per_page' => 100,
            ]))->assertOk()->json('data'),
            'id',
        );
        sort($ids);

        return $ids;
    }

    // =================================================================
    // LINK A1 — the SKU is configured ONCE, and the floor asks nothing
    //           it already knows.
    // =================================================================

    public function test_a1_a_configured_sku_leaves_the_shift_floor_with_no_question_to_ask(): void
    {
        // The gate is ENFORCED for this link: "asks nothing" is only a claim
        // worth making when the gate is switched on and still silent.
        config()->set('production.readiness.enforced', true);
        $this->actAs();

        // ACC-M1: the machine's own approved configuration answers it.
        $configured = $this->preview($this->bottle, $this->m1);
        $this->assertSame([], $configured['warnings'], 'A configured SKU on a configured machine must raise no question.');
        $this->assertSame([], $configured['readiness']['blocking'] ?? [], 'Nothing may block a run the factory has already configured.');
        $this->assertNotNull($configured['configuration']);
        $this->assertSame('12.30', (string) $configured['estimation']['standard_cycle_time']);
        $this->assertSame(4, $configured['estimation']['active_cavities']);

        // ACC-M2: no machine configuration, so the recorded PRODUCT STANDARD
        // answers instead — still nothing to ask, and demonstrably a
        // different tier (16.00 / 3, not 12.30 / 4 and not 20.00 / 2).
        $standardRun = $this->preview($this->bottle, $this->m2);
        $this->assertSame([], $standardRun['warnings']);
        $this->assertSame([], $standardRun['readiness']['blocking'] ?? []);
        $this->assertNull($standardRun['configuration']);
        $this->assertSame('16.00', (string) $standardRun['estimation']['standard_cycle_time']);
        $this->assertSame(3, $standardRun['estimation']['active_cavities']);

        // THE FALSIFIER. Strip the knowledge and the floor asks: a product
        // with no standard, no configuration and no item-master figures
        // blocks on exactly the facts nobody recorded.
        $gap = $this->preview($this->gapBottle, $this->m2);
        $blocking = array_column($gap['readiness']['blocking'] ?? [], 'code');
        $this->assertContains('cycle_time', $blocking);
        $this->assertContains('cavities', $blocking);
        $this->assertContains('weight', $blocking);
    }

    // =================================================================
    // LINK A2 — Start Batch freezes the recorded standard, and stamps
    //           WHICH arithmetic this run's figures belong to.
    // =================================================================

    public function test_a2_start_batch_freezes_the_recorded_standard_under_its_own_calculation_version(): void
    {
        $this->actAs();

        $configuredId = $this->startBatch($this->shiftA, $this->m1);
        $configured = ShiftProductionEntry::findOrFail($configuredId);

        $this->assertSame('configuration', $configured->cycle_time_source);
        $this->assertSame('configuration', $configured->cavities_source);
        $this->assertSame(0, bccomp('12.30', (string) $configured->standard_cycle_time, 2));
        $this->assertSame(4, $configured->active_cavities);
        $this->assertSame('12.0000', $configured->config_snapshot['unit_weight_grams']);
        $this->assertSame($this->configuration->id, $configured->production_configuration_id);
        $this->assertSame($this->standard->id, $configured->production_standard_id);
        // The stamp — the whole point of versioning rather than migrating.
        $this->assertSame(ProductionCalculationEngine::VERSION_UNIFIED, $configured->calculation_version);

        $standardId = $this->startBatch($this->shiftA, $this->m2);
        $standardRun = ShiftProductionEntry::findOrFail($standardId);

        $this->assertSame('product_standard', $standardRun->cycle_time_source);
        $this->assertSame(0, bccomp('16.00', (string) $standardRun->standard_cycle_time, 2));
        $this->assertSame(3, $standardRun->active_cavities);
        $this->assertSame('15.0000', $standardRun->config_snapshot['unit_weight_grams']);
        $this->assertNull($standardRun->production_configuration_id);

        // Neither run took the item master's 20.00 / 2 / 18.0000 — so
        // "the recorded standard governed" is a claim with teeth.
        foreach ([$configured, $standardRun] as $entry) {
            $this->assertSame(-1, bccomp((string) $entry->standard_cycle_time, '20.00', 2));
            $this->assertNotSame(2, $entry->active_cavities);
            $this->assertNotSame('18.0000', $entry->config_snapshot['unit_weight_grams']);
        }
    }

    // =================================================================
    // LINK A3 — Complete Batch: expected · actual · good · reject ·
    //           packs · downtime · efficiency, all from the frozen
    //           standard under the entry's own stamp.
    // =================================================================

    public function test_a3_completion_reports_every_figure_from_the_frozen_standard_and_the_entrys_own_stamp(): void
    {
        $this->actAs();
        $entryId = $this->startBatch($this->shiftA, $this->m1);

        // 16 boxes x 500 = 8,000 pieces, 100 rejected on the floor,
        // 8 h typed with a 30-minute power cut recorded against the run.
        $data = $this->complete($entryId, boxes: 16, perBox: 500, extra: [
            'quantity_scrap' => '100',
            'downtime_events' => [
                ['downtime_reason_id' => $this->powerCut->id, 'minutes' => 30, 'note' => 'ACC 10:00-10:30 power cut'],
            ],
        ]);

        $entry = ShiftProductionEntry::findOrFail($entryId);
        $metrics = $data['metrics'];

        // -- downtime -------------------------------------------------
        $this->assertSame('30.00', $metrics['downtime_minutes_total']);
        $this->assertSame('7.50', $metrics['net_running_hours'], '8 h typed, 30 min netted.');
        $this->assertSame(0, bccomp('8', (string) $entry->running_hours, 2), 'The typed figure survives beside the net one.');

        // -- expected, computed BY THE ENTRY'S OWN STAMP --------------
        // Not a literal: the engine is asked with the entry's frozen CT,
        // its cavities, its net hours and its version. If the code ever
        // stopped honouring the stamp, this is what would catch it.
        $engine = app(ProductionCalculationEngine::class);
        $fromStamp = $engine->targetPieces(
            $metrics['net_running_hours'],
            (string) $entry->standard_cycle_time,
            $entry->active_cavities,
            (string) $entry->calculation_version,
        );
        $this->assertSame((float) $fromStamp, (float) $metrics['expected_pieces']);
        $this->assertSame(ProductionCalculationEngine::VERSION_UNIFIED, $metrics['calculation_version']);
        $this->assertSame($entry->calculation_version, $metrics['calculation_version']);
        // And the arithmetic, spelled out once so the walk is checkable by
        // hand: FLOOR(7.5 x 3600 / 12.30) = 2195 cycles x 4 = 8,780.
        $this->assertSame(8780.0, (float) $metrics['expected_pieces']);

        // -- actual, packs, good, reject, efficiency -------------------
        $this->assertSame(8000.0, (float) $metrics['actual_pieces']);
        $this->assertSame(16, $metrics['actual_boxes']);
        $this->assertSame(16, $data['no_of_box'], 'Packs are what the supervisor counted.');
        $this->assertSame(18, $metrics['expected_boxes'], 'ROUND(8780 / 500).');
        // 8,000 pieces x 12.0000 g (the CONFIGURATION's weight) = 96 kg.
        $this->assertSame(96.0, (float) $metrics['good_production_kg']);
        // 100 rejected x 12.0000 g = 1.2 kg, from the same frozen weight.
        $this->assertSame(1.2, (float) $metrics['rejection_kg_production']);
        $this->assertSame(1.2, (float) $metrics['confirmed_rejection_kg']);
        // 8000 / 8780 = 91.116... -> 91.1 at one decimal place.
        $this->assertSame(91.1, $metrics['efficiency_pct']);

        $this->assertSame(BatchStatus::Completed, $entry->batch_status);
    }

    public function test_a3b_the_stamp_is_load_bearing_a_different_version_moves_the_expected_figure(): void
    {
        // The negative control for A3. Same run, same frozen standard, same
        // hours — only the stamp changes, and the number must move. Without
        // this, A3 would pass even if the code ignored calculation_version
        // entirely.
        $this->actAs();
        $entryId = $this->startBatch($this->shiftA, $this->m1);
        $unified = $this->complete($entryId, boxes: 16, perBox: 500, extra: [
            'downtime_events' => [
                ['downtime_reason_id' => $this->powerCut->id, 'minutes' => 30, 'note' => 'ACC power cut'],
            ],
        ]);

        ShiftProductionEntry::query()->whereKey($entryId)
            ->update(['calculation_version' => ProductionCalculationEngine::VERSION_FLOOR]);

        $legacy = $this->getJson('/api/v1/production/shift-production-entries?'.http_build_query([
            'production_date' => self::DATE, 'batch_status' => 'completed', 'per_page' => 100,
        ]))->assertOk()->json('data');
        $legacyRow = collect($legacy)->firstWhere('id', $entryId);

        $this->assertSame(ProductionCalculationEngine::VERSION_FLOOR, $legacyRow['metrics']['calculation_version']);
        $this->assertNotSame(
            (float) $unified['metrics']['expected_pieces'],
            (float) $legacyRow['metrics']['expected_pieces'],
            'The unfloored legacy formula and the floored unified one must not agree here — if they do, the stamp is not being read.',
        );
    }

    // =================================================================
    // LINKS A4–A8 — one continuous day, walked once.
    // =================================================================

    public function test_a4_to_a8_the_day_walks_from_completed_today_to_the_shift_voucher(): void
    {
        $ids = $this->buildTheDay();

        // ---- A4 · COMPLETED TODAY -----------------------------------
        // Exactly the batches COMPLETED on this factory day: the running
        // one, the cancelled one and yesterday's are all absent.
        $expectedCompleted = [$ids['B1'], $ids['B2'], $ids['B3'], $ids['B7']];
        sort($expectedCompleted);
        $this->assertSame($expectedCompleted, $this->completedToday());
        $this->assertNotContains($ids['B4'], $this->completedToday(), 'A running batch is not production yet.');
        $this->assertNotContains($ids['B5'], $this->completedToday(), 'A cancelled batch never happened.');
        $this->assertSame([$ids['B6']], $this->completedToday(self::DAY_BEFORE));

        // It is the same set the database calls completed on the date —
        // the list is a read of the transaction model, not its own opinion.
        $fromModel = ShiftProductionEntry::query()
            ->whereDate('production_date', self::DATE)
            ->where('batch_status', BatchStatus::Completed)
            ->orderBy('id')->pluck('id')->all();
        $this->assertSame($expectedCompleted, $fromModel);

        // ---- A5 · SHIFT SUMMARY == COMPLETED PRODUCTION -------------
        $summaries = app(ShiftSummaryService::class);
        $shiftA = $summaries->report($this->shiftA->id, self::DATE);
        $shiftB = $summaries->report($this->shiftB->id, self::DATE);
        $day = $summaries->report(null, self::DATE);

        $this->assertSame($this->completedKg([$ids['B1'], $ids['B2'], $ids['B7']]), $shiftA['actual_production_kg']);
        $this->assertSame($this->completedKg([$ids['B3']]), $shiftB['actual_production_kg']);
        $this->assertSame($this->completedKg($expectedCompleted), $day['actual_production_kg']);
        $this->assertSame(
            bcadd($shiftA['actual_production_kg'], $shiftB['actual_production_kg'], 4),
            $day['actual_production_kg'],
            'The day is the sum of its shifts.',
        );

        // The QC reduction reached the summary: ACC-B2 was counted at 5,000
        // and quality rejected 50, so 4,950 x 15.0000 g = 74.2500 kg is what
        // the shift is credited with — never the 75.0000 the machine ejected.
        $b2 = ShiftProductionEntry::findOrFail($ids['B2']);
        $this->assertSame(4950.0, (float) $b2->quantity_produced);
        $this->assertSame(5000.0, (float) $b2->gross_quantity_produced);
        $this->assertSame(74.25, (float) $b2->quantity_produced_kg);
        $this->assertSame(0, bccomp('206.2500', $shiftA['actual_production_kg'], 4), 'ACC-B1 96.0000 + ACC-B2 74.2500 + ACC-B7 36.0000.');

        // The running batch contributes nothing to the shift it sits in.
        $this->assertSame(BatchStatus::InProgress, ShiftProductionEntry::findOrFail($ids['B4'])->batch_status);

        // ---- A6 · CEC == SHIFT SUMMARY (format BLOCKED) -------------
        $this->actAs();
        $cec = $this->getJson('/api/v1/production/cec?date='.self::DATE)->assertOk()->json('data');

        // The FORMAT is the owner's to give. No sample is on file, so the
        // endpoint says so about itself rather than inventing a layout —
        // this link is recorded BLOCKED in ACCEPTANCE-CHAIN.md, and the
        // golden harness (Production\CecGoldenTest) is what will close it.
        $this->assertSame(CecReportService::FORMAT, $cec['format']);
        $this->assertStringStartsWith('BLOCKED', $cec['format']);
        $this->assertSame(['shift_summary', 'shift_production_entries'], $cec['figures_from']);

        // What IS assertable today: the composition. The CEC's numbers are
        // the Shift Summary's numbers and the completed batches' numbers,
        // with no arithmetic of its own in between.
        $this->assertSame($this->asJson($day), $cec['day']['summary']);
        $blocks = collect($cec['shifts'])->keyBy(fn (array $block) => $block['shift']['id']);
        $this->assertSame($this->asJson($shiftA), $blocks[$this->shiftA->id]['summary']);
        $this->assertSame($this->asJson($shiftB), $blocks[$this->shiftB->id]['summary']);

        $cecBatches = collect($cec['shifts'])
            ->flatMap(fn (array $block) => collect($block['machines'])->flatMap(fn (array $machine) => $machine['batches']))
            ->keyBy('entry_id');
        $cecBatchIds = $cecBatches->keys()->sort()->values()->all();
        $this->assertSame($expectedCompleted, $cecBatchIds, 'The CEC lists the completed batches — no more, no fewer.');

        // And each CEC figure is the Completed Today row's own figure, not a
        // second opinion about the same batch.
        $indexRows = collect($this->getJson('/api/v1/production/shift-production-entries?'.http_build_query([
            'production_date' => self::DATE, 'batch_status' => 'completed', 'per_page' => 100,
        ]))->assertOk()->json('data'))->keyBy('id');
        foreach ($expectedCompleted as $id) {
            $row = $indexRows[$id];
            $batch = $cecBatches[$id];
            $this->assertSame($row['metrics']['expected_pieces'], $batch['expected_pieces']);
            $this->assertSame($row['metrics']['actual_pieces'], $batch['actual_pieces']);
            $this->assertSame($row['metrics']['good_production_kg'], $batch['good_production_kg']);
            $this->assertSame($row['metrics']['efficiency_pct'], $batch['efficiency_pct']);
            $this->assertSame($row['metrics']['calculation_version'], $batch['calculation_version']);
            $this->assertSame($row['no_of_box'], $batch['packs']);
            $this->assertSame($row['status'], $batch['approval_status']);
        }

        // ---- A7 · THE TALLY SHIFT VOUCHER ---------------------------
        // One voucher per (date, shift), and it contains EXACTLY the
        // accountant-approved entries. ACC-B2 is completed and PM-approved
        // but not accountant-approved, so it is visible everywhere above
        // and absent here.
        $this->assertSame(2, TallySyncEntry::query()->count(), 'Shift A and Shift B: one voucher each.');

        // Reached through the MEMBERSHIP COLUMN, not a JSON-path predicate:
        // a `payload->voucher_number` where-clause compiles differently per
        // driver, and this walk must mean the same thing on both.
        $voucherA = TallySyncEntry::findOrFail(ShiftProductionEntry::findOrFail($ids['B1'])->tally_sync_entry_id);
        $this->assertSame('Stock Journal', $voucherA->tally_voucher_type);
        $this->assertSame('SJ-20260803-S'.$this->shiftA->id, $voucherA->payload['voucher_number']);
        $this->assertSame(self::DATE, $voucherA->payload['voucher_date']);

        // BOTH accountant-approved entries of Shift A, and only those.
        $members = $voucherA->payload['entry_ids'];
        sort($members);
        $approvedInShiftA = [$ids['B1'], $ids['B7']];
        sort($approvedInShiftA);
        $this->assertSame($approvedInShiftA, $members, 'The voucher carries every approved entry of the shift — no fewer.');
        $this->assertNotContains($ids['B2'], $members, 'An entry the accountant has not approved never reaches Tally.');
        $this->assertSame($voucherA->id, ShiftProductionEntry::findOrFail($ids['B7'])->tally_sync_entry_id);
        $this->assertNull(ShiftProductionEntry::findOrFail($ids['B2'])->tally_sync_entry_id);

        $voucherB = TallySyncEntry::findOrFail(ShiftProductionEntry::findOrFail($ids['B3'])->tally_sync_entry_id);
        $this->assertNotSame($voucherA->id, $voucherB->id, 'A second shift is a second voucher.');
        $this->assertSame('SJ-20260803-S'.$this->shiftB->id, $voucherB->payload['voucher_number']);
        $this->assertSame([$ids['B3']], $voucherB->payload['entry_ids']);

        // The produced line is the summed NET figure of the shift's members:
        // ACC-B1 8,000 + ACC-B7 3,000, and not one piece of ACC-B2's 4,950.
        $this->assertSame('ACC FG Store', $voucherA->payload['produced'][0]['godown']);
        $this->assertSame(11000.0, (float) $voucherA->payload['produced'][0]['quantity']);
        $this->assertSame('ACC RM Store', $voucherA->payload['consumed'][0]['godown']);

        // ---- A8 · RELEASE GATE, AGENT ACK, SNAPSHOT -----------------
        $sync = app(TallySyncService::class);

        // The shift ended a fortnight ago, so the only live condition is the
        // idle hold: nothing is offered while the voucher is still fresh.
        $this->assertSame([], $sync->pending()->all(), 'A voucher that merged moments ago is still collecting.');
        $this->assertNull($voucherA->fresh()->delivered_at);

        $this->travelTo(Carbon::parse(self::NOW)->addMinutes(16));
        $offered = $sync->pending()->map(fn (TallySyncEntry $entry) => $entry->voucherNumber())->all();
        $this->assertContains($voucherA->voucherNumber(), $offered);
        $this->assertNotNull($voucherA->fresh()->delivered_at);

        // The agent acknowledges. This is the LOCAL cloud API answering the
        // agent's token — no Tally connection exists in this suite and none
        // is opened.
        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$voucherA->id}/ack")->assertOk();
        $this->assertSame(TallySyncStatus::Synced, $voucherA->fresh()->status);
        $this->assertSame('synced', ShiftProductionEntry::findOrFail($ids['B1'])->status->value);
        $this->assertSame('pm_approved', ShiftProductionEntry::findOrFail($ids['B2'])->status->value, 'A non-member entry is untouched by the ack — it is still waiting for the accountant.');

        // And uploads what it sent. A snapshot is an observation kept beside
        // the entry: it moves nothing.
        $xml = '<ENVELOPE><VOUCHERNUMBER>'.$voucherA->voucherNumber().'</VOUCHERNUMBER></ENVELOPE>';
        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$voucherA->id}/snapshot", [
            'xml' => $xml,
            'xml_sha256' => hash('sha256', $xml),
            'attempt' => 1,
            'agent_version' => '0.3.8',
            'tally' => ['success' => true, 'created' => 1, 'errors' => 0, 'message' => null, 'raw' => '<RESPONSE><CREATED>1</CREATED></RESPONSE>'],
        ])->assertCreated();

        $snapshot = TallySyncSnapshot::query()->sole();
        $this->assertSame($voucherA->id, $snapshot->tally_sync_entry_id);
        $this->assertSame(hash('sha256', $xml), $snapshot->xml_sha256);
        $this->assertSame(TallySyncStatus::Synced, $voucherA->fresh()->status);
    }

    // =================================================================
    // The day, built through the real paths and nothing else.
    // =================================================================

    /** @return array<string, int> */
    private function buildTheDay(): array
    {
        $ids = [];

        // ACC-B6 — the day before, so "today" has to mean today.
        $this->actAs();
        $ids['B6'] = $this->startBatch($this->shiftA, $this->m1, self::DAY_BEFORE);
        $this->complete($ids['B6'], boxes: 4, perBox: 500);

        // ACC-B1 — Shift A on the configured machine, all the way through.
        $this->actAs();
        $ids['B1'] = $this->startBatch($this->shiftA, $this->m1);
        $this->complete($ids['B1'], boxes: 16, perBox: 500, extra: [
            'quantity_scrap' => '100',
            'downtime_events' => [
                ['downtime_reason_id' => $this->powerCut->id, 'minutes' => 30, 'note' => 'ACC 10:00-10:30 power cut'],
            ],
        ]);
        $this->qualityCheck($ids['B1'], ['reviewed_nos' => 8000, 'ok_nos' => 8000, 'rejected_nos' => 0, 'note' => 'ACC clean check']);
        $this->pmApprove($ids['B1']);
        $this->accountantApprove($ids['B1']);

        // ACC-B5 — started by mistake on the same machine, withdrawn.
        $this->actAs();
        $ids['B5'] = $this->startBatch($this->shiftA, $this->m1);
        $this->postJson("/api/v1/production/shift-production-entries/{$ids['B5']}/cancel", [
            'reason' => 'ACC started on the wrong machine',
        ])->assertOk();

        // ACC-B3 — Shift B on the same machine, fully approved.
        $this->actAs();
        $ids['B3'] = $this->startBatch($this->shiftB, $this->m1);
        $this->complete($ids['B3'], boxes: 8, perBox: 500);
        $this->qualityCheck($ids['B3'], ['reviewed_nos' => 4000, 'ok_nos' => 4000, 'rejected_nos' => 0, 'note' => 'ACC clean check']);
        $this->pmApprove($ids['B3']);
        $this->accountantApprove($ids['B3']);

        // ACC-B7 — a SECOND fully-approved batch in Shift A. Without it,
        // "the voucher contains exactly the approved entries" only ever has
        // to get EXCLUSION right: a builder that silently dropped a member
        // would pass a one-member voucher. It also exercises the merge into
        // an undelivered voucher that A8's timing depends on.
        $this->actAs();
        $ids['B7'] = $this->startBatch($this->shiftA, $this->m1);
        $this->complete($ids['B7'], boxes: 6, perBox: 500);
        $this->qualityCheck($ids['B7'], ['reviewed_nos' => 3000, 'ok_nos' => 3000, 'rejected_nos' => 0, 'note' => 'ACC clean check']);
        $this->pmApprove($ids['B7']);
        $this->accountantApprove($ids['B7']);

        // ACC-B2 — Shift A on the UNCONFIGURED machine: the product standard
        // governs, quality rejects 50, and the accountant has NOT signed.
        $this->actAs();
        $ids['B2'] = $this->startBatch($this->shiftA, $this->m2);
        $this->complete($ids['B2'], boxes: 10, perBox: 500);
        $this->qualityCheck($ids['B2'], ['reviewed_nos' => 5000, 'ok_nos' => 4950, 'rejected_nos' => 50, 'note' => 'ACC neck finish']);
        $this->pmApprove($ids['B2']);

        // ACC-B4 — still on the machine when the walk is read.
        $this->actAs();
        $ids['B4'] = $this->startBatch($this->shiftA, $this->m2);

        return $ids;
    }

    /**
     * The Shift Summary as the API serialises it — the service answers with
     * Eloquent collections inside, and the CEC carries it through a JSON
     * response, so the two are compared on the ONE shape a client sees.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function asJson(array $report): array
    {
        return json_decode(json_encode($report), true);
    }

    /**
     * Σ quantity_produced_kg over the given completed entries, read off the
     * rows themselves — so the Shift Summary is compared against the
     * transaction model rather than against a number typed twice.
     *
     * @param  list<int>  $entryIds
     */
    private function completedKg(array $entryIds): string
    {
        $total = '0.0000';
        foreach (ShiftProductionEntry::query()->whereIn('id', $entryIds)->get() as $entry) {
            $this->assertSame(BatchStatus::Completed, $entry->batch_status);
            $total = bcadd($total, (string) ($entry->quantity_produced_kg ?? '0'), 4);
        }

        return $total;
    }
}
