<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Procurement\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * SORTING THE VENDOR MASTER (03-Sep-2026) — code, name, state, status — on
 * a list the server paginates (628 rows after the ledger import). `sort` is
 * validated by ListVendorsRequest and applied through ListSort; the three
 * readers PR #83 landed (`q`, `classification[]`, `unclassified`) are
 * untouched and still compose with it. Every vendor below is synthetic
 * (FC-06: real supplier identity is never test data).
 */
class VendorSortingTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['procurement.view', 'procurement.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    public function test_an_unknown_sort_column_is_refused(): void
    {
        $this->actingAs($this->user())
            ->getJson('/api/v1/procurement/vendors?sort=nonsense')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort']);
    }

    public function test_state_descending_ties_break_on_newest_id(): void
    {
        $tnFirst = Vendor::create(['code' => 'V-0001', 'name' => 'Alpha Polymers', 'state_code' => '33']);
        $mh = Vendor::create(['code' => 'V-0002', 'name' => 'Beta Packaging', 'state_code' => '27']);
        $tnSecond = Vendor::create(['code' => 'V-0003', 'name' => 'Gamma Plasto', 'state_code' => '33']);

        $response = $this->actingAs($this->user())
            ->getJson('/api/v1/procurement/vendors?sort=-state_code')
            ->assertOk();

        $this->assertSame(
            [$tnSecond->id, $tnFirst->id, $mh->id],
            collect($response->json('data'))->pluck('id')->all(),
        );
    }

    public function test_the_default_is_still_name_order_and_a_sort_composes_with_the_search(): void
    {
        Vendor::create(['code' => 'V-0003', 'name' => 'Zeta Packaging']);
        Vendor::create(['code' => 'V-0001', 'name' => 'Alpha Packaging']);
        Vendor::create(['code' => 'V-0002', 'name' => 'Mu Polymers']);

        $default = $this->actingAs($this->user())->getJson('/api/v1/procurement/vendors')->assertOk();
        $this->assertSame(['Alpha Packaging', 'Mu Polymers', 'Zeta Packaging'], collect($default->json('data'))->pluck('name')->all());

        $searched = $this->actingAs($this->user())
            ->getJson('/api/v1/procurement/vendors?q=Packaging&sort=-code')
            ->assertOk();
        $this->assertSame(['V-0003', 'V-0001'], collect($searched->json('data'))->pluck('code')->all());
        $this->assertSame(2, $searched->json('meta.total'));
    }

    public function test_page_size_is_honoured_and_the_total_is_the_whole_master(): void
    {
        Vendor::create(['code' => 'V-0001', 'name' => 'One']);
        Vendor::create(['code' => 'V-0002', 'name' => 'Two']);
        Vendor::create(['code' => 'V-0003', 'name' => 'Three']);

        $response = $this->actingAs($this->user())
            ->getJson('/api/v1/procurement/vendors?per_page=2&sort=code')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));
        $this->assertSame(['V-0001', 'V-0002'], collect($response->json('data'))->pluck('code')->all());
    }
}
