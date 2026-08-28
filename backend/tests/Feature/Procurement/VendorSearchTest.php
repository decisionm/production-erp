<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Procurement\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A LIST OF 628 VENDORS NEEDS A WAY IN.
 *
 * The vendor master held four demo rows for months, so paging alone looked
 * sufficient. The import from Tally ledgers took it to 628 in one run, and the
 * page became a wall: no search, no filter, nothing to type a supplier's name
 * into. Finding one meant paging through thirteen screens.
 *
 * The clause is `ProcurementDocumentQuery::whereVendorMatches`, which is
 * already "the one vendor clause every list's `q` shares" — reused rather than
 * written again, so the vendor page and the purchase-order filter can never
 * disagree about what matching a vendor means.
 *
 * SEARCHING IS SERVER-SIDE, deliberately. Filtering the current page in the
 * browser would search 50 rows out of 628 and answer "no such vendor" for one
 * that plainly exists — the same defect this repo has now fixed on four
 * pickers, and the reason `pickerFullList.test.ts` exists.
 */
class VendorSearchTest extends TestCase
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

    private function vendors(): void
    {
        Vendor::create(['code' => 'V-0001', 'name' => 'Arihant Polyplast Private Limited']);
        Vendor::create(['code' => 'V-0002', 'name' => 'Bestow Packaging']);
        Vendor::create(['code' => 'VEN-RESIN', 'name' => 'Shri Plasto Packers']);
    }

    public function test_a_search_narrows_the_list_to_matching_names(): void
    {
        $this->vendors();

        $response = $this->actingAs($this->user())
            ->getJson('/api/v1/procurement/vendors?q=Arihant')
            ->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('Arihant Polyplast Private Limited', $response->json('data.0.name'));
    }

    /** Part of a name is enough — a storekeeper types what they remember. */
    public function test_a_partial_name_matches(): void
    {
        $this->vendors();

        $response = $this->actingAs($this->user())
            ->getJson('/api/v1/procurement/vendors?q=packag')
            ->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('Bestow Packaging', $response->json('data.0.name'));
    }

    /** The code matches too, because that is what the printed paperwork carries. */
    public function test_the_code_matches_as_well_as_the_name(): void
    {
        $this->vendors();

        $response = $this->actingAs($this->user())
            ->getJson('/api/v1/procurement/vendors?q=VEN-RESIN')
            ->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('Shri Plasto Packers', $response->json('data.0.name'));
    }

    /**
     * THE COUNT MUST BE THE MATCH COUNT. A pager reporting the unfiltered total
     * over a filtered page is how a list tells a reader there is more when there
     * is not — the defect three procurement screens were just fixed for.
     */
    public function test_the_total_counts_the_matches_not_the_whole_table(): void
    {
        $this->vendors();

        $response = $this->actingAs($this->user())
            ->getJson('/api/v1/procurement/vendors?q=a')
            ->assertOk();

        $this->assertSame(count($response->json('data')), $response->json('meta.total'));
    }

    public function test_no_search_still_returns_everything(): void
    {
        $this->vendors();

        $this->actingAs($this->user())
            ->getJson('/api/v1/procurement/vendors')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    }

    /** A term matching nothing is an empty list, never an error. */
    public function test_a_term_matching_nothing_returns_an_empty_list(): void
    {
        $this->vendors();

        $this->actingAs($this->user())
            ->getJson('/api/v1/procurement/vendors?q=zzzznothing')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    /** Whitespace is not a search — it must not return nothing. */
    public function test_a_blank_term_is_treated_as_no_search(): void
    {
        $this->vendors();

        $this->actingAs($this->user())
            ->getJson('/api/v1/procurement/vendors?q=%20%20')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    }
}
