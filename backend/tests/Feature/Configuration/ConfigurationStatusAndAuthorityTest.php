<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\MoldStatus;
use App\Modules\Production\Models\Mold;
use App\Support\Configuration\ActiveFlag;
use App\Support\Configuration\ConfigurationInUseException;
use App\Support\Configuration\ConfigurationLifecycle;
use App\Support\Configuration\DependencyCheck;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * The three remaining Phase 7.6 review findings on the shared mechanism:
 *
 *  · a STATUS-ENUM master (Mold, Asset, MeasuringInstrument carry a
 *    BackedEnum `status`, not a boolean `is_active`) was read as ACTIVE
 *    while retired, and archiving it wrote `false` into its status column;
 *  · WHO may hard-delete (DEC-20260817-002 §3, Super Admin / Owner only)
 *    had no hook at all — the mechanism deleted for anyone who reached it;
 *  · an ARCHIVED (soft-deleted) master answered 404 instead of the
 *    contract's own answer.
 */
class ConfigurationStatusAndAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private function mold(MoldStatus $status = MoldStatus::Active): Mold
    {
        return Mold::create([
            'code' => 'MLD-'.$status->value,
            'name' => 'Mould '.$status->value,
            'cavity_count' => 4,
            'status' => $status,
        ]);
    }

    /** A mould's lifecycle: `molds` has no cascading child, so nothing is declared. */
    private function moldLifecycle(): ConfigurationLifecycle
    {
        return new ConfigurationLifecycle(
            label: 'mould',
            checks: [],
            activeColumn: ActiveFlag::status('status', active: MoldStatus::Active, retired: MoldStatus::Retired),
            canHardDelete: fn (?Authenticatable $user): bool => true,
        );
    }

    private function warehouseLifecycle(): ConfigurationLifecycle
    {
        return new ConfigurationLifecycle(
            label: 'warehouse',
            checks: [DependencyCheck::table('stock_balances', 'warehouse_id')->label('stock balance')->cascadeSide()],
            canHardDelete: fn (?Authenticatable $user): bool => true,
        );
    }

    // ---- status-enum masters -------------------------------------------

    public function test_a_retired_status_master_is_not_read_as_active(): void
    {
        $retired = $this->mold(MoldStatus::Retired);

        $can = $this->moldLifecycle()->abilities($retired);

        $this->assertTrue($can['activate'], 'a retired mould may be put back in service');
        $this->assertFalse($can['archive'], 'and cannot be retired twice');
    }

    public function test_an_active_status_master_may_be_archived_and_not_activated(): void
    {
        $can = $this->moldLifecycle()->abilities($this->mold());

        $this->assertFalse($can['activate']);
        $this->assertTrue($can['archive']);
    }

    /**
     * `MoldStatus` has THREE cases, so "not active" and "retired" are
     * different sentences. An under-repair mould is neither: it may be put
     * back in service, and it may be retired. Deriving one from the other
     * would strand it in a state it could never leave.
     */
    public function test_an_intermediate_status_is_neither_active_nor_retired(): void
    {
        $can = $this->moldLifecycle()->abilities($this->mold(MoldStatus::UnderRepair));

        $this->assertTrue($can['activate']);
        $this->assertTrue($can['archive']);
    }

    public function test_archiving_a_status_master_writes_the_retired_case_not_false(): void
    {
        $mold = $this->mold();

        $this->moldLifecycle()->archive($mold, 'cracked core');

        $this->assertSame(MoldStatus::Retired, $mold->fresh()->status);
        $this->assertSame('retired', DB::table('molds')->where('id', $mold->id)->value('status'));
        $this->assertNotNull(Mold::find($mold->id), 'archive deletes nothing');
        $this->assertFalse($mold->fresh()->trashed(), 'and does not soft-delete a master that has a status column');
    }

    public function test_activating_a_status_master_writes_the_active_case(): void
    {
        $mold = $this->mold(MoldStatus::Retired);

        $this->moldLifecycle()->activate($mold, 'refurbished');

        $this->assertSame(MoldStatus::Active, $mold->fresh()->status);
    }

    public function test_an_under_repair_mould_can_be_retired_and_reactivated(): void
    {
        $mold = $this->mold(MoldStatus::UnderRepair);

        $this->moldLifecycle()->archive($mold);
        $this->assertSame(MoldStatus::Retired, $mold->fresh()->status);

        $this->moldLifecycle()->activate($mold);
        $this->assertSame(MoldStatus::Active, $mold->fresh()->status);
    }

    public function test_a_boolean_master_is_unchanged_by_the_status_mode(): void
    {
        $warehouse = Warehouse::create(['code' => 'WH-B', 'name' => 'Boolean store', 'is_active' => true]);

        $this->warehouseLifecycle()->archive($warehouse);
        $this->assertFalse($warehouse->fresh()->is_active);
        $this->assertTrue($this->warehouseLifecycle()->abilities($warehouse->fresh())['activate']);
        $this->assertFalse($this->warehouseLifecycle()->abilities($warehouse->fresh())['archive']);

        $this->warehouseLifecycle()->activate($warehouse->fresh());
        $this->assertTrue($warehouse->fresh()->is_active);
    }

    /**
     * The wrong declaration is not merely unsupported — it is refused. A
     * module that declares a status column as if it were a boolean flag gets
     * told, rather than quietly writing `false` into an enum column and
     * reading every case as "in service".
     */
    public function test_declaring_a_status_column_as_a_boolean_flag_is_refused(): void
    {
        $mold = $this->mold();

        $wrong = new ConfigurationLifecycle(label: 'mould', checks: [], activeColumn: 'status');

        try {
            $wrong->archive($mold);
            $this->fail('A boolean declaration on a status column must be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('is a status enum, not a boolean flag', $e->getMessage());
            $this->assertStringContainsString('ActiveFlag::status', $e->getMessage());
        }

        $this->assertSame(MoldStatus::Active, $mold->fresh()->status, 'and nothing was written');
    }

    // ---- who may hard-delete (DEC-20260817-002 §3) ----------------------

    /**
     * The seam is FAIL-CLOSED. A lifecycle that names no authority deletes
     * nothing — the repo cannot yet express "Super Admin", and the honest
     * reading of a rule it cannot express is "not by default".
     */
    public function test_a_hard_delete_is_refused_when_no_authority_is_declared(): void
    {
        $mold = $this->mold();

        $unwired = new ConfigurationLifecycle(
            label: 'mould',
            checks: [],
            activeColumn: ActiveFlag::status('status', active: MoldStatus::Active, retired: MoldStatus::Retired),
        );

        // FALSE, not null: "you may not" is a decision, and no amount of
        // counting would change it — so the cheap list answers it too,
        // without paying a single COUNT.
        $this->assertFalse($unwired->abilities($mold)['delete']);
        $this->assertFalse($unwired->abilities($mold, resolveDelete: false)['delete']);

        try {
            $unwired->delete($mold);
            $this->fail('An unauthorised hard delete must be refused.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('Super Admin / Owner', $e->getMessage());
        }

        $this->assertNotNull(Mold::find($mold->id));
    }

    public function test_the_seam_is_asked_first_and_is_given_the_acting_user(): void
    {
        $mold = $this->mold();
        $user = new User(['name' => 'Somebody']);
        $seen = [];

        $lifecycle = new ConfigurationLifecycle(
            label: 'mould',
            checks: [DependencyCheck::callable(function (): int {
                throw new \RuntimeException('the dependency checks must not run for a user who may not delete at all');
            }, 'never')->label('never')],
            activeColumn: ActiveFlag::status('status', active: MoldStatus::Active, retired: MoldStatus::Retired),
            canHardDelete: function (?Authenticatable $actor) use (&$seen): bool {
                $seen[] = $actor;

                return false;
            },
        );

        $this->expectException(AuthorizationException::class);

        try {
            $lifecycle->delete($mold, $user);
        } finally {
            $this->assertSame([$user], $seen);
            $this->assertNotNull(Mold::find($mold->id));
        }
    }

    public function test_the_seam_falls_back_to_the_authenticated_user(): void
    {
        $mold = $this->mold();
        $user = User::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'secret-secret']);
        $this->be($user);

        $seen = [];
        $lifecycle = new ConfigurationLifecycle(
            label: 'mould',
            checks: [],
            activeColumn: ActiveFlag::status('status', active: MoldStatus::Active, retired: MoldStatus::Retired),
            canHardDelete: function (?Authenticatable $actor) use (&$seen): bool {
                $seen[] = $actor?->getAuthIdentifier();

                return true;
            },
        );

        $lifecycle->delete($mold);

        $this->assertSame([$user->getKey()], $seen);
        $this->assertNull(Mold::withTrashed()->find($mold->id));
    }

    // ---- an archived master gets the contract's answer, not a 404 -------

    public function test_an_archived_master_that_is_still_used_is_refused_with_its_reasons(): void
    {
        $warehouse = Warehouse::create(['code' => 'WH-ARCH', 'name' => 'Archived store', 'is_active' => false]);
        $item = Item::create(['sku' => 'ITM-A', 'name' => 'Item A', 'uom' => 'Nos', 'is_active' => true]);
        StockBalance::create(['item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity' => '5']);
        $warehouse->delete();

        $this->assertTrue($warehouse->fresh()->trashed());

        try {
            $this->warehouseLifecycle()->delete($warehouse);
            $this->fail('Expected the contract refusal, not a 404.');
        } catch (ConfigurationInUseException $e) {
            $this->assertSame(
                [['code' => 'stock_balances', 'label' => 'stock balance', 'count' => 1]],
                $e->payload()['blocking'],
            );
        }

        $this->assertNotNull(Warehouse::withTrashed()->find($warehouse->id));
    }

    public function test_an_archived_master_that_is_provably_unused_is_deleted(): void
    {
        $warehouse = Warehouse::create(['code' => 'WH-GONE', 'name' => 'Archived and unused', 'is_active' => false]);
        $warehouse->delete();

        $this->warehouseLifecycle()->delete($warehouse);

        $this->assertNull(Warehouse::withTrashed()->find($warehouse->id));
    }

    public function test_a_record_that_really_does_not_exist_still_answers_not_found(): void
    {
        $warehouse = Warehouse::create(['code' => 'WH-VANISH', 'name' => 'Vanishing store', 'is_active' => true]);
        Warehouse::withTrashed()->where('id', $warehouse->id)->forceDelete();

        $this->expectException(ModelNotFoundException::class);
        $this->warehouseLifecycle()->delete($warehouse);
    }

    public function test_an_archived_master_is_still_offered_activate_and_a_resolved_delete(): void
    {
        $warehouse = Warehouse::create(['code' => 'WH-CAN', 'name' => 'Archived store', 'is_active' => false]);
        $warehouse->delete();

        $can = $this->warehouseLifecycle()->abilities($warehouse->fresh());

        $this->assertTrue($can['activate']);
        $this->assertFalse($can['archive']);
        $this->assertFalse($can['edit']);
        $this->assertTrue($can['delete'], 'being archived does not make a never-used record used (DEC-20260817-002 §1)');
    }
}
