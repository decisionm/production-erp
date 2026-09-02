<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** DEC-20260902-025: a requester withdraws their own draft; nobody else does, and nothing but a draft can be withdrawn. */
class RequisitionWithdrawTest extends TestCase
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

    public function test_the_requester_withdraws_their_own_draft(): void
    {
        $me = $this->actAs();
        $id = $this->raise();

        $this->postJson("/api/v1/procurement/purchase-requisitions/{$id}/withdraw")
            ->assertOk()
            ->assertJsonPath('data.status', 'withdrawn')
            ->assertJsonPath('data.requested_by_id', $me->id);

        $this->assertDatabaseHas('purchase_requisitions', ['id' => $id, 'status' => 'withdrawn', 'withdrawn_by' => $me->id]);
    }

    public function test_someone_else_cannot_withdraw_it(): void
    {
        $this->actAs();
        $id = $this->raise();
        $this->actAs();

        $this->postJson("/api/v1/procurement/purchase-requisitions/{$id}/withdraw")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only the person who raised a requisition can withdraw it.');
    }

    public function test_an_approved_requisition_cannot_be_withdrawn(): void
    {
        $me = $this->actAs();
        $id = $this->raise();
        $this->actAs();
        $this->postJson("/api/v1/procurement/purchase-requisitions/{$id}/approve")->assertOk();

        Sanctum::actingAs($me);
        $this->postJson("/api/v1/procurement/purchase-requisitions/{$id}/withdraw")->assertStatus(422);
    }
}
