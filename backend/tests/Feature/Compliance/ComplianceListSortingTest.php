<?php

namespace Tests\Feature\Compliance;

use App\Models\User;
use App\Modules\Compliance\Models\GstRate;
use App\Modules\Compliance\Models\GstRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GST rates and registrations sort on the SERVER (03-Sep-2026): `sort` is
 * validated at the door, a named column orders the whole set with `id desc`
 * as the tiebreak, and `per_page` pages it with the real total. Every
 * GSTIN below is synthetic.
 */
class ComplianceListSortingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('compliance.view', 'web');
        $user->givePermissionTo('compliance.view');
        Sanctum::actingAs($user);
    }

    private function rate(string $code, string $percent): GstRate
    {
        return GstRate::create(['hsn_sac_code' => $code, 'description' => null, 'rate_percent' => $percent, 'is_active' => true]);
    }

    private function registration(string $stateCode, string $stateName, bool $primary = false): GstRegistration
    {
        return GstRegistration::create([
            'gstin' => $stateCode.'AAAAA0000A1Z5',
            'state_code' => $stateCode,
            'state_name' => $stateName,
            'is_primary' => $primary,
            'is_active' => true,
        ]);
    }

    /** @return list<int> */
    private function ids(string $url): array
    {
        return array_map(fn (array $row) => $row['id'], $this->getJson($url)->assertOk()->json('data'));
    }

    // ---- gst-rates --------------------------------------------------------

    public function test_gst_rates_refuse_a_sort_column_they_do_not_have(): void
    {
        $this->getJson('/api/v1/compliance/gst-rates?sort=nonsense')->assertStatus(422)->assertJsonValidationErrors('sort');
    }

    public function test_gst_rates_sort_descending_on_rate_with_newest_id_breaking_the_tie(): void
    {
        $high = $this->rate('3923', '18.00');
        $lowOld = $this->rate('1001', '5.00');
        $lowNew = $this->rate('2002', '5.00');

        $this->assertSame([$high->id, $lowNew->id, $lowOld->id], $this->ids('/api/v1/compliance/gst-rates?sort=-rate_percent'));
        // The default is still HSN/SAC order.
        $this->assertSame([$lowOld->id, $lowNew->id, $high->id], $this->ids('/api/v1/compliance/gst-rates'));
    }

    public function test_gst_rates_page_with_the_real_total(): void
    {
        $this->rate('1001', '5.00');
        $this->rate('2002', '12.00');
        $this->rate('3003', '18.00');

        $this->getJson('/api/v1/compliance/gst-rates?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3);
    }

    // ---- gst-registrations ------------------------------------------------

    public function test_gst_registrations_refuse_a_sort_column_they_do_not_have(): void
    {
        $this->getJson('/api/v1/compliance/gst-registrations?sort=nonsense')->assertStatus(422)->assertJsonValidationErrors('sort');
    }

    public function test_gst_registrations_sort_descending_on_state_with_newest_id_breaking_the_tie(): void
    {
        $tamilNadu = $this->registration('33', 'Tamil Nadu');
        $keralaOld = $this->registration('32', 'Kerala', primary: true);
        $keralaNew = $this->registration('31', 'Kerala');

        $this->assertSame([$tamilNadu->id, $keralaNew->id, $keralaOld->id], $this->ids('/api/v1/compliance/gst-registrations?sort=-state_name'));
        $this->assertSame([$keralaNew->id, $keralaOld->id, $tamilNadu->id], $this->ids('/api/v1/compliance/gst-registrations?sort=state_code'));
        // The default is still the primary first, then by state.
        $this->assertSame([$keralaOld->id, $keralaNew->id, $tamilNadu->id], $this->ids('/api/v1/compliance/gst-registrations'));
    }

    public function test_gst_registrations_page_with_the_real_total(): void
    {
        $this->registration('33', 'Tamil Nadu');
        $this->registration('32', 'Kerala');
        $this->registration('29', 'Karnataka');

        $this->getJson('/api/v1/compliance/gst-registrations?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3);
    }
}
