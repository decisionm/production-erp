<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Procurement\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class VendorStateNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_state_code_is_answered_with_its_name(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permission::findOrCreate('procurement.view', 'web'));
        Sanctum::actingAs($user);
        Vendor::create(['code' => 'V-PY', 'name' => 'Puducherry Co', 'state_code' => '34']);
        Vendor::create(['code' => 'V-NA', 'name' => 'No State Co']);

        $rows = collect($this->getJson('/api/v1/procurement/vendors')->assertOk()->json('data'))->keyBy('code');
        $this->assertSame('Puducherry', $rows['V-PY']['state_name']);
        $this->assertNull($rows['V-NA']['state_name']);
    }
}
