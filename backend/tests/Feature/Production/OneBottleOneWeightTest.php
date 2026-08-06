<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ONE BOTTLE, ONE WEIGHT — and the screen must be told which one.
 *
 * THE DEFECT THIS CLOSES. Every kilogram the server stores on an entry is
 * computed by resolvedUnitWeightGrams(): the weight this run froze at Start
 * first, the item master only as a fallback. The completion drawer previewed its
 * kilograms from the item master ALONE, because the frozen weight was
 * write-only — no client could read it back.
 *
 * Those two agree whenever no configuration overrode the weight, and diverge the
 * moment one does. Silently: the screen shows one figure, the entry holds
 * another, and nothing says which the batch was recorded at.
 *
 * The factory found the same class of defect the hard way on 5 August. The owner
 * checked a batch with a pencil and found 133.09 kg of resin where his paper said
 * 123.80 — two different weights on one panel, under a line claiming they were
 * the same. That one was caught because a person did the arithmetic. This one
 * would not be.
 */
class OneBottleOneWeightTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Warehouse $godown;

    private Shift $shift;

    private WorkCenter $machine;

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.enforced' => false]);

        $this->godown = Warehouse::create([
            'code' => 'SWA', 'name' => 'SWAASHPET POLYMERS PVT LTD', 'is_active' => true,
            'tally_guid' => '7cabb80e-0000-0000-0000-00000000003e',
        ]);

        // The real product and the real weight from the paper's ASB-1 row.
        $this->bottle = Item::create([
            'sku' => 'B100RC840', 'name' => 'B.100 Ml Round Clear Pet Bottle - 840',
            'uom' => 'Nos.', 'nominal_weight_grams' => '12.0000', 'is_active' => true,
        ]);

        $this->shift = Shift::create(['name' => 'A', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $this->machine = WorkCenter::create(['code' => 'ASB-1', 'name' => 'ASB-1', 'is_active' => true]);

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        Permission::findOrCreate('production.manage', 'web');
        $user->givePermissionTo(['production.view', 'production.manage']);
        $this->actingAs($user);
    }

    private function entry(?string $snapshotWeight): ShiftProductionEntry
    {
        return ShiftProductionEntry::create([
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->godown->id,
            'production_date' => '2026-08-05',
            'batch_number' => '20260805-ASB1-001',
            'batch_status' => BatchStatus::InProgress,
            'status' => ShiftProductionEntryStatus::Pending,
            'config_snapshot' => $snapshotWeight === null ? [] : ['unit_weight_grams' => $snapshotWeight],
        ]);
    }

    /**
     * The weight as a CLIENT sees it. Read off the list endpoint the completion
     * drawer actually uses — there is no single-entry route, and asserting on
     * the resource class directly would prove the field exists without proving
     * a screen can reach it.
     */
    private function weightOnTheWire(ShiftProductionEntry $entry): ?string
    {
        $rows = $this->getJson('/api/v1/production/shift-production-entries?per_page=50')
            ->assertOk()
            ->json('data');

        $row = collect($rows)->firstWhere('id', $entry->id);

        $this->assertNotNull($row, 'The entry must be readable by a client at all.');

        return $row['unit_weight_grams'] ?? null;
    }

    public function test_a_run_that_froze_a_weight_reports_that_weight_not_the_item_masters(): void
    {
        // The divergence case. The item master says 12.0 g; this run was started
        // against a configuration that says 12.9. The server will store its
        // kilograms at 12.9, so the screen has to preview at 12.9.
        $this->assertSame('12.9000', $this->weightOnTheWire($this->entry('12.9000')));
    }

    public function test_a_run_that_froze_no_weight_reports_null_so_the_item_master_stands(): void
    {
        // Null is the real answer, not zero and not the item's figure echoed
        // back. The screen falls through to the item master, which IS the truth
        // for this run rather than a guess at it — and a resource that echoed
        // the item weight here would make the two cases indistinguishable.
        $this->assertNull($this->weightOnTheWire($this->entry(null)));
    }

    public function test_the_kilograms_the_server_stores_use_the_frozen_weight(): void
    {
        // The other half of the contract, and the reason the field had to be
        // exposed at all: whatever the resource reports, the completion must
        // compute from the same figure.
        $entry = $this->entry('12.9000');

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => 10080,
            'quantity_scrap' => 237,
            'running_hours' => 8,
        ])->assertOk();

        $entry->refresh();

        // 10,080 x 12.9 g = 130.032 kg, and 237 x 12.9 g = 3.0573 kg — the
        // frozen weight throughout, never the item master's 12.0.
        $this->assertSame(0, bccomp((string) $entry->quantity_produced_kg, '130.0320', 4),
            'Produced kg must come from the frozen weight.');
        $this->assertSame(0, bccomp((string) $entry->quantity_rejection_kg, '3.0573', 4),
            'Rejection kg must come from the SAME weight as produced kg.');
    }

    public function test_with_no_frozen_weight_the_item_master_drives_the_kilograms(): void
    {
        // The paper's own row: 10,080 at 12.0 g is 120.96 kg, and 237 rejected
        // is 2.844 kg — 123.804 kg consumed, which is what the supervisor wrote
        // by hand as 123.80.
        $entry = $this->entry(null);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => 10080,
            'quantity_scrap' => 237,
            'running_hours' => 8,
        ])->assertOk();

        $entry->refresh();

        $this->assertSame(0, bccomp((string) $entry->quantity_produced_kg, '120.9600', 4));
        $this->assertSame(0, bccomp((string) $entry->quantity_rejection_kg, '2.8440', 4));
        $this->assertSame(0, bccomp(
            bcadd((string) $entry->quantity_produced_kg, (string) $entry->quantity_rejection_kg, 4),
            '123.8040',
            4,
        ), 'The paper says 123.80 kg consumed. Anything else means the app disagrees with the floor.');
    }
}
