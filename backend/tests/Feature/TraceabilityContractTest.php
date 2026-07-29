<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\TraceabilityService;
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
 * The exact request/response contract the SPA's traceability screens hold
 * with the backend: the two aggregate GETs (machine day-bin state,
 * per-segment summary), barcode-first loading, the return-to-bag cap, the
 * closed-segment guard, and the machine-readable FIFO refusal code. Every
 * request here uses the same path + payload the frontend issues.
 */
class TraceabilityContractTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUserWithPermissions(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    private function enableTraceability(): void
    {
        config(['production.traceability_enabled' => true]);
    }

    /**
     * @return array{0: Item, 1: Warehouse, 2: WorkCenter}
     */
    private function masters(): array
    {
        return [
            Item::create(['sku' => 'RM-PET', 'name' => 'PET Resin', 'uom' => 'Kgs']),
            Warehouse::create(['code' => 'RM-STORE', 'name' => 'RM Store']),
            WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']),
        ];
    }

    private function inProgressEntry(WorkCenter $machine, Warehouse $warehouse): ShiftProductionEntry
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $bottle = Item::create(['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'NOS']);

        return ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $bottle->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => '2026-07-28',
            'batch_number' => '20260728-M01-001',
            'batch_status' => BatchStatus::InProgress,
            'quantity_produced' => null,
            'quantity_scrap' => '0',
        ]);
    }

    public function test_production_settings_advertise_the_traceability_flag(): void
    {
        $this->actingAsUserWithPermissions('production.manage');

        // PHPUnit pins the flag off so the suite is deterministic regardless
        // of the shipped default or a developer's local environment.
        $this->getJson('/api/v1/production/settings')
            ->assertSuccessful()
            ->assertJsonPath('data.traceability_enabled', false);

        $this->enableTraceability();
        $this->getJson('/api/v1/production/settings')
            ->assertSuccessful()
            ->assertJsonPath('data.traceability_enabled', true);
    }

    public function test_the_aggregate_gets_404_while_the_flag_is_off(): void
    {
        config(['production.traceability_enabled' => false]);
        $this->actingAsUserWithPermissions('production.manage');
        [, $warehouse, $machine] = $this->masters();
        $entry = $this->inProgressEntry($machine, $warehouse);

        $this->getJson("/api/v1/production/work-centers/{$machine->id}/day-bin")->assertNotFound();
        $this->getJson("/api/v1/production/shift-production-entries/{$entry->id}/day-bin")->assertNotFound();
    }

    public function test_work_center_day_bin_aggregate_reports_balances_and_loaded_bags(): void
    {
        $this->enableTraceability();
        $user = $this->actingAsUserWithPermissions('inventory.manage', 'production.manage');
        [$resin, $warehouse, $machine] = $this->masters();

        $lot = app(TraceabilityService::class)->createLot([
            'item_id' => $resin->id, 'supplier_lot_no' => 'RIL-2026-0714',
            'received_date' => '2026-07-20', 'bag_count' => 2,
            'bag_weight_kg' => '25', 'total_received_kg' => '50',
        ], $user->id);
        [$bag1, $bag2] = $lot->bags->sortBy('id')->values();

        // Full bag to the machine + a weighed partial from the next bag.
        $this->postJson('/api/v1/production/day-bin/load', [
            'work_center_id' => $machine->id, 'barcode' => $bag1->barcode,
        ])->assertSuccessful();
        $this->postJson('/api/v1/production/day-bin/load', [
            'work_center_id' => $machine->id, 'barcode' => $bag2->barcode, 'quantity_kg' => '7.5',
        ])->assertSuccessful();

        // Exactly the shape the SPA's DayBinState type binds to.
        $this->getJson("/api/v1/production/work-centers/{$machine->id}/day-bin")
            ->assertSuccessful()
            ->assertJson(['data' => ['materials' => [[
                'item' => ['id' => $resin->id, 'name' => 'PET Resin', 'sku' => 'RM-PET'],
                'balance_kg' => '32.5000',
                'loaded_bags' => [[
                    'id' => $bag1->id,
                    'barcode' => $bag1->barcode,
                    'remaining_kg' => '0.0000',
                    'lot' => ['id' => $lot->id, 'supplier_lot_no' => 'RIL-2026-0714'],
                ]],
            ]]]]);

        // A machine with no movements reports an empty material list.
        $idle = WorkCenter::create(['code' => 'MC-02', 'name' => 'Machine 2']);
        $this->getJson("/api/v1/production/work-centers/{$idle->id}/day-bin")
            ->assertSuccessful()
            ->assertExactJson(['data' => ['materials' => []]]);
    }

    public function test_entry_day_bin_aggregate_reports_the_per_material_formula(): void
    {
        $this->enableTraceability();
        $user = $this->actingAsUserWithPermissions('inventory.manage', 'production.manage');
        [$resin, $warehouse, $machine] = $this->masters();
        $entry = $this->inProgressEntry($machine, $warehouse);

        // Before any movement: has_movements false, nothing to prefill.
        $this->getJson("/api/v1/production/shift-production-entries/{$entry->id}/day-bin")
            ->assertSuccessful()
            ->assertExactJson(['data' => ['has_movements' => false, 'materials' => []]]);

        $lot = app(TraceabilityService::class)->createLot([
            'item_id' => $resin->id, 'received_date' => '2026-07-20',
            'bag_count' => 2, 'bag_weight_kg' => '25', 'total_received_kg' => '50',
        ], $user->id);
        [$bag1, $bag2] = $lot->bags->sortBy('id')->values();

        $this->postJson('/api/v1/production/day-bin/load', [
            'work_center_id' => $machine->id, 'barcode' => $bag1->barcode,
            'shift_production_entry_id' => $entry->id,
        ])->assertSuccessful();
        $this->postJson('/api/v1/production/day-bin/load', [
            'work_center_id' => $machine->id, 'barcode' => $bag2->barcode,
            'quantity_kg' => '7.5', 'shift_production_entry_id' => $entry->id,
        ])->assertSuccessful();
        $this->postJson('/api/v1/production/day-bin/return', [
            'work_center_id' => $machine->id, 'item_id' => $resin->id,
            'quantity_kg' => '1.3', 'material_bag_id' => $bag2->id,
            'shift_production_entry_id' => $entry->id,
        ])->assertSuccessful();

        // No closing count yet: consumption_kg is null, never a guess.
        $this->getJson("/api/v1/production/shift-production-entries/{$entry->id}/day-bin")
            ->assertSuccessful()
            ->assertJson(['data' => ['has_movements' => true, 'materials' => [[
                'item' => ['id' => $resin->id, 'name' => 'PET Resin', 'sku' => 'RM-PET'],
                'opening_kg' => '0.0000',
                'loaded_kg' => '32.5000',
                'returned_kg' => '1.3000',
                'closing_kg' => null,
                'consumption_kg' => null,
            ]]]]);

        $this->postJson('/api/v1/production/day-bin/count', [
            'work_center_id' => $machine->id, 'item_id' => $resin->id,
            'quantity_kg' => '4.2', 'shift_production_entry_id' => $entry->id,
        ])->assertSuccessful();

        // consumed = 0 + 32.5 − 4.2 − 1.3 = 27.0000.
        $this->getJson("/api/v1/production/shift-production-entries/{$entry->id}/day-bin")
            ->assertSuccessful()
            ->assertJsonPath('data.materials.0.closing_kg', '4.2000')
            ->assertJsonPath('data.materials.0.consumption_kg', '27.0000');
    }

    public function test_load_by_barcode_requires_exactly_one_identifier_and_names_unknown_barcodes(): void
    {
        $this->enableTraceability();
        $user = $this->actingAsUserWithPermissions('inventory.manage', 'production.manage');
        [$resin, $warehouse, $machine] = $this->masters();

        $lot = app(TraceabilityService::class)->createLot([
            'item_id' => $resin->id, 'received_date' => '2026-07-20',
            'bag_count' => 1, 'bag_weight_kg' => '25', 'total_received_kg' => '25',
        ], $user->id);
        $bag = $lot->bags->first();

        // Unknown barcode: a clear 422, not a 404 or a silent no-op.
        $this->postJson('/api/v1/production/day-bin/load', [
            'work_center_id' => $machine->id, 'barcode' => 'NO-SUCH-BAG',
        ])->assertStatus(422)->assertJsonValidationErrors(['barcode']);

        // Neither identifier, or both at once: rejected.
        $this->postJson('/api/v1/production/day-bin/load', [
            'work_center_id' => $machine->id,
        ])->assertStatus(422);
        $this->postJson('/api/v1/production/day-bin/load', [
            'work_center_id' => $machine->id, 'barcode' => $bag->barcode, 'material_bag_id' => $bag->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['material_bag_id']);

        // The scanner-gun happy path.
        $this->postJson('/api/v1/production/day-bin/load', [
            'work_center_id' => $machine->id, 'barcode' => $bag->barcode,
        ])->assertSuccessful()
            ->assertJsonPath('data.quantity_kg', '25.0000')
            ->assertJsonPath('data.material_bag.barcode', $bag->barcode);
    }

    public function test_a_return_can_never_push_a_bag_past_its_original_kg(): void
    {
        $this->enableTraceability();
        $user = $this->actingAsUserWithPermissions('inventory.manage', 'production.manage');
        [$resin, $warehouse, $machine] = $this->masters();

        $lot = app(TraceabilityService::class)->createLot([
            'item_id' => $resin->id, 'received_date' => '2026-07-20',
            'bag_count' => 2, 'bag_weight_kg' => '25', 'total_received_kg' => '50',
        ], $user->id);
        [$bag1, $bag2] = $lot->bags->sortBy('id')->values();

        // Bin holds 35 kg (25 full + 10 partial); bag2 still holds 15 kg.
        $this->postJson('/api/v1/production/day-bin/load', [
            'work_center_id' => $machine->id, 'barcode' => $bag1->barcode,
        ])->assertSuccessful();
        $this->postJson('/api/v1/production/day-bin/load', [
            'work_center_id' => $machine->id, 'barcode' => $bag2->barcode, 'quantity_kg' => '10',
        ])->assertSuccessful();

        // 15 + 20 = 35 > the bag's original 25 — refused, naming the figures.
        $response = $this->postJson('/api/v1/production/day-bin/return', [
            'work_center_id' => $machine->id, 'item_id' => $resin->id,
            'quantity_kg' => '20', 'material_bag_id' => $bag2->id,
        ]);
        $response->assertStatus(422);
        $message = $response->json('message');
        $this->assertStringContainsString('20.0000', $message);
        $this->assertStringContainsString('15.0000', $message);
        $this->assertStringContainsString('25.0000', $message);

        // Nothing moved: no ledger row, bag untouched.
        $this->assertSame('15.0000', $bag2->fresh()->remaining_kg);

        // An honest return passes and the bag lands back in the store.
        $this->postJson('/api/v1/production/day-bin/return', [
            'work_center_id' => $machine->id, 'item_id' => $resin->id,
            'quantity_kg' => '10', 'material_bag_id' => $bag2->id,
        ])->assertSuccessful();
        $bag2 = $bag2->fresh();
        $this->assertSame('25.0000', $bag2->remaining_kg);
        $this->assertSame(MaterialBagStatus::InStore, $bag2->status);
        $this->assertNull($bag2->day_bin_work_center_id);
    }

    public function test_movements_against_a_completed_segment_are_refused(): void
    {
        $this->enableTraceability();
        $user = $this->actingAsUserWithPermissions('inventory.manage', 'production.manage');
        [$resin, $warehouse, $machine] = $this->masters();
        $entry = $this->inProgressEntry($machine, $warehouse);

        $lot = app(TraceabilityService::class)->createLot([
            'item_id' => $resin->id, 'received_date' => '2026-07-20',
            'bag_count' => 2, 'bag_weight_kg' => '25', 'total_received_kg' => '50',
        ], $user->id);
        [$bag1, $bag2] = $lot->bags->sortBy('id')->values();

        $this->postJson('/api/v1/production/day-bin/load', [
            'work_center_id' => $machine->id, 'barcode' => $bag1->barcode,
            'shift_production_entry_id' => $entry->id,
        ])->assertSuccessful();

        app(ShiftProductionEntryService::class)->completeBatch($entry, ['quantity_produced' => '100'], null);

        // Load, return and count all refuse a closed segment window.
        $this->postJson('/api/v1/production/day-bin/load', [
            'work_center_id' => $machine->id, 'barcode' => $bag2->barcode,
            'shift_production_entry_id' => $entry->id,
        ])->assertStatus(422)->assertJsonFragment(['message' => "Segment already completed — day-bin movements cannot reference shift production entry #{$entry->id} because it is no longer in progress."]);
        $this->postJson('/api/v1/production/day-bin/return', [
            'work_center_id' => $machine->id, 'item_id' => $resin->id,
            'quantity_kg' => '1', 'shift_production_entry_id' => $entry->id,
        ])->assertStatus(422);
        $this->postJson('/api/v1/production/day-bin/count', [
            'work_center_id' => $machine->id, 'item_id' => $resin->id,
            'quantity_kg' => '1', 'shift_production_entry_id' => $entry->id,
        ])->assertStatus(422);

        // The refused load left the bag untouched.
        $this->assertSame('25.0000', $bag2->fresh()->remaining_kg);
        // Movements without a segment reference still work on the machine.
        $this->postJson('/api/v1/production/day-bin/count', [
            'work_center_id' => $machine->id, 'item_id' => $resin->id, 'quantity_kg' => '5',
        ])->assertSuccessful();
    }

    public function test_a_fifo_refusal_carries_the_machine_readable_code(): void
    {
        $this->enableTraceability();
        $user = $this->actingAsUserWithPermissions('inventory.manage', 'production.manage');
        [$resin, $warehouse, $machine] = $this->masters();
        $service = app(TraceabilityService::class);

        $service->createLot([
            'item_id' => $resin->id, 'received_date' => '2026-07-20',
            'bag_count' => 1, 'bag_weight_kg' => '25', 'total_received_kg' => '25',
        ], $user->id);
        $newer = $service->createLot([
            'item_id' => $resin->id, 'received_date' => '2026-07-22',
            'bag_count' => 1, 'bag_weight_kg' => '25', 'total_received_kg' => '25',
        ], $user->id);

        // The SPA keys its "override FIFO?" prompt off this code — never
        // off message text.
        $this->postJson('/api/v1/production/day-bin/load', [
            'work_center_id' => $machine->id, 'barcode' => $newer->bags->first()->barcode,
        ])->assertStatus(422)->assertJsonPath('code', 'fifo_order');
    }
}
