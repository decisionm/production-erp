<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * FINDING A RECEIPT BY ITS DATE, on a register that is paginated.
 *
 * "Reprint the label for the resin that came in on the 14th" is the question
 * this register exists to answer, and the filter has to run on the SERVER.
 * Narrowing it in the browser would filter the twenty rows that had already
 * arrived while the pager went on reporting the whole total — the same defect
 * review caught on the item catalogue.
 */
class MaterialLotRegisterFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The whole lot/bag surface 404s unless this is on — the feature does
        // not exist with the flag off, deliberately.
        config()->set('production.traceability_enabled', true);
    }

    private function actingAsStore(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['inventory.view', 'inventory.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    private function lot(string $receivedDate, string $supplierLot): MaterialLot
    {
        $item = Item::firstOrCreate(
            ['sku' => 'SYN-RESIN'],
            ['name' => 'Synthetic Resin', 'uom' => 'Kgs.', 'is_active' => true],
        );

        return MaterialLot::create([
            'item_id' => $item->id,
            'supplier_lot_no' => $supplierLot,
            'received_date' => $receivedDate,
            'bag_count' => 4,
            'bag_weight_kg' => '25.0000',
            'total_received_kg' => '100.0000',
        ]);
    }

    public function test_a_date_range_narrows_the_register_on_the_server(): void
    {
        $this->actingAsStore();
        $this->lot('2026-08-10', 'EARLY');
        $this->lot('2026-08-14', 'WANTED');
        $this->lot('2026-08-20', 'LATE');

        $response = $this->getJson('/api/v1/inventory/material-lots?received_from=2026-08-14&received_to=2026-08-14')
            ->assertSuccessful();

        $this->assertSame(['WANTED'], collect($response->json('data'))->pluck('supplier_lot_no')->all());
        // The pager must describe the FILTERED register, not the whole one.
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_an_open_ended_range_is_allowed_from_either_side(): void
    {
        $this->actingAsStore();
        $this->lot('2026-08-10', 'EARLY');
        $this->lot('2026-08-20', 'LATE');

        $from = $this->getJson('/api/v1/inventory/material-lots?received_from=2026-08-15')->assertSuccessful();
        $this->assertSame(['LATE'], collect($from->json('data'))->pluck('supplier_lot_no')->all());

        $to = $this->getJson('/api/v1/inventory/material-lots?received_to=2026-08-15')->assertSuccessful();
        $this->assertSame(['EARLY'], collect($to->json('data'))->pluck('supplier_lot_no')->all());
    }

    public function test_newest_first_by_default_and_oldest_first_on_request(): void
    {
        $this->actingAsStore();
        $this->lot('2026-08-10', 'EARLY');
        $this->lot('2026-08-14', 'MIDDLE');
        $this->lot('2026-08-20', 'LATE');

        $newest = $this->getJson('/api/v1/inventory/material-lots')->assertSuccessful();
        $this->assertSame(['LATE', 'MIDDLE', 'EARLY'], collect($newest->json('data'))->pluck('supplier_lot_no')->all());

        $oldest = $this->getJson('/api/v1/inventory/material-lots?order=oldest')->assertSuccessful();
        $this->assertSame(['EARLY', 'MIDDLE', 'LATE'], collect($oldest->json('data'))->pluck('supplier_lot_no')->all());
    }

    public function test_a_backwards_range_is_refused_rather_than_returning_nothing(): void
    {
        $this->actingAsStore();
        $this->lot('2026-08-14', 'WANTED');

        // An empty register and an impossible question look identical on
        // screen, so the impossible one is named.
        $this->getJson('/api/v1/inventory/material-lots?received_from=2026-08-20&received_to=2026-08-10')
            ->assertStatus(422)
            ->assertJsonValidationErrors('received_to');
    }

    public function test_an_unparseable_date_is_refused_rather_than_ignored(): void
    {
        $this->actingAsStore();
        $this->lot('2026-08-14', 'WANTED');

        // Silently dropping it would show the whole register and look like an
        // answer to the question that was asked.
        $this->getJson('/api/v1/inventory/material-lots?received_from=the-14th')
            ->assertStatus(422)
            ->assertJsonValidationErrors('received_from');
    }
}
