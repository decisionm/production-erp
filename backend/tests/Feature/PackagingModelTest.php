<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ShiftProductionEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Wave A packaging (pouches): items gain nos_per_pouch / pouches_per_box
 * standards, shift entries persist no_of_pouches and loose_pieces (the
 * latter formalized from a frontend-only helper), and the metrics block
 * gains expected_pouches — a packing suggestion rounded per
 * production.packing_rounding, unlike expected_boxes which stays the WB2
 * half-up ROUND workbook formula. Everything is strictly additive: with
 * the new fields absent, behaviour is byte-identical.
 */
class PackagingModelTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUserWithPermission(string $permission): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
        Sanctum::actingAs($user);

        return $user;
    }

    private function inProgressEntry(): ShiftProductionEntry
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
        $item = Item::create(['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'NOS']);
        $warehouse = Warehouse::create(['code' => 'WH-1', 'name' => 'Store']);

        return ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => '2026-07-28',
            'batch_number' => '20260728-M01-001',
            'batch_status' => BatchStatus::InProgress,
            'quantity_produced' => null,
            'quantity_scrap' => '0',
        ]);
    }

    /**
     * In-memory completed entry (ExpectedOutputEngineTest pattern) —
     * productionMetrics() is pure computation, so no DB needed.
     */
    private function completedEntry(array $attributes = [], array $itemAttributes = []): ShiftProductionEntry
    {
        $entry = new ShiftProductionEntry($attributes + ['batch_status' => BatchStatus::Completed]);
        $entry->setRelation('item', new Item($itemAttributes + ['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'NOS']));
        $entry->setRelation('materialConsumptions', collect());
        $entry->setRelation('scraps', collect());

        return $entry;
    }

    private function metrics(ShiftProductionEntry $entry): ?array
    {
        return app(ShiftProductionEntryService::class)->productionMetrics($entry);
    }

    public function test_item_pouch_standards_round_trip(): void
    {
        $this->actingAsUserWithPermission('inventory.manage');

        $id = $this->postJson('/api/v1/inventory/items', [
            'sku' => 'BTL-500',
            'name' => '500ml Bottle',
            'uom' => 'NOS',
            'nos_per_pouch' => 25,
            'pouches_per_box' => 20,
        ])
            ->assertCreated()
            ->assertJsonPath('data.nos_per_pouch', 25)
            ->assertJsonPath('data.pouches_per_box', 20)
            ->json('data.id');

        $this->putJson("/api/v1/inventory/items/{$id}", [
            'nos_per_pouch' => 30,
            'pouches_per_box' => 16,
        ])
            ->assertOk()
            ->assertJsonPath('data.nos_per_pouch', 30)
            ->assertJsonPath('data.pouches_per_box', 16);

        $this->assertDatabaseHas('items', [
            'id' => $id,
            'nos_per_pouch' => 30,
            'pouches_per_box' => 16,
        ]);

        // Clearable — a wrong data load must not be sticky.
        $this->putJson("/api/v1/inventory/items/{$id}", ['nos_per_pouch' => null])
            ->assertOk()
            ->assertJsonPath('data.nos_per_pouch', null);

        // Zero/negative standards are rejected, not silently stored.
        $this->putJson("/api/v1/inventory/items/{$id}", ['nos_per_pouch' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nos_per_pouch']);
        $this->putJson("/api/v1/inventory/items/{$id}", ['pouches_per_box' => -2])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pouches_per_box']);
    }

    public function test_items_without_pouch_standards_expose_the_keys_as_null(): void
    {
        // Presence (>= 1) of nos_per_pouch is what shows the pouch fields on
        // the frontend — every existing item must report null and keep
        // today's tray+box field set.
        $this->actingAsUserWithPermission('inventory.view');
        Item::create(['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'NOS']);

        $this->getJson('/api/v1/inventory/items')
            ->assertOk()
            ->assertJsonPath('data.0.nos_per_pouch', null)
            ->assertJsonPath('data.0.pouches_per_box', null);
    }

    public function test_complete_batch_persists_no_of_pouches_and_loose_pieces(): void
    {
        $this->actingAsUserWithPermission('production.manage');
        $entry = $this->inProgressEntry();

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '1265',
            'no_of_pouches' => 50,
            'loose_pieces' => 15,
        ])
            ->assertOk()
            ->assertJsonPath('data.no_of_pouches', 50)
            ->assertJsonPath('data.loose_pieces', 15)
            ->assertJsonPath('data.metrics.actual_pouches', 50);

        $this->assertDatabaseHas('shift_production_entries', [
            'id' => $entry->id,
            'no_of_pouches' => 50,
            'loose_pieces' => 15,
        ]);
    }

    public function test_empty_completion_preserves_prior_values_and_behaves_as_before(): void
    {
        // One-shot semantics, same as the tray/box counts: a completion that
        // doesn't send the new fields leaves them untouched (null), and the
        // auto-minted batch number survives an empty completion.
        $this->actingAsUserWithPermission('production.manage');
        $entry = $this->inProgressEntry();

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '1000',
        ])
            ->assertOk()
            ->assertJsonPath('data.batch_status', 'completed')
            ->assertJsonPath('data.batch_number', '20260728-M01-001')
            ->assertJsonPath('data.no_of_pouches', null)
            ->assertJsonPath('data.loose_pieces', null)
            ->assertJsonPath('data.metrics.actual_pouches', null)
            ->assertJsonPath('data.metrics.expected_pouches', null);
    }

    public function test_complete_batch_rejects_negative_pouch_and_loose_counts(): void
    {
        $this->actingAsUserWithPermission('production.manage');
        $entry = $this->inProgressEntry();

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '1000',
            'no_of_pouches' => -1,
            'loose_pieces' => -5,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['no_of_pouches', 'loose_pieces']);
    }

    public function test_expected_pouches_uses_packing_rounding_while_expected_boxes_stays_half_up(): void
    {
        // WB2 row 6 standards: CT=10.6, CAV=5, HRS=8 → 13584.9056… pieces.
        // Same divisor 840 for both: the workbook formula half-up ROUNDS
        // 16.1725… to 16 boxes, while the pouch packing SUGGESTION ceils
        // the same ratio to 17 — proving packing_rounding (default ceil)
        // applies to pouches and never to the WB2 boxes figure.
        $metrics = $this->metrics($this->completedEntry(
            attributes: [
                'standard_cycle_time' => '10.6',
                'active_cavities' => 5,
                'running_hours' => '8',
                'no_of_pouches' => 12,
            ],
            itemAttributes: ['nos_per_box' => 840, 'nos_per_pouch' => 840],
        ));

        $this->assertSame('13584.91', $metrics['expected_pieces']);
        $this->assertSame(16, $metrics['expected_boxes']);
        $this->assertSame(17, $metrics['expected_pouches']);
        $this->assertSame(12, $metrics['actual_pouches']);
    }

    public function test_expected_pouches_is_null_safe(): void
    {
        // No pouch standard on the item → null, never a fake number.
        $noStandard = $this->metrics($this->completedEntry(
            attributes: ['standard_cycle_time' => '12', 'active_cavities' => 5, 'running_hours' => '8'],
            itemAttributes: ['nos_per_box' => 1040],
        ));
        $this->assertSame(12, $noStandard['expected_boxes']);
        $this->assertNull($noStandard['expected_pouches']);

        // No expected pieces (missing CT) → null even with a pouch standard.
        $noCycleTime = $this->metrics($this->completedEntry(
            attributes: ['active_cavities' => 5, 'running_hours' => '8', 'no_of_pouches' => 9],
            itemAttributes: ['nos_per_pouch' => 100],
        ));
        $this->assertNull($noCycleTime['expected_pieces']);
        $this->assertNull($noCycleTime['expected_pouches']);
        // The reported actual survives regardless.
        $this->assertSame(9, $noCycleTime['actual_pouches']);
    }

    public function test_packing_rounding_config_flips_the_same_suggestion(): void
    {
        // CT=12, CAV=5, HRS=8 → exactly 12000 pieces; /7 = 1714.2857… —
        // a fraction below .5, so all three modes disagree with ceil:
        // ceil → 1715, round → 1714, floor → 1714.
        $entry = fn () => $this->completedEntry(
            attributes: ['standard_cycle_time' => '12', 'active_cavities' => 5, 'running_hours' => '8'],
            itemAttributes: ['nos_per_pouch' => 7],
        );

        config()->set('production.packing_rounding', 'ceil');
        $this->assertSame(1715, $this->metrics($entry())['expected_pouches']);

        config()->set('production.packing_rounding', 'round');
        $this->assertSame(1714, $this->metrics($entry())['expected_pouches']);

        config()->set('production.packing_rounding', 'floor');
        $this->assertSame(1714, $this->metrics($entry())['expected_pouches']);

        // And a fraction ABOVE .5 (12000/3200 = 3.75), which separates
        // round from floor — a half-up regression can't hide behind
        // fractions that happen to land below .5.
        $high = fn () => $this->completedEntry(
            attributes: ['standard_cycle_time' => '12', 'active_cavities' => 5, 'running_hours' => '8'],
            itemAttributes: ['nos_per_pouch' => 3200],
        );

        config()->set('production.packing_rounding', 'round');
        $this->assertSame(4, $this->metrics($high())['expected_pouches']);

        config()->set('production.packing_rounding', 'floor');
        $this->assertSame(3, $this->metrics($high())['expected_pouches']);

        config()->set('production.packing_rounding', 'ceil');
        $this->assertSame(4, $this->metrics($high())['expected_pouches']);
    }

    public function test_ceil_does_not_bump_an_exact_division(): void
    {
        // 12000 / 40 = 300 exactly — ceil must report 300, not 301.
        config()->set('production.packing_rounding', 'ceil');

        $metrics = $this->metrics($this->completedEntry(
            attributes: ['standard_cycle_time' => '12', 'active_cavities' => 5, 'running_hours' => '8'],
            itemAttributes: ['nos_per_pouch' => 40],
        ));

        $this->assertSame(300, $metrics['expected_pouches']);
    }
}
