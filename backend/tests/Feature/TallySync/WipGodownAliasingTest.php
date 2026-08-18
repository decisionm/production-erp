<?php

namespace Tests\Feature\TallySync;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\TallySync\Services\TallySyncService;
use App\Modules\TallySync\Services\VoucherPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE GODOWN A NEW VOUCHER NAMES DOES NOT CHANGE (Phase 7.5, WS-B).
 *
 * Phase 7.5 moves a batch's consumption source to Production/WIP. Tally
 * knows nothing about Production/WIP — the accountant's books have the
 * company godown and nothing else — so an unaliased WIP would rename the
 * godown on every NEW voucher, which the phase forbids outright. (Posted
 * vouchers are untouched by any of this; nothing rewrites history.)
 *
 * TallyGodownResolver already answers this for every internal location:
 * the warehouse's own tally_guid, else the nearest parent's, else the sole
 * Tally-linked godown. Production/WIP rides the same rule, and this test
 * pins both halves of it:
 *
 *  - in THIS factory's shape (one Tally godown) WIP aliases to it, so a
 *    consumption line posts under exactly the name it posts under today;
 *  - where the alias is genuinely ambiguous (several Tally-linked godowns,
 *    WIP parented to none) NOTHING IS GUESSED: the STORE ISSUE is refused
 *    at the point of handover, naming the fix, rather than letting a batch
 *    consume from a location whose voucher would later be rejected.
 *
 * Fail-closed at the issue is deliberate. The alternative — allowing the
 * issue and discovering the problem when the voucher is built hours later —
 * strands a completed batch's posting after the shift is over, which is the
 * exact failure FactoryWarehouseResolver's docblock exists to prevent.
 */
class WipGodownAliasingTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_GODOWN = 'SWAASHPET POLYMERS PVT LTD';

    private Warehouse $godown;

    private Warehouse $wip;

    private Item $resin;

    private Item $bottle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->godown = Warehouse::create([
            'code' => 'GDN-MAIN', 'name' => self::COMPANY_GODOWN,
            'is_active' => true, 'tally_guid' => 'gd-company',
        ]);

        // The WIP row the factory already has — no Tally identity of its
        // own, no parent, and inactive, exactly as the audit found it.
        $this->wip = Warehouse::create([
            'code' => 'WIP', 'name' => 'Work In Progress', 'is_active' => false,
        ]);

        $this->resin = Item::create([
            'sku' => 'PET-RESIN', 'name' => 'PET Polyster Chips', 'uom' => 'Kgs',
            'is_active' => true, 'tally_stock_item_guid' => 'itm-resin',
        ]);
        $this->bottle = Item::create([
            'sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos',
            'is_active' => true, 'tally_stock_item_guid' => 'itm-bottle',
        ]);
    }

    private function completedEntryConsumingFrom(Warehouse $consumeFrom): ShiftProductionEntry
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);

        $entry = ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->godown->id,
            'production_date' => '2026-08-19',
            'batch_number' => '20260819-M01-001',
            'batch_status' => BatchStatus::Completed,
            'quantity_produced' => '5000',
            'quantity_scrap' => '0',
        ]);

        $entry->materialConsumptions()->create([
            'item_id' => $this->resin->id,
            'warehouse_id' => $consumeFrom->id,
            'quantity_issued_kg' => '100.0000',
        ]);

        return $entry->fresh();
    }

    private function actingAsStore(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('inventory.manage', 'web');
        $user->givePermissionTo('inventory.manage');
        Sanctum::actingAs($user);

        return $user;
    }

    private function stockInTheGodown(): void
    {
        StockBalance::create([
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->godown->id,
            'quantity' => '1000.0000',
            'average_cost' => '85.0000',
        ]);
    }

    // (a) this factory's shape: one godown, and the name does not change -----

    public function test_a_consumption_line_from_production_wip_posts_under_the_company_godown(): void
    {
        $entry = $this->completedEntryConsumingFrom($this->wip);

        $payload = app(TallySyncService::class)->buildBatchVoucherPayload($entry);

        $this->assertSame(self::COMPANY_GODOWN, $payload['consumed'][0]['godown']);
        $this->assertSame(self::COMPANY_GODOWN, $payload['godown']);

        $preview = app(VoucherPreviewService::class)->forShiftProductionEntry($entry);
        $this->assertSame([], $preview['problems']);
        $this->assertTrue($preview['postable']);
    }

    public function test_the_store_may_issue_into_production_wip_in_a_one_godown_factory(): void
    {
        $user = $this->actingAsStore();
        $this->stockInTheGodown();

        $this->postJson('/api/v1/inventory/store-issues', [
            'received_by' => $user->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '100']],
        ])->assertCreated();
    }

    // (b) a SECOND godown does not stop the store handing material over -----

    /**
     * THE DAY-ONE SHAPE (AUDIT-WAREHOUSES-2026-08-17 §4).
     *
     * Two warehouse rows on this system carry a `tally_guid`, which kills
     * TallyGodownResolver's sole-linked fallback for every unparented
     * internal location — Production/WIP among them. Gating the HANDOVER on
     * that fallback meant every store issue would be refused on day one,
     * over a Tally master-data question, while the material was physically
     * walking out of the store either way.
     *
     * So the issue is allowed and the unpostable-godown question is asked
     * where it is actually answerable and actually enforced: the voucher
     * preview, and the accounts-approval gate that reads it
     * (production.approvals.require_postable_voucher). The store records the
     * physical fact; the posting waits for the master.
     */
    public function test_a_second_tally_linked_godown_does_not_refuse_the_handover(): void
    {
        Warehouse::create(['code' => 'GDN-2', 'name' => 'Second Godown', 'is_active' => true, 'tally_guid' => 'gd-second']);

        $user = $this->actingAsStore();
        $this->stockInTheGodown();

        $this->postJson('/api/v1/inventory/store-issues', [
            'received_by' => $user->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '100']],
        ])->assertCreated();
    }

    public function test_an_unaliased_wip_is_still_reported_by_the_voucher_preview(): void
    {
        Warehouse::create(['code' => 'GDN-2', 'name' => 'Second Godown', 'is_active' => true, 'tally_guid' => 'gd-second']);

        $entry = $this->completedEntryConsumingFrom($this->wip);

        $preview = app(VoucherPreviewService::class)->forShiftProductionEntry($entry);

        $this->assertFalse($preview['postable']);

        $problems = array_merge(
            $preview['problems'] ?? [],
            ...array_map(fn ($line) => $line['problems'] ?? [], $preview['lines'] ?? []),
        );

        $this->assertNotEmpty(array_filter(
            $problems,
            fn (string $problem) => str_contains($problem, 'Work In Progress'),
        ), 'the unpostable godown must be named where posting is decided');
    }

    // (b2) fail-closed where WIP itself cannot be identified -----------------

    public function test_the_issue_is_refused_when_no_production_wip_location_can_be_identified(): void
    {
        // The row DEC-20260817-001 names is what makes "issued to production"
        // a real stock state. Without it there is nowhere to issue TO, and
        // that — not a Tally master — is what the handover is refused over.
        $this->wip->update(['code' => 'NOT-WIP']);

        $user = $this->actingAsStore();
        $this->stockInTheGodown();

        $this->postJson('/api/v1/inventory/store-issues', [
            'received_by' => $user->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '100']],
        ])->assertStatus(422)->assertJsonPath(
            'errors.production_wip.0',
            ProductionWipLocationResolver::UNRESOLVED_MESSAGE,
        );
    }

    public function test_parenting_production_wip_to_the_company_godown_restores_the_godown_name(): void
    {
        Warehouse::create(['code' => 'GDN-2', 'name' => 'Second Godown', 'is_active' => true, 'tally_guid' => 'gd-second']);

        // The alias mechanism that already exists for every internal
        // location: the parent link. No row is renamed, merged or
        // deactivated — DEC-20260817-001 gates all of that. This is the fix
        // for the preview problem above, and it is master data a person
        // makes, never a side effect of an issue being recorded.
        $this->wip->update(['parent_id' => $this->godown->id, 'tally_parent_name' => self::COMPANY_GODOWN]);

        $user = $this->actingAsStore();
        $this->stockInTheGodown();

        $this->postJson('/api/v1/inventory/store-issues', [
            'received_by' => $user->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '100']],
        ])->assertCreated();

        $entry = $this->completedEntryConsumingFrom($this->wip);
        $payload = app(TallySyncService::class)->buildBatchVoucherPayload($entry);
        $this->assertSame(self::COMPANY_GODOWN, $payload['consumed'][0]['godown']);
        $this->assertTrue(app(VoucherPreviewService::class)->forShiftProductionEntry($entry)['postable']);
    }

    // (c) the resolver itself ------------------------------------------------

    public function test_the_wip_location_resolves_by_code_and_may_be_named_explicitly(): void
    {
        $resolver = app(ProductionWipLocationResolver::class);

        // Found by its canonical code, inactive or not: where the material
        // actually is beats whether the row is still selectable.
        $this->assertSame($this->wip->id, $resolver->warehouse()?->id);

        $named = Warehouse::create(['code' => 'WIP-2', 'name' => 'Production Floor', 'is_active' => true, 'parent_id' => $this->godown->id]);
        $resolver->setWarehouseId($named->id);
        $this->assertSame($named->id, $resolver->warehouse()?->id);

        $resolver->setWarehouseId(null);
        $this->assertSame($this->wip->id, $resolver->warehouse()?->id);
    }
}
