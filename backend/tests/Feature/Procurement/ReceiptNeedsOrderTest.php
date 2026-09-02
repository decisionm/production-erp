<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** DEC-20260902-034: PO first, always. The receipt screen never offers "receive without order", and the server refuses one. */
class ReceiptNeedsOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_receipt_without_a_purchase_order_is_refused(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['procurement.view', 'procurement.manage'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/procurement/goods-receipts', ['lines' => [['item_id' => 1, 'quantity' => '1']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['purchase_order_id']);
    }
}
