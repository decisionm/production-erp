<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * DEC-20260902-025: any procurement-write holder may approve a requisition
 * EXCEPT the person who raised it; self-approval is refused with a clear
 * message; no Administrator bypass. Rejection is an approver action too.
 */
class RequisitionApproverTest extends TestCase
{
    use RefreshDatabase;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->item = Item::create(['sku' => 'ITEM_RM', 'name' => 'Item RM', 'uom' => 'Kgs', 'category' => ItemCategory::RawMaterial]);
    }

    private function actAs(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['procurement.view', 'procurement.manage'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        Sanctum::actingAs($user);

        return $user;
    }

    private function raise(): int
    {
        return $this->postJson('/api/v1/procurement/purchase-requisitions', [
            'lines' => [['item_id' => $this->item->id, 'quantity' => '10']],
        ])->assertCreated()->json('data.id');
    }

    public function test_the_requester_cannot_approve_their_own_requisition(): void
    {
        $this->actAs();
        $id = $this->raise();

        $this->postJson("/api/v1/procurement/purchase-requisitions/{$id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', 'A requisition cannot be approved by the person who raised it.');

        $this->assertDatabaseHas('purchase_requisitions', ['id' => $id, 'status' => 'draft', 'approved_by' => null]);
    }

    public function test_the_requester_cannot_reject_their_own_requisition(): void
    {
        $this->actAs();
        $id = $this->raise();

        $this->postJson("/api/v1/procurement/purchase-requisitions/{$id}/reject")->assertStatus(422);
        $this->assertDatabaseHas('purchase_requisitions', ['id' => $id, 'status' => 'draft']);
    }

    public function test_a_different_procurement_user_approves_and_is_recorded(): void
    {
        $this->actAs();
        $id = $this->raise();

        $approver = $this->actAs();
        $this->postJson("/api/v1/procurement/purchase-requisitions/{$id}/approve")->assertOk();

        $this->assertDatabaseHas('purchase_requisitions', ['id' => $id, 'status' => 'approved', 'approved_by' => $approver->id]);
    }

    public function test_an_administrator_who_raised_it_is_not_exempt(): void
    {
        $admin = $this->actAs();
        $admin->assignRole(Role::findOrCreate('Administrator', 'web'));
        $id = $this->raise();

        $this->postJson("/api/v1/procurement/purchase-requisitions/{$id}/approve")->assertStatus(422);
    }
}
