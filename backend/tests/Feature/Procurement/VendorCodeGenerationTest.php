<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Procurement\Services\VendorService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDOException;
use ReflectionMethod;
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
 * on its own. Neither fits a vendor list. A slug of this factory's supplier
 * names overruns the 32 characters `StoreVendorRequest` allows, on a
 * significant minority of them, and truncating to fit collides — the
 * measurement is against the live creditor ledger and the names themselves
 * stay out of this repository (FC-06: supplier identity is Owner/Accounts).
 * A slug also goes stale: it
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
        $vendor = $this->service()->create(['name' => 'Vendor Alpha']);

        $this->assertSame('V-0001', $vendor->code);
    }

    public function test_the_sequence_steps_on_for_each_vendor(): void
    {
        $first = $this->service()->create(['name' => 'Vendor Bravo']);
        $second = $this->service()->create(['name' => 'Vendor Charlie']);

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

        $this->assertSame('V-0001', $this->service()->create(['name' => 'Vendor Alpha'])->code);
    }

    public function test_the_sequence_continues_from_the_highest_number_already_used(): void
    {
        Vendor::create(['code' => 'V-0042', 'name' => 'Vendor Delta']);

        $this->assertSame('V-0043', $this->service()->create(['name' => 'Vendor Echo'])->code);
    }

    /**
     * A soft-deleted vendor still owns its code — `vendors.code` is unique
     * across trashed rows, so minting past it is the only thing that works.
     */
    public function test_an_archived_vendor_keeps_its_number_and_the_sequence_steps_past_it(): void
    {
        $retired = Vendor::create(['code' => 'V-0007', 'name' => 'Vendor Foxtrot']);
        $retired->delete();

        $this->assertSame('V-0008', $this->service()->create(['name' => 'Vendor Golf'])->code);
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
        $this->assertSame('V-0001', $this->service()->create(['code' => '   ', 'name' => 'Vendor Hotel'])->code);
    }

    /** Past four digits the number simply gets longer; it never wraps or truncates. */
    public function test_the_sequence_grows_beyond_four_digits(): void
    {
        Vendor::create(['code' => 'V-9999', 'name' => 'Vendor India']);

        $this->assertSame('V-10000', $this->service()->create(['name' => 'Vendor Juliet'])->code);
    }

    public function test_the_api_creates_a_vendor_with_no_code_in_the_request(): void
    {
        $this->actingAs($this->procurementUser())
            ->postJson('/api/v1/procurement/vendors', ['name' => 'Vendor Kilo'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'V-0001')
            ->assertJsonPath('data.name', 'Vendor Kilo');
    }

    /**
     * THE RETRY IS FOR A LOST RACE, NOT FOR EVERY DATABASE ERROR.
     *
     * A mint that loses to the unique index is re-read and tried again.
     * Anything else must surface on the first attempt. The two are easy to
     * confuse: both arrive as SQLSTATE 23000, and the message carries the
     * whole INSERT — which names the `code` column on every mint — so a check
     * matching only the column name would retry a NOT NULL failure five times
     * before telling anyone. The classifier is read directly because the
     * exception that finally escapes the loop looks identical either way,
     * and a failing query fires no query-log event to count.
     */
    public function test_only_a_duplicate_code_counts_as_a_lost_race(): void
    {
        $classify = new ReflectionMethod(VendorService::class, 'isDuplicateCode');

        $mysqlDuplicate = "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'V-0001' for key 'vendors.vendors_code_unique'";
        $sqliteDuplicate = 'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: vendors.code';
        $notNull = 'SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint failed: vendors.name (insert into "vendors" ("code", "name") values (V-0001, ))';

        $this->assertTrue($classify->invoke($this->service(), $this->constraintViolation($mysqlDuplicate)));
        $this->assertTrue($classify->invoke($this->service(), $this->constraintViolation($sqliteDuplicate)));
        $this->assertFalse(
            $classify->invoke($this->service(), $this->constraintViolation($notNull)),
            'a NOT NULL failure was read as a lost race and would be retried five times',
        );
    }

    /** A QueryException shaped the way a driver raises an integrity-constraint violation. */
    private function constraintViolation(string $message): QueryException
    {
        return new QueryException('sqlite', 'insert into "vendors" ("code", "name") values (?, ?)', [], new PDOException($message, 23000));
    }

    /** Every minted code fits the 32 characters `StoreVendorRequest` allows, with room to spare. */
    public function test_a_minted_code_is_well_inside_the_column_limit(): void
    {
        $this->assertLessThanOrEqual(32, strlen($this->service()->create(['name' => 'Vendor Lima'])->code));
    }
}
