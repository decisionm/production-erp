<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Procurement\Services\VendorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A VENDOR'S CODE IS MINTED, NOT INVENTED AT THE KEYBOARD.
 *
 * The vendor form used to demand a code the person filling it in had no way
 * to know. The live master shows what that produced: `V-DEMO-KPXL`, typed by
 * hand on 24-Jul against a vendor called "CHA / Clearing Agent" — a code
 * carrying a random suffix because the operator had no convention to follow.
 *
 * WHY A SEQUENCE AND NOT A SLUG OF THE NAME. The two generators already in
 * this repo — WarehouseService::uniqueCodeFrom() and
 * ItemService::uniqueSkuFrom() — both slug the name, and both are right for
 * what they serve: a handful of godowns, and items whose SKU a person reads
 * on its own. Neither fits a vendor list. Measured against the 633 Sundry
 * Creditors ledgers already mirrored in this database, 48 names slug to more
 * than `vendors.code`'s 32 characters, and truncating them to fit produces a
 * duplicate straight away ("Productivity Solutions Private Limited Chennai"
 * against another Productivity Solutions row). A slug also goes stale: it
 * records the spelling a name had on the day it was created, and a corrected
 * name leaves a code that disagrees with it for ever. Every screen that shows
 * a vendor code shows the name beside it, so the code buys nothing by
 * repeating it.
 *
 * WHAT THIS DOES NOT DECIDE. Codes stay unique across soft-deleted rows,
 * exactly as they were before — Q52(b), whether an archived master should
 * keep occupying its code, is open for the owner, and a generator is not the
 * place to answer it. So an archived `V-0007` still holds its number and the
 * sequence steps past it.
 */
class VendorCodeGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function service(): VendorService
    {
        return app(VendorService::class);
    }

    private function procurementUser(): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach (['procurement.view', 'procurement.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    public function test_the_first_vendor_created_without_a_code_is_minted_one(): void
    {
        $vendor = $this->service()->create(['name' => 'Reliance Industries Ltd']);

        $this->assertSame('V-0001', $vendor->code);
    }

    public function test_the_sequence_steps_on_for_each_vendor(): void
    {
        $first = $this->service()->create(['name' => 'IVL Dhunseri']);
        $second = $this->service()->create(['name' => 'JBF Industries']);

        $this->assertSame('V-0001', $first->code);
        $this->assertSame('V-0002', $second->code);
    }

    /**
     * The live master already holds `VEN-RESIN`, `VEN-CAPS`, `VEN-LABEL` and
     * `V-DEMO-KPXL`. None of them is `V-` followed by digits, so none of them
     * is a number in this sequence and none of them may shift it.
     */
    public function test_codes_that_are_not_in_the_sequence_do_not_move_it(): void
    {
        Vendor::create(['code' => 'VEN-RESIN', 'name' => 'Sri Manakula Polymers Pvt Ltd']);
        Vendor::create(['code' => 'V-DEMO-KPXL', 'name' => 'CHA / Clearing Agent']);

        $this->assertSame('V-0001', $this->service()->create(['name' => 'Reliance Industries Ltd'])->code);
    }

    public function test_the_sequence_continues_from_the_highest_number_already_used(): void
    {
        Vendor::create(['code' => 'V-0042', 'name' => 'Akash Pet Containers Pvt Ltd']);

        $this->assertSame('V-0043', $this->service()->create(['name' => 'Anil Pet Packs'])->code);
    }

    /**
     * A soft-deleted vendor still owns its code — `vendors.code` is unique
     * across trashed rows, so minting past it is the only thing that works.
     */
    public function test_an_archived_vendor_keeps_its_number_and_the_sequence_steps_past_it(): void
    {
        $retired = Vendor::create(['code' => 'V-0007', 'name' => 'Aarti Rubber & Plastics']);
        $retired->delete();

        $this->assertSame('V-0008', $this->service()->create(['name' => 'Accurate Industries'])->code);
    }

    /** A code the caller supplies is still honoured — the API contract is unchanged. */
    public function test_a_supplied_code_is_kept_as_given(): void
    {
        $vendor = $this->service()->create(['code' => 'VEN-RESIN', 'name' => 'Sri Manakula Polymers Pvt Ltd']);

        $this->assertSame('VEN-RESIN', $vendor->code);
    }

    /** A blank code is an absent code, not a code of zero length. */
    public function test_a_blank_code_is_minted_over(): void
    {
        $this->assertSame('V-0001', $this->service()->create(['code' => '   ', 'name' => 'Adexpress'])->code);
    }

    /** Past four digits the number simply gets longer; it never wraps or truncates. */
    public function test_the_sequence_grows_beyond_four_digits(): void
    {
        Vendor::create(['code' => 'V-9999', 'name' => 'Anjaneya Beltings']);

        $this->assertSame('V-10000', $this->service()->create(['name' => 'Annai Enterprises'])->code);
    }

    public function test_the_api_creates_a_vendor_with_no_code_in_the_request(): void
    {
        $this->actingAs($this->procurementUser())
            ->postJson('/api/v1/procurement/vendors', ['name' => 'Acme Drinktec Solutions LLP'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'V-0001')
            ->assertJsonPath('data.name', 'Acme Drinktec Solutions LLP');
    }

    /** Every minted code fits `vendors.code`'s 32 characters with room to spare. */
    public function test_a_minted_code_is_well_inside_the_column_limit(): void
    {
        $this->assertLessThanOrEqual(32, strlen($this->service()->create(['name' => 'Adithya Plastics Industries'])->code));
    }
}
