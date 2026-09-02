<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Sales\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * SORTING THE CUSTOMER MASTER (03-Sep-2026) — code, name, state, status —
 * on a list the server paginates. `sort` is validated by
 * ListCustomersRequest and applied through ListSort with `id desc` as the
 * tiebreak; the controller's own per_page clamp (1..200) is unchanged
 * because the order and opportunity pickers read the master at 200.
 */
class CustomerSortingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['is_active' => true]);
        foreach (['sales.view', 'sales.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);
    }

    public function test_an_unknown_sort_column_is_refused(): void
    {
        $this->getJson('/api/v1/sales/customers?sort=nonsense')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort']);
    }

    public function test_status_descending_ties_break_on_newest_id(): void
    {
        $activeFirst = Customer::create(['code' => 'C-1', 'name' => 'Aqua Traders', 'is_active' => true]);
        $archived = Customer::create(['code' => 'C-2', 'name' => 'Blue Bottles', 'is_active' => false]);
        $activeSecond = Customer::create(['code' => 'C-3', 'name' => 'Cool Drinks', 'is_active' => true]);

        $response = $this->getJson('/api/v1/sales/customers?sort=-is_active')->assertOk();

        $this->assertSame(
            [$activeSecond->id, $activeFirst->id, $archived->id],
            collect($response->json('data'))->pluck('id')->all(),
        );
    }

    public function test_the_default_is_still_name_order(): void
    {
        Customer::create(['code' => 'C-1', 'name' => 'Zeta Stores']);
        Customer::create(['code' => 'C-2', 'name' => 'Alpha Stores']);
        Customer::create(['code' => 'C-3', 'name' => 'Mu Stores']);

        $response = $this->getJson('/api/v1/sales/customers')->assertOk();

        $this->assertSame(['Alpha Stores', 'Mu Stores', 'Zeta Stores'], collect($response->json('data'))->pluck('name')->all());
    }

    public function test_page_size_is_honoured_and_the_total_is_the_whole_master(): void
    {
        Customer::create(['code' => 'C-1', 'name' => 'One']);
        Customer::create(['code' => 'C-2', 'name' => 'Two']);
        Customer::create(['code' => 'C-3', 'name' => 'Three']);

        $response = $this->getJson('/api/v1/sales/customers?per_page=2&sort=-code')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));
        $this->assertSame(['C-3', 'C-2'], collect($response->json('data'))->pluck('code')->all());
    }
}
