<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Core\Services\AppSettingService;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Models\DowntimeReason;
use App\Modules\Production\Models\ScrapReason;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\FactoryDayBinService;
use App\Modules\Production\Services\ShiftProductionEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The Approve Production detail drawer's data contract.
 *
 * The drawer is not a second screen with its own fetch — it renders the row
 * the pending queue already returned, plus three read-only side fetches
 * (voucher preview, machine downtime logs, traceability lots). Every field it
 * dereferences must therefore be PRESENT in the queue payload: a JsonResource
 * whose relation was not eager-loaded emits MissingValue, Laravel strips the
 * key entirely, and the React render throws on `x.y` — blanking the whole
 * drawer with a 200 on the wire and nothing in the server log.
 *
 * That is exactly how the drawer broke on 30-Jul: `paginate()` eager-loaded
 * `materialConsumptions.item` but never `materialConsumptions.warehouse`,
 * while the consumption table renders `row.warehouse.code`. The bug was
 * latent for as long as completions carried no material lines; the day-bin
 * warehouse prefill shipped that morning made supervisors actually submit
 * them, and the first batch completed with the new form blanked the drawer.
 *
 * So these tests assert the SHAPE of every field the drawer reads, not just
 * a 200 — a 200 with a missing key is precisely the failure mode.
 */
class ApprovalDrawerContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // This suite exercises the approval payload, not the readiness gate —
        // same minimal-fixture rationale as CompletionDowntimeTest.
        config()->set('production.readiness.enforced', false);
    }

    private function actAs(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['production.view', 'production.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * A batch completed the way the floor completes one now: packing lines,
     * a lumps scrap line, resin issued FROM THE DAY-BIN WAREHOUSE, and a
     * completion downtime line.
     *
     * @return array{entry: ShiftProductionEntry, response: TestResponse, day_bin: Warehouse, resin: Item, machine: WorkCenter}
     */
    private function completedBatch(): array
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
        $fgStore = Warehouse::create(['code' => 'FG-STORE', 'name' => 'Finished Goods']);
        $dayBin = Warehouse::create(['code' => 'DAY-BIN', 'name' => 'Factory Day Bin']);
        $bottle = Item::create([
            'sku' => 'BTL-1', 'name' => '1 Litre Pet Bottle', 'uom' => 'NOS',
            'standard_cycle_time' => '12', 'standard_cavities' => 5,
            'nos_per_box' => 1040, 'nominal_weight_grams' => '20',
        ]);
        $resin = Item::create(['sku' => 'RM-PET', 'name' => 'PET Resin', 'uom' => 'Kgs']);
        $reason = ScrapReason::create(['code' => 'SR-FLASH', 'name' => 'Flashing', 'is_active' => true]);
        $power = DowntimeReason::create([
            'code' => 'DT-POWER', 'category' => 'Utilities', 'description' => 'Power outage',
            'planning_type' => 'unplanned', 'reduces_runtime' => true,
            'requires_note' => true, 'selectable_at_start' => true, 'is_active' => true,
        ]);

        // The day bin is a real warehouse holding real stock — the resin line
        // below is issued out of it, so it has to be there to issue.
        app(AppSettingService::class)->set(FactoryDayBinService::SETTING_KEY, (string) $dayBin->id);
        app(StockMovementService::class)->recordReceipt(
            itemId: $resin->id, warehouseId: $dayBin->id, quantity: '500', unitCost: '82.5000',
        );

        $entry = app(ShiftProductionEntryService::class)->startBatch([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $bottle->id,
            'warehouse_id' => $fgStore->id,
            'production_date' => '2026-07-30',
        ], null);

        $response = $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '10400',
            'quantity_scrap' => '120',
            'scrap_reason_id' => $reason->id,
            'no_of_box' => 10,
            'nos_per_box' => 1040,
            'loose_pieces' => 0,
            'running_hours' => '8',
            'qc_rejection_kg' => '2.5',
            'packing_lines' => [[
                'mode' => 'direct_box',
                'boxes' => 10,
                'nos_per_box' => 1040,
                'derived_pieces' => 10400,
                'actual_pieces' => 10400,
            ]],
            'material_consumptions' => [[
                'item_id' => $resin->id,
                'warehouse_id' => $dayBin->id,
                'quantity_issued_kg' => '215.5',
            ]],
            'scraps' => [
                ['type' => 'lumps', 'quantity_kg' => '4.25'],
                ['type' => 'rejected_finished_good', 'quantity_nos' => '120', 'quantity_kg' => '2.4', 'scrap_reason_id' => $reason->id],
            ],
            'downtime_events' => [
                ['downtime_reason_id' => $power->id, 'minutes' => 20, 'note' => '10:10–10:30 power cut'],
            ],
        ]);

        $response->assertOk();

        return ['entry' => $entry, 'response' => $response, 'day_bin' => $dayBin, 'resin' => $resin, 'machine' => $machine];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function assertDrawerCanRender(array $row, string $where): void
    {
        // Row-level relations the list AND the drawer title dereference.
        foreach (['shift.name', 'work_center.name', 'item.sku', 'item.name'] as $path) {
            $this->assertNotNull(data_get($row, $path), "{$where}: {$path} is missing — the drawer title reads it unguarded");
        }

        // The consumption table: `itemLabel(row.item)` and `row.warehouse.code`.
        $this->assertArrayHasKey('material_consumptions', $row, "{$where}: material_consumptions absent — the drawer reads .length on it");
        $this->assertCount(1, $row['material_consumptions']);
        $line = $row['material_consumptions'][0];
        $this->assertArrayHasKey('item', $line, "{$where}: consumption line has no item — itemLabel(row.item) throws");
        $this->assertArrayHasKey('warehouse', $line, "{$where}: consumption line has no warehouse — row.warehouse.code throws and blanks the drawer");
        $this->assertSame('RM-PET', $line['item']['sku']);
        $this->assertSame('DAY-BIN', $line['warehouse']['code']);
        // The unit the quantity cell prints beside the figure. Both drawers
        // read the line's OWN item uom now — the column used to be headed a
        // blanket "Kg", so 500 cartons read as half a tonne of cardboard —
        // and both fall back to "Kg" when the key is absent. That fallback is
        // deliberate (it matches the server's kg-family default for an
        // unlabelled master) and it means a dropped `uom`, or a future detail
        // endpoint that forgets to eager-load materialConsumptions.item, would
        // silently reinstate the old lie with nothing failing. So the key is
        // pinned here rather than left to the eager load's good behaviour.
        $this->assertArrayHasKey('uom', $line['item'], "{$where}: consumption line item has no uom — every line falls back to \"Kg\"");
        $this->assertSame('Kgs', $line['item']['uom']);
        // Numeric compare: the column has no decimal cast, so the driver
        // decides the wire type (MySQL hands back "215.5000", SQLite 215.5).
        // The cell only prints it, so either is fine — the figure is what
        // must be right.
        $this->assertEqualsWithDelta(215.5, (float) $line['quantity_issued_kg'], 0.0001);

        // The scrap table, including the lumps line the new form writes.
        $this->assertArrayHasKey('scraps', $row, "{$where}: scraps absent — the drawer reads .length on it");
        $types = array_column($row['scraps'], 'type');
        $this->assertContains('lumps', $types);
        $this->assertContains('rejected_finished_good', $types);

        // Downtime events and the netted metrics keys the drawer prints.
        $this->assertSame('Power outage', data_get($row, 'downtime_events.0.reason.description'), "{$where}: downtime reason label missing");
        foreach (['expected_pieces', 'efficiency_pct', 'downtime_minutes_total', 'net_running_hours', 'lumps_kg', 'issued_kg'] as $key) {
            $this->assertArrayHasKey($key, $row['metrics'], "{$where}: metrics.{$key} missing");
        }
        $this->assertSame('4.2500', $row['metrics']['lumps_kg']);
        $this->assertSame('215.5000', $row['metrics']['issued_kg']);
    }

    // =================================================================
    // The queue payload the drawer renders from
    // =================================================================

    public function test_the_pending_queue_carries_every_field_the_detail_drawer_dereferences(): void
    {
        $this->actAs();
        ['entry' => $entry] = $this->completedBatch();

        $row = collect(
            $this->getJson('/api/v1/production/shift-production-entries?status=pending')->assertOk()->json('data')
        )->firstWhere('id', $entry->id);

        $this->assertNotNull($row, 'the completed batch is not in the pending queue');
        $this->assertDrawerCanRender($row, 'pending queue');
    }

    public function test_a_retired_godown_is_still_named_on_the_line_it_issued(): void
    {
        $this->actAs();
        ['entry' => $entry, 'day_bin' => $dayBin] = $this->completedBatch();

        // Warehouses soft-delete. History must still say where the material
        // came from — and the key must stay present either way, because a
        // null warehouse renders "—" while an ABSENT one used to throw.
        $dayBin->delete();

        $row = collect(
            $this->getJson('/api/v1/production/shift-production-entries?status=pending')->assertOk()->json('data')
        )->firstWhere('id', $entry->id);

        $this->assertArrayHasKey('warehouse', $row['material_consumptions'][0]);
        $this->assertSame('DAY-BIN', $row['material_consumptions'][0]['warehouse']['code']);
    }

    public function test_the_completion_response_carries_the_same_fields(): void
    {
        $this->actAs();
        ['response' => $response] = $this->completedBatch();

        // The floor screen renders this payload straight after Complete
        // Batch, so it must satisfy the same contract as the queue row.
        $this->assertDrawerCanRender($response->json('data'), 'completion response');
    }

    // =================================================================
    // The three read-only fetches the drawer fires when it opens
    // =================================================================

    public function test_the_drawers_voucher_preview_resolves_for_a_batch_completed_with_the_new_form(): void
    {
        $this->actAs();
        ['entry' => $entry] = $this->completedBatch();

        $preview = $this->getJson("/api/v1/production/shift-production-entries/{$entry->id}/voucher-preview")
            ->assertOk()
            ->json('data');

        // The section reads postable / problems / lines[].{side,item,quantity,uom,godown,problems}.
        $this->assertIsBool($preview['postable']);
        $this->assertIsArray($preview['problems']);
        $this->assertNotEmpty($preview['lines']);
        foreach ($preview['lines'] as $line) {
            foreach (['side', 'item', 'quantity', 'uom', 'godown', 'problems'] as $key) {
                $this->assertArrayHasKey($key, $line, "voucher line is missing {$key}");
            }
            $this->assertIsArray($line['problems']);
        }
    }

    public function test_the_drawers_machine_downtime_log_fetch_still_answers(): void
    {
        $this->actAs();
        $this->completedBatch();

        // Completion downtime lives in production_downtime_events, NOT in the
        // stopwatch log — so an empty list is the correct answer here, and the
        // section says "no breakdown logged" rather than blanking. What must
        // never happen is a 500/404 that leaves the drawer half-rendered.
        $body = $this->getJson('/api/v1/production/machine-downtime-logs')->assertOk()->json();
        $this->assertIsArray($body['data']);
    }

    public function test_the_drawers_traceability_fetch_404s_only_when_the_flag_is_off(): void
    {
        $this->actAs();
        ['entry' => $entry] = $this->completedBatch();
        $query = 'date_from=2026-04-29&date_to='.$entry->production_date->toDateString();

        // Flag off is the live server's state: the route 404s and the drawer's
        // Source Material section degrades to "traceability is switched off".
        config(['production.traceability_enabled' => false]);
        $this->getJson("/api/v1/production/reports/traceability?{$query}")->assertNotFound();

        // Flag on: a real payload, and the section reads lot.bags[].fed[].
        config(['production.traceability_enabled' => true]);
        $lots = $this->getJson("/api/v1/production/reports/traceability?{$query}")->assertOk()->json('data');
        $this->assertIsArray($lots);
    }
}
