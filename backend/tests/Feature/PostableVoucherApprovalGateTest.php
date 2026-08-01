<?php

namespace Tests\Feature;

use App\Exceptions\InvalidStatusTransitionException;
use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\FactoryWarehouseResolver;
use App\Modules\Production\Services\ShiftProductionEntryService;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE POSTING GATE'S OWN PRECONDITION, and the Packing Material Store the
 * voucher needs before it can name one.
 *
 * The owner (31-Jul): "If the Tally preview is invalid, posting must remain
 * unavailable." Accountant approval IS the posting gate here — it is the
 * transition that enqueues the voucher — so that is where the preview's
 * verdict is consulted, through the EXISTING VoucherPreviewService, which
 * builds its payload with the same method the real post uses.
 *
 * IT SHIPS WATCH-ONLY (production.approvals.require_postable_voucher =
 * false), and both directions are pinned below, because "we can turn it on"
 * is worth nothing if nobody has checked that turning it on refuses the right
 * batch and lets the right one through. The reason for the default is in
 * config/production.php: what makes a voucher unpostable is master-data
 * coverage, and a true default would reach a server whose .env had not been
 * edited and refuse every approval in the factory on the next shift.
 */
class PostableVoucherApprovalGateTest extends TestCase
{
    use RefreshDatabase;

    private Shift $shift;

    private WorkCenter $machine;

    protected function setUp(): void
    {
        parent::setUp();

        // This suite is about the posting gate, not the quality one.
        config(['production.approvals.quality_stage_enabled' => false]);

        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'is_active' => true]);
    }

    /** A batch whose voucher Tally would accept: item and godown both known. */
    private function postableEntry(): ShiftProductionEntry
    {
        $item = Item::create([
            'sku' => 'BTL-500', 'name' => '500 ml Round', 'uom' => 'Nos.',
            'is_active' => true, 'tally_stock_item_guid' => 'itm-bottle',
        ]);

        return $this->entryFor($item, Warehouse::create([
            'code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg',
        ]));
    }

    /** The live failure this gate exists for: a product Tally has never heard of. */
    private function unpostableEntry(): ShiftProductionEntry
    {
        $item = Item::create([
            'sku' => 'BTL-501', 'name' => '501 ml Round', 'uom' => 'Nos.', 'is_active' => true,
        ]);

        return $this->entryFor($item, Warehouse::create([
            'code' => 'FG2', 'name' => 'FG Store 2', 'is_active' => true, 'tally_guid' => 'gd-fg2',
        ]));
    }

    /**
     * A LOCAL- fixture: it exists here and nowhere in Tally, deliberately.
     * No voucher is ever built for it, so there is nothing for a posting gate
     * to protect.
     */
    private function localFixtureEntry(): ShiftProductionEntry
    {
        $item = Item::create([
            'sku' => 'LOCAL-BTL-9', 'name' => 'Local fixture bottle', 'uom' => 'Nos.', 'is_active' => true,
        ]);

        return $this->entryFor($item, Warehouse::create([
            'code' => 'FG3', 'name' => 'FG Store 3', 'is_active' => true, 'tally_guid' => 'gd-fg3',
        ]));
    }

    private function entryFor(Item $item, Warehouse $warehouse): ShiftProductionEntry
    {
        return ShiftProductionEntry::create([
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => '2026-07-31',
            'batch_status' => BatchStatus::Completed,
            'quantity_produced' => '1000',
            'quantity_scrap' => '0',
            'status' => ShiftProductionEntryStatus::Pending,
        ]);
    }

    // (a) the gate, both ways ---------------------------------------------------

    public function test_with_the_gate_on_a_batch_tally_would_reject_cannot_be_approved(): void
    {
        config(['production.approvals.require_postable_voucher' => true]);

        $entry = $this->unpostableEntry();
        $service = app(ShiftProductionEntryService::class);
        $service->pmApprove($entry, User::factory()->create()->id);

        try {
            $service->accountantApprove($entry->fresh(), User::factory()->create()->id);
            $this->fail('A batch whose voucher Tally would refuse was approved anyway.');
        } catch (InvalidStatusTransitionException $e) {
            // Factory words, and the actual reason — an accountant needs to
            // know WHICH master to fix, not that a boolean was false.
            $this->assertStringContainsString('cannot be posted to Tally yet', $e->getMessage());
            $this->assertStringContainsString('501 ml Round', $e->getMessage());
            $this->assertStringContainsString('no Tally identity', $e->getMessage());
        }

        // Refused means refused: still at pm_approved, still unposted.
        $fresh = $entry->fresh();
        $this->assertSame(ShiftProductionEntryStatus::PmApproved, $fresh->status);
        $this->assertNull($fresh->getRawOriginal('accountant_signed_by'));
        $this->assertSame(0, TallySyncEntry::count(), 'A refused approval must never enqueue a voucher.');
    }

    public function test_with_the_gate_on_a_postable_batch_approves_and_posts_exactly_as_before(): void
    {
        config(['production.approvals.require_postable_voucher' => true]);

        $entry = $this->postableEntry();
        $service = app(ShiftProductionEntryService::class);
        $service->pmApprove($entry, User::factory()->create()->id);
        $approved = $service->accountantApprove($entry->fresh(), User::factory()->create()->id);

        $this->assertSame(ShiftProductionEntryStatus::Approved, $approved->status);
        $this->assertSame(1, TallySyncEntry::count());
    }

    public function test_with_the_gate_off_the_same_unpostable_batch_approves(): void
    {
        // The shipped default. The gate evaluates nothing and approval
        // behaves exactly as it did before this existed — which is what
        // keeps a server on an unedited .env from refusing every shift.
        $this->assertFalse((bool) config('production.approvals.require_postable_voucher'));

        $entry = $this->unpostableEntry();
        $service = app(ShiftProductionEntryService::class);
        $service->pmApprove($entry, User::factory()->create()->id);
        $approved = $service->accountantApprove($entry->fresh(), User::factory()->create()->id);

        $this->assertSame(ShiftProductionEntryStatus::Approved, $approved->status);
    }

    public function test_a_local_fixture_is_exempt_because_it_is_never_posted_at_all(): void
    {
        config(['production.approvals.require_postable_voucher' => true]);

        $entry = $this->localFixtureEntry();
        $service = app(ShiftProductionEntryService::class);
        $service->pmApprove($entry, User::factory()->create()->id);
        $approved = $service->accountantApprove($entry->fresh(), User::factory()->create()->id);

        // The batch is real and gets recorded; only the posting was never
        // going to happen, so refusing would strand it for nothing.
        $this->assertSame(ShiftProductionEntryStatus::Approved, $approved->status);
        $this->assertSame(0, TallySyncEntry::count(), 'A product Tally does not know is never queued to it.');
    }

    public function test_a_wrong_status_call_still_reports_the_status_not_the_voucher(): void
    {
        config(['production.approvals.require_postable_voucher' => true]);

        // Straight to the accountant with no PM signature. The real problem
        // is the skipped stage, and that is what the message must say —
        // the gate must not shout about a voucher for an entry that was
        // never eligible anyway.
        $entry = $this->unpostableEntry();

        try {
            app(ShiftProductionEntryService::class)->accountantApprove($entry, User::factory()->create()->id);
            $this->fail('A pending entry was approved without the PM stage.');
        } catch (InvalidStatusTransitionException $e) {
            $this->assertStringContainsString('Cannot transition', $e->getMessage());
            $this->assertStringNotContainsString('Tally', $e->getMessage());
        }
    }

    // (b) the Packing Material Store ---------------------------------------------

    private function actAsSettingsUser(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['production.view', 'production.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo(['production.view', 'production.manage']);
        Sanctum::actingAs($user);
    }

    public function test_the_packing_store_is_named_in_settings_and_read_back(): void
    {
        $this->actAsSettingsUser();

        $packing = Warehouse::create([
            'code' => 'PACK', 'name' => 'Packing Material Store', 'is_active' => true, 'tally_guid' => 'gd-pack',
        ]);

        $this->putJson('/api/v1/production/settings/factory-warehouses', [
            'packing_material_warehouse_id' => $packing->id,
        ])->assertOk()
            ->assertJsonPath('data.packing_material_warehouse_id', $packing->id)
            ->assertJsonPath('data.packing_material_resolved_warehouse_id', $packing->id);

        $this->getJson('/api/v1/production/settings')
            ->assertOk()
            ->assertJsonPath('data.packing_material_warehouse_id', $packing->id);

        $this->assertSame($packing->id, app(FactoryWarehouseResolver::class)->packingMaterial()?->id);
    }

    public function test_an_unnamed_packing_store_resolves_to_null_with_no_silent_fallback(): void
    {
        $this->actAsSettingsUser();

        // The single Tally-linked warehouse — the fallback the other two
        // roles legitimately take.
        $factory = Warehouse::create([
            'code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg',
        ]);

        $resolver = app(FactoryWarehouseResolver::class);

        $this->assertSame($factory->id, $resolver->finishedGoods()?->id);
        $this->assertSame($factory->id, $resolver->rawMaterial()?->id);

        // And NOT for packing material. Falling back here would issue tape
        // and cartons out of the resin godown — a confidently wrong answer,
        // where for the other roles it is the only possible one.
        $this->assertNull(
            $resolver->packingMaterial(),
            'An unresolved packing store must stay null so the voucher preview can name it.',
        );

        $this->getJson('/api/v1/production/settings')
            ->assertOk()
            ->assertJsonPath('data.packing_material_warehouse_id', null)
            ->assertJsonPath('data.packing_material_resolved_warehouse_id', null);
    }

    public function test_naming_the_packing_store_never_disturbs_the_other_roles(): void
    {
        $this->actAsSettingsUser();

        $fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg']);
        $rm = Warehouse::create(['code' => 'RM', 'name' => 'RM Store', 'is_active' => true, 'tally_guid' => 'gd-rm']);
        $packing = Warehouse::create(['code' => 'PACK', 'name' => 'Packing Store', 'is_active' => true]);

        $resolver = app(FactoryWarehouseResolver::class);
        $resolver->setFinishedGoodsWarehouseId($fg->id);
        $resolver->setRawMaterialWarehouseId($rm->id);

        $this->putJson('/api/v1/production/settings/factory-warehouses', [
            'packing_material_warehouse_id' => $packing->id,
        ])->assertOk()
            ->assertJsonPath('data.finished_goods_warehouse_id', $fg->id)
            ->assertJsonPath('data.raw_material_warehouse_id', $rm->id)
            ->assertJsonPath('data.packing_material_warehouse_id', $packing->id);

        // And clearing it is a real operation, not a no-op.
        $this->putJson('/api/v1/production/settings/factory-warehouses', [
            'packing_material_warehouse_id' => null,
        ])->assertOk()
            ->assertJsonPath('data.packing_material_warehouse_id', null)
            ->assertJsonPath('data.finished_goods_warehouse_id', $fg->id);
    }

    public function test_an_inactive_warehouse_is_refused_for_the_packing_store(): void
    {
        $this->actAsSettingsUser();

        $retired = Warehouse::create(['code' => 'OLD', 'name' => 'Old Packing', 'is_active' => false]);

        // Same rule as its two neighbours: the resolver reads an inactive
        // setting as "not set", so storing one would show configured on the
        // screen while every payload resolved elsewhere.
        $this->putJson('/api/v1/production/settings/factory-warehouses', [
            'packing_material_warehouse_id' => $retired->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['packing_material_warehouse_id']);
    }
}
