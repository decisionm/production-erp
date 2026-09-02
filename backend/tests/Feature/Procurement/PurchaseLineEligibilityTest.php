<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Item;
use App\Modules\Procurement\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * DEC-20260902-023 on the wire: a finished good OR a work-in-progress item is
 * refused on a requisition and on an ERP-entered purchase order; an
 * unclassified item is accepted only with a reason; raw, packing and Other
 * items are accepted as before. Resolves the half of InactiveMasterGuardTest
 * that pinned "category is not consulted" while Q59 was open — Q59(a) is now
 * answered.
 *
 * Message strings are asserted exactly — response()->json('errors') indexed
 * by the LITERAL key (e.g. 'lines.0.item_id'), not assertJsonPath: Laravel's
 * validation error bag keys are dotted strings, one segment, and
 * assertJsonPath/data_get would explode that string into path SEGMENTS and
 * never find it. Not merely "the key errored" either — the two refusals say
 * different things for different reasons (PurchaseLineEligibility's own
 * comment), and a substring match would let them collapse into one another
 * unnoticed.
 */
class PurchaseLineEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vendor = Vendor::create(['code' => 'V-ALPHA', 'name' => 'Vendor Alpha']);
        $user = User::factory()->create(['is_active' => true]);
        foreach (['procurement.view', 'procurement.manage'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        Sanctum::actingAs($user);
    }

    private function item(string $sku, ?ItemCategory $category): Item
    {
        return Item::create(['sku' => $sku, 'name' => $sku, 'uom' => 'Nos', 'category' => $category]);
    }

    public function test_a_finished_good_is_refused_on_a_requisition(): void
    {
        $bottle = $this->item('BOTTLE', ItemCategory::FinishedGood);

        $response = $this->postJson('/api/v1/procurement/purchase-requisitions', ['lines' => [['item_id' => $bottle->id, 'quantity' => '5']]])
            ->assertStatus(422);

        $this->assertSame(['A finished good is not purchased.'], $response->json('errors')['lines.0.item_id']);
    }

    public function test_a_work_in_progress_item_is_refused_on_a_requisition(): void
    {
        $wip = $this->item('WIP-BOTTLE', ItemCategory::WorkInProgress);

        $response = $this->postJson('/api/v1/procurement/purchase-requisitions', ['lines' => [['item_id' => $wip->id, 'quantity' => '5']]])
            ->assertStatus(422);

        $this->assertSame(['A produced item is not purchased.'], $response->json('errors')['lines.0.item_id']);
    }

    public function test_an_unclassified_item_needs_a_reason(): void
    {
        $spray = $this->item('SPRAY', null);

        $response = $this->postJson('/api/v1/procurement/purchase-requisitions', ['lines' => [['item_id' => $spray->id, 'quantity' => '2']]])
            ->assertStatus(422);

        $this->assertSame(['An unclassified item needs a reason.'], $response->json('errors')['lines.0.unclassified_reason']);

        $this->postJson('/api/v1/procurement/purchase-requisitions', ['lines' => [['item_id' => $spray->id, 'quantity' => '2', 'unclassified_reason' => 'Mould release, used on M3']]])
            ->assertCreated()
            ->assertJsonPath('data.lines.0.unclassified_reason', 'Mould release, used on M3');
    }

    public function test_other_raw_and_packing_are_accepted_without_a_reason(): void
    {
        foreach ([ItemCategory::Other, ItemCategory::RawMaterial, ItemCategory::PackingMaterial] as $category) {
            $item = $this->item('I-'.$category->value, $category);
            $this->postJson('/api/v1/procurement/purchase-requisitions', ['lines' => [['item_id' => $item->id, 'quantity' => '1']]])->assertCreated();
        }
    }

    public function test_a_finished_good_is_refused_on_an_erp_entered_purchase_order(): void
    {
        $bottle = $this->item('BOTTLE', ItemCategory::FinishedGood);

        $response = $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $this->vendor->id,
            'order_date' => '2026-09-03',
            'lines' => [['item_id' => $bottle->id, 'quantity' => '5', 'unit_price' => '1.00']],
        ])
            ->assertStatus(422);

        $this->assertSame(['A finished good is not purchased.'], $response->json('errors')['lines.0.item_id']);
    }

    public function test_a_work_in_progress_item_is_refused_on_an_erp_entered_purchase_order(): void
    {
        $wip = $this->item('WIP-BOTTLE-PO', ItemCategory::WorkInProgress);

        $response = $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $this->vendor->id,
            'order_date' => '2026-09-03',
            'lines' => [['item_id' => $wip->id, 'quantity' => '5', 'unit_price' => '1.00']],
        ])
            ->assertStatus(422);

        $this->assertSame(['A produced item is not purchased.'], $response->json('errors')['lines.0.item_id']);
    }

    public function test_an_unclassified_item_needs_a_reason_on_an_erp_entered_purchase_order(): void
    {
        $spray = $this->item('SPRAY-PO', null);

        $response = $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $this->vendor->id,
            'order_date' => '2026-09-03',
            'lines' => [['item_id' => $spray->id, 'quantity' => '2', 'unit_price' => '1.00']],
        ])
            ->assertStatus(422);

        $this->assertSame(['An unclassified item needs a reason.'], $response->json('errors')['lines.0.unclassified_reason']);

        $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $this->vendor->id,
            'order_date' => '2026-09-03',
            'lines' => [['item_id' => $spray->id, 'quantity' => '2', 'unit_price' => '1.00', 'unclassified_reason' => 'Mould release, used on M3']],
        ])
            ->assertCreated()
            ->assertJsonPath('data.lines.0.unclassified_reason', 'Mould release, used on M3');
    }
}
