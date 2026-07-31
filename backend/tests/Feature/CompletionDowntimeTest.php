<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\DowntimeReason;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\ProductionDowntimeEvent;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ShiftProductionEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Downtime at completion (owner, 30-Jul: "if power outage need to add the
 * power outage and mold change they need add with timing … i want to do
 * this for efficiency"):
 *
 *   1. Completion accepts downtime_events lines (reason + minutes + note —
 *      minutes is the stored shape of production_downtime_events; the note
 *      carries the from–to text) and persists them against the entry AND
 *      its machine, inside the completion transaction.
 *   2. productionMetrics() nets those minutes out of running hours BEFORE
 *      the WB2 expected-output formula — the formula itself and
 *      calculation_version are untouched, and a batch with no downtime
 *      lines computes byte-identically to before (pinned below).
 *   3. Efficiency is PIECE-grain: actual pieces / expected pieces. The
 *      owner's live batch (3 boxes + 5,208 loose = 14,322 pieces vs
 *      13,333 expected) read "75%" under the old box ratio while the
 *      machine was actually running at ~107% — pinned below with exactly
 *      those figures.
 *   4. Anything ABOVE the standard bands as 'over_standard' (owner, 30-Jul:
 *      "the efficiency should not go more than 100%") — a warning that an
 *      input needs correcting, outranking ok/watch/investigate, never a
 *      block. Boundary (exactly 100 = ok) and the configurable threshold
 *      are pinned below alongside the unchanged sub-100 bands.
 */
class CompletionDowntimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // This suite exercises completion-time downtime, not the readiness
        // gate — same minimal-fixture rationale as ExpectedOutputEngineTest.
        config()->set('production.readiness.enforced', false);
    }

    private function actAs(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.manage', 'web');
        $user->givePermissionTo('production.manage');
        Sanctum::actingAs($user);

        return $user;
    }

    /** @return array{0: DowntimeReason, 1: DowntimeReason} */
    private function reasons(): array
    {
        $power = DowntimeReason::create([
            'code' => 'DT-POWER', 'category' => 'Utilities', 'description' => 'Power outage',
            'planning_type' => 'unplanned', 'reduces_runtime' => true,
            'requires_note' => true, 'selectable_at_start' => true, 'is_active' => true,
        ]);
        $mould = DowntimeReason::create([
            'code' => 'DT-MOULD', 'category' => 'Changeover', 'description' => 'Mould change',
            'planning_type' => 'planned', 'reduces_runtime' => true,
            'requires_note' => false, 'selectable_at_start' => true, 'is_active' => true,
        ]);

        return [$power, $mould];
    }

    /**
     * Start a real batch (8 h shift → scheduled_hours 8.00) on WB2 row 7
     * standards: CT 12 × 5 cavities × 8 h = 12,000 expected pieces,
     * pack 1040 → 12 boxes.
     *
     * @return array{0: ShiftProductionEntry, 1: Shift}
     */
    private function runningBatch(string $cycleTime = '12', int $nosPerBox = 1040): array
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
        $warehouse = Warehouse::create(['code' => 'WH-1', 'name' => 'FG Store']);
        $item = Item::create([
            'sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'NOS',
            'standard_cycle_time' => $cycleTime, 'standard_cavities' => 5, 'nos_per_box' => $nosPerBox,
        ]);

        $entry = app(ShiftProductionEntryService::class)->startBatch([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => '2026-07-30',
        ], null);

        return [$entry, $shift];
    }

    // =================================================================
    // (a) Two lines persist, expected output shrinks by the netted time
    // =================================================================

    public function test_completion_with_two_downtime_lines_persists_both_and_nets_the_hours(): void
    {
        $this->actAs();
        [$power, $mould] = $this->reasons();
        [$entry] = $this->runningBatch();

        $response = $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '10400',
            'no_of_box' => 10,
            'nos_per_box' => 1040,
            'running_hours' => '8',
            'downtime_events' => [
                ['downtime_reason_id' => $power->id, 'minutes' => 20, 'note' => '14:30–14:50 power cut'],
                ['downtime_reason_id' => $mould->id, 'minutes' => 10],
            ],
        ])->assertOk();

        // Both lines persisted, linked to the entry AND its machine, and
        // stamped as NOT known before start — they explain the run, they
        // never rewrite the Start-time target.
        $events = ProductionDowntimeEvent::query()->get();
        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $this->assertSame($entry->id, $event->shift_production_entry_id);
            $this->assertSame($entry->work_center_id, $event->work_center_id);
            $this->assertFalse($event->known_before_start);
        }
        // is_planned follows the reason's own planning type.
        $this->assertFalse($events->firstWhere('downtime_reason_id', $power->id)->is_planned);
        $this->assertTrue($events->firstWhere('downtime_reason_id', $mould->id)->is_planned);

        // Expected output shrinks by EXACTLY the netted time: 8 h − 30 min
        // = 7.5 h → 3600/12 × 5 × 7.5 = 11,250 pieces (was 12,000);
        // /1040 = 10.817 → 11 boxes (was 12).
        $response->assertJsonPath('data.metrics.expected_pieces', '11250.00')
            ->assertJsonPath('data.metrics.expected_boxes', 11)
            ->assertJsonPath('data.metrics.downtime_minutes_total', '30.00')
            ->assertJsonPath('data.metrics.net_running_hours', '7.50')
            // The raw typed figure survives untouched next to the net one.
            ->assertJsonPath('data.running_hours', '8.00')
            // Piece-grain efficiency against the NET expectation:
            // 10400/11250 = 92.444… → 92.4.
            ->assertJsonPath('data.metrics.efficiency_pct', 92.4);
    }

    // =================================================================
    // (b) Regression pin: no downtime lines → identical to before
    // =================================================================

    public function test_a_batch_with_no_downtime_lines_computes_exactly_as_before(): void
    {
        $this->actAs();
        $this->reasons();
        [$entry] = $this->runningBatch();

        // Same completion as (a) minus the downtime lines. Every figure is
        // the pre-downtime-feature value (WB2 row 7: 12,000 pieces, 12
        // boxes) — the netting must be a byte-identical no-op when there
        // is nothing to net. NEVER weaken this pin.
        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '10400',
            'no_of_box' => 10,
            'nos_per_box' => 1040,
            'running_hours' => '8',
        ])->assertOk()
            ->assertJsonPath('data.metrics.expected_pieces', '12000.00')
            ->assertJsonPath('data.metrics.expected_boxes', 12)
            ->assertJsonPath('data.metrics.downtime_minutes_total', '0.00')
            ->assertJsonPath('data.metrics.net_running_hours', '8.00')
            ->assertJsonPath('data.running_hours', '8.00')
            // 10400/12000 = 86.666… → 86.7 (piece grain).
            ->assertJsonPath('data.metrics.efficiency_pct', 86.7);

        $this->assertSame(0, ProductionDowntimeEvent::query()->count());
    }

    // =================================================================
    // (3b) Efficiency grain: the owner's live batch, exact figures
    // =================================================================

    public function test_efficiency_is_piece_grain_the_owners_batch_reads_107_not_75(): void
    {
        $this->actAs();
        // CT 10.8 × 5 cavities × 8 h = 13,333.33 expected pieces; pack
        // 3038 → 4 expected boxes. The floor counted 3 full boxes plus
        // 5,208 loose pieces = 14,322 — the machine ran OVER standard.
        [$entry] = $this->runningBatch(cycleTime: '10.8', nosPerBox: 3038);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '14322',
            'no_of_box' => 3,
            'nos_per_box' => 3038,
            'loose_pieces' => 5208,
            'running_hours' => '8',
        ])->assertOk()
            ->assertJsonPath('data.metrics.expected_pieces', '13333.33')
            ->assertJsonPath('data.metrics.expected_boxes', 4)
            ->assertJsonPath('data.metrics.actual_boxes', 3)
            // 14322 / 13333.333… × 100 = 107.415 → 107.4. The old box
            // ratio (3/4 = 75.0) threw the 5,208 loose pieces away and
            // told the owner an over-standard run was failing.
            ->assertJsonPath('data.metrics.efficiency_pct', 107.4)
            // The PCT IS UNCHANGED and always was right — what changed is
            // the DISPLAY RULE, by owner instruction (30-Jul): "the
            // efficiency should not go more than 100%. if a machine can
            // produce a certain [amount] of material how can it be more
            // than that … if it was high, then we need to display error to
            // correct the entry, maybe standard cycle time can be reduced".
            // So 107.4 no longer bands as the greenest 'ok'; it bands as
            // over_standard, which every screen renders as a loud warning
            // to check produced count / hours / cavities, and failing those
            // to correct the standard cycle time. Do NOT re-band this to
            // 'ok' — the number being over 100 IS the finding.
            ->assertJsonPath('data.metrics.efficiency_band', 'over_standard')
            // …and it stays a WARNING, never a gate. blocks_approval keys
            // only off unaccounted_blocking_kg; an over-standard run must
            // remain approvable, because the pieces were genuinely made.
            ->assertJsonPath('data.metrics.blocks_approval', false);
    }

    // =================================================================
    // (3c) The over-100 band: boundary, precedence, config override
    // =================================================================

    public function test_exactly_one_hundred_percent_is_ok_not_over_standard(): void
    {
        $this->actAs();
        // runningBatch() defaults: CT 12 × 5 cavities × 8 h = 12,000
        // expected pieces. Producing exactly 12,000 is the standard MET,
        // not beaten — the boundary is strict `>`, so this must stay 'ok'.
        [$entry] = $this->runningBatch();

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '12000',
            'no_of_box' => 11,
            'nos_per_box' => 1040,
            'running_hours' => '8',
        ])->assertOk()
            ->assertJsonPath('data.metrics.expected_pieces', '12000.00')
            // 100 not 100.0 — a whole-number float decodes as an int over
            // JSON, same as the other whole percentages in the suite.
            ->assertJsonPath('data.metrics.efficiency_pct', 100)
            ->assertJsonPath('data.metrics.efficiency_band', 'ok');
    }

    public function test_a_hair_over_one_hundred_is_over_standard_and_the_threshold_is_configurable(): void
    {
        $this->actAs();
        // 12,060 against 12,000 expected = 100.5 — barely over, and still
        // over: the default tolerance is exactly 100 because a machine
        // cannot beat its own standard.
        [$entry] = $this->runningBatch();

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '12060',
            'no_of_box' => 11,
            'nos_per_box' => 1040,
            'running_hours' => '8',
        ])->assertOk()
            ->assertJsonPath('data.metrics.efficiency_pct', 100.5)
            ->assertJsonPath('data.metrics.efficiency_band', 'over_standard');

        // Same batch, same 100.5 — a factory that later decides to allow a
        // 5% measurement margin moves the boundary from .env alone, with no
        // deploy and no code change, and the identical figure bands 'ok'.
        config(['production.tolerances.efficiency_over' => 105.0]);
        $metrics = app(ShiftProductionEntryService::class)
            ->productionMetrics($entry->fresh());

        $this->assertSame(100.5, $metrics['efficiency_pct'], 'The pct is a fact; only the band is configurable');
        $this->assertSame('ok', $metrics['efficiency_band']);
    }

    public function test_bands_below_one_hundred_are_untouched_by_the_over_standard_rule(): void
    {
        $this->actAs();
        [$entry] = $this->runningBatch();

        // 11,500 / 12,000 = 95.8 → 'ok' (>= efficiency_ok 95).
        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '11500',
            'no_of_box' => 11,
            'nos_per_box' => 1040,
            'running_hours' => '8',
        ])->assertOk()
            ->assertJsonPath('data.metrics.efficiency_pct', 95.8)
            ->assertJsonPath('data.metrics.efficiency_band', 'ok');

        $service = app(ShiftProductionEntryService::class);

        // 10,800 / 12,000 = 90.0 → 'watch' (>= 85, < 95).
        $entry->update(['quantity_produced' => '10800']);
        $metrics = $service->productionMetrics($entry->fresh());
        $this->assertSame(90.0, $metrics['efficiency_pct']);
        $this->assertSame('watch', $metrics['efficiency_band']);

        // 9,600 / 12,000 = 80.0 → 'investigate' (< 85).
        $entry->update(['quantity_produced' => '9600']);
        $metrics = $service->productionMetrics($entry->fresh());
        $this->assertSame(80.0, $metrics['efficiency_pct']);
        $this->assertSame('investigate', $metrics['efficiency_band']);
    }

    // =================================================================
    // (c) Invalid lines are a 422 and nothing persists
    // =================================================================

    public function test_invalid_downtime_lines_are_refused_and_nothing_persists(): void
    {
        $this->actAs();
        [$power] = $this->reasons();
        [$entry] = $this->runningBatch();

        // Unknown reason.
        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '10400',
            'running_hours' => '8',
            'downtime_events' => [
                ['downtime_reason_id' => 999999, 'minutes' => 30, 'note' => 'x'],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['downtime_events.0.downtime_reason_id']);

        // Negative duration — the "ended before it started" of the
        // minutes shape.
        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '10400',
            'running_hours' => '8',
            'downtime_events' => [
                ['downtime_reason_id' => $power->id, 'minutes' => -15, 'note' => 'x'],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['downtime_events.0.minutes']);

        // Longer than the scheduled shift (8 h → 480 min cap). Overlapping
        // lines stay allowed — one power cut hits every machine at once —
        // but no single interruption can exceed the shift that held it.
        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '10400',
            'running_hours' => '8',
            'downtime_events' => [
                ['downtime_reason_id' => $power->id, 'minutes' => 481, 'note' => 'x'],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['downtime_events.0.minutes']);

        // A reason that requires a note refuses a blank one — the 422
        // lands on the exact line and field.
        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '10400',
            'running_hours' => '8',
            'downtime_events' => [
                ['downtime_reason_id' => $power->id, 'minutes' => 30],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['downtime_events.0.note']);

        // Nothing persisted anywhere, and the batch is still running.
        $this->assertSame(0, ProductionDowntimeEvent::query()->count());
        $this->assertSame(BatchStatus::InProgress, $entry->fresh()->batch_status);
    }

    public function test_an_entry_without_a_scheduled_shift_falls_back_to_the_24h_cap(): void
    {
        $this->actAs();
        [$power] = $this->reasons();
        [$entry] = $this->runningBatch();
        // Simulate a legacy row with no scheduled_hours snapshot.
        ShiftProductionEntry::query()->whereKey($entry->id)->update(['scheduled_hours' => null]);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '10400',
            'running_hours' => '8',
            'downtime_events' => [
                ['downtime_reason_id' => $power->id, 'minutes' => 1441, 'note' => 'x'],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['downtime_events.0.minutes']);

        $this->assertSame(0, ProductionDowntimeEvent::query()->count());
    }

    // =================================================================
    // (d) The events come back in the resource
    // =================================================================

    public function test_downtime_events_come_back_in_the_resource_with_reason_label(): void
    {
        $this->actAs();
        [$power] = $this->reasons();
        [$entry] = $this->runningBatch();

        // The complete response carries them immediately…
        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '10400',
            'no_of_box' => 10,
            'nos_per_box' => 1040,
            'running_hours' => '8',
            'downtime_events' => [
                ['downtime_reason_id' => $power->id, 'minutes' => 30, 'note' => '13:05–13:35 power cut'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.downtime_events.0.reason.description', 'Power outage')
            ->assertJsonPath('data.downtime_events.0.reason.code', 'DT-POWER')
            ->assertJsonPath('data.downtime_events.0.minutes', '30.00')
            ->assertJsonPath('data.downtime_events.0.note', '13:05–13:35 power cut')
            ->assertJsonPath('data.downtime_events.0.known_before_start', false)
            ->assertJsonPath('data.metrics.downtime_minutes_total', '30.00');

        // …and so does the approval list.
        $row = collect($this->getJson('/api/v1/production/shift-production-entries?status=pending')->assertOk()->json('data'))
            ->firstWhere('id', $entry->id);
        $this->assertSame('Power outage', $row['downtime_events'][0]['reason']['description']);
        $this->assertSame('30.00', $row['downtime_events'][0]['minutes']);
        $this->assertSame('30.00', $row['metrics']['downtime_minutes_total']);
    }

    // =================================================================
    // Handover shares the same rules and persistence
    // =================================================================

    public function test_handover_completion_records_downtime_against_the_outgoing_segment(): void
    {
        config(['production.traceability_enabled' => true]);
        $this->actAs();
        [, $mould] = $this->reasons();
        [$entry] = $this->runningBatch();
        $evening = Shift::create(['name' => 'Evening', 'start_time' => '14:00', 'end_time' => '22:00']);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/handover", [
            'shift_id' => $evening->id,
            'completion' => [
                'quantity_produced' => '10400',
                'no_of_box' => 10,
                'nos_per_box' => 1040,
                'running_hours' => '8',
                'downtime_events' => [
                    ['downtime_reason_id' => $mould->id, 'minutes' => 60, 'note' => 'mould change 06:00–07:00'],
                ],
            ],
        ])->assertSuccessful();

        // The event belongs to the OUTGOING (completed) segment.
        $events = ProductionDowntimeEvent::query()->get();
        $this->assertCount(1, $events);
        $this->assertSame($entry->id, $events[0]->shift_production_entry_id);
        $this->assertSame($entry->work_center_id, $events[0]->work_center_id);
        $this->assertFalse($events[0]->known_before_start);

        // And nets that segment's hours: 8 h − 60 min = 7 h →
        // 3600/12 × 5 × 7 = 10,500 expected pieces.
        $metrics = app(ShiftProductionEntryService::class)->productionMetrics($entry->fresh());
        $this->assertSame('10500.00', $metrics['expected_pieces']);
        $this->assertSame('60.00', $metrics['downtime_minutes_total']);
        $this->assertSame('7.00', $metrics['net_running_hours']);
    }

    public function test_a_handover_downtime_line_longer_than_the_shift_is_refused(): void
    {
        config(['production.traceability_enabled' => true]);
        $this->actAs();
        [$power] = $this->reasons();
        [$entry] = $this->runningBatch();
        $evening = Shift::create(['name' => 'Evening', 'start_time' => '14:00', 'end_time' => '22:00']);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/handover", [
            'shift_id' => $evening->id,
            'completion' => [
                'quantity_produced' => '10400',
                'running_hours' => '8',
                'downtime_events' => [
                    ['downtime_reason_id' => $power->id, 'minutes' => 481, 'note' => 'x'],
                ],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['completion.downtime_events.0.minutes']);

        $this->assertSame(0, ProductionDowntimeEvent::query()->count());
        $this->assertSame(BatchStatus::InProgress, $entry->fresh()->batch_status);
    }
}
