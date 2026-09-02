<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Enums\ItemTrackingType;
use App\Modules\Inventory\Models\Enums\SerialNumberStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\SerialNumber;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * SORTING THE THREE INVENTORY MASTERS the server paginates — warehouses,
 * batches, serial numbers (03-Sep-2026).
 *
 * Each list validates `sort` with ListSort::rule (unknown column = 422) and
 * orders the whole result set through ListSort::apply with `id desc` as the
 * tiebreak. Batches carry two NULLABLE dates, and an undated batch is not
 * "earliest": it sorts last whichever way the column points.
 */
class InventoryMasterListSortingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['is_active' => true]);
        foreach (['inventory.view', 'inventory.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);
    }

    // ---- warehouses ---------------------------------------------------------

    public function test_warehouses_refuse_an_unknown_sort(): void
    {
        $this->getJson('/api/v1/inventory/warehouses?sort=nonsense')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort']);
    }

    public function test_warehouses_sort_by_code_descending_with_newest_id_breaking_a_tie(): void
    {
        // Two rows share a NAME (the tiebreak case) and the codes are created
        // out of order so an id order would not pass by accident.
        $b = Warehouse::create(['code' => 'B-STORE', 'name' => 'Same Store', 'is_active' => true]);
        $c = Warehouse::create(['code' => 'C-STORE', 'name' => 'Other Store', 'is_active' => true]);
        $a = Warehouse::create(['code' => 'A-STORE', 'name' => 'Same Store', 'is_active' => true]);

        $codes = $this->getJson('/api/v1/inventory/warehouses?sort=-code')->assertOk();
        $this->assertSame([$c->id, $b->id, $a->id], collect($codes->json('data'))->pluck('id')->all());

        // Same name twice: the newer row (higher id) comes first within the tie.
        $names = $this->getJson('/api/v1/inventory/warehouses?sort=-name')->assertOk();
        $this->assertSame([$a->id, $b->id, $c->id], collect($names->json('data'))->pluck('id')->all());
    }

    public function test_warehouses_page_size_is_honoured_and_the_total_is_the_whole_list(): void
    {
        Warehouse::create(['code' => 'W1', 'name' => 'One', 'is_active' => true]);
        Warehouse::create(['code' => 'W2', 'name' => 'Two', 'is_active' => true]);
        Warehouse::create(['code' => 'W3', 'name' => 'Three', 'is_active' => true]);

        $response = $this->getJson('/api/v1/inventory/warehouses?per_page=2')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));
    }

    // ---- batches ------------------------------------------------------------

    private function batchItem(): Item
    {
        return Item::firstOrCreate(
            ['sku' => 'SYN-BATCHED'],
            ['name' => 'Batched Resin', 'uom' => 'Kgs.', 'is_active' => true, 'tracking_type' => ItemTrackingType::Batch],
        );
    }

    public function test_batches_refuse_an_unknown_sort(): void
    {
        $this->getJson('/api/v1/inventory/batches?sort=nonsense')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort']);
    }

    public function test_batches_sort_by_expiry_with_undated_rows_last_either_way(): void
    {
        $item = $this->batchItem();
        $late = Batch::create(['item_id' => $item->id, 'batch_number' => 'B-LATE', 'expiry_date' => '2027-01-01']);
        $undated = Batch::create(['item_id' => $item->id, 'batch_number' => 'B-NONE']);
        $early = Batch::create(['item_id' => $item->id, 'batch_number' => 'B-EARLY', 'expiry_date' => '2026-06-01']);
        $lateAgain = Batch::create(['item_id' => $item->id, 'batch_number' => 'B-LATE-2', 'expiry_date' => '2027-01-01']);

        $descending = $this->getJson('/api/v1/inventory/batches?sort=-expiry_date')->assertOk();
        // Two batches share the expiry: the newer id leads within the tie.
        $this->assertSame(
            [$lateAgain->id, $late->id, $early->id, $undated->id],
            collect($descending->json('data'))->pluck('id')->all(),
        );

        $ascending = $this->getJson('/api/v1/inventory/batches?sort=expiry_date')->assertOk();
        $this->assertSame(
            [$early->id, $lateAgain->id, $late->id, $undated->id],
            collect($ascending->json('data'))->pluck('id')->all(),
        );
    }

    public function test_batches_page_size_is_honoured(): void
    {
        $item = $this->batchItem();
        foreach (['B-1', 'B-2', 'B-3'] as $number) {
            Batch::create(['item_id' => $item->id, 'batch_number' => $number]);
        }

        $response = $this->getJson('/api/v1/inventory/batches?sort=batch_number&per_page=2')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));
        $this->assertSame(['B-1', 'B-2'], collect($response->json('data'))->pluck('batch_number')->all());
    }

    // ---- serial numbers -----------------------------------------------------

    private function serialItem(): Item
    {
        return Item::firstOrCreate(
            ['sku' => 'SYN-SERIAL'],
            ['name' => 'Serialised Unit', 'uom' => 'Nos.', 'is_active' => true, 'tracking_type' => ItemTrackingType::Serial],
        );
    }

    private function serial(string $number, SerialNumberStatus $status): SerialNumber
    {
        return SerialNumber::create([
            'item_id' => $this->serialItem()->id,
            'serial_number' => $number,
            'status' => $status,
        ]);
    }

    public function test_serial_numbers_refuse_an_unknown_sort(): void
    {
        $this->getJson('/api/v1/inventory/serial-numbers?sort=nonsense')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort']);
    }

    public function test_serial_numbers_sort_by_status_descending_with_newest_id_breaking_a_tie(): void
    {
        $soldFirst = $this->serial('SN-A', SerialNumberStatus::Sold);
        $registered = $this->serial('SN-B', SerialNumberStatus::Registered);
        $soldSecond = $this->serial('SN-C', SerialNumberStatus::Sold);

        $response = $this->getJson('/api/v1/inventory/serial-numbers?sort=-status')->assertOk();

        $this->assertSame(
            [$soldSecond->id, $soldFirst->id, $registered->id],
            collect($response->json('data'))->pluck('id')->all(),
        );
    }

    public function test_serial_numbers_page_size_is_honoured(): void
    {
        $this->serial('SN-1', SerialNumberStatus::Registered);
        $this->serial('SN-2', SerialNumberStatus::Registered);
        $this->serial('SN-3', SerialNumberStatus::Registered);

        $response = $this->getJson('/api/v1/inventory/serial-numbers?sort=serial_number&per_page=2')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));
        $this->assertSame(['SN-1', 'SN-2'], collect($response->json('data'))->pluck('serial_number')->all());
    }
}
