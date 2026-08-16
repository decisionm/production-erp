<?php

namespace Tests\Feature\TallySync;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ProductReadinessService;
use App\Modules\TallySync\Services\TallySyncService;
use App\Modules\TallySync\Services\VoucherPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GODOWN ALIASING — the keystone of the internal-day-bin design.
 *
 * This factory's Tally has EXACTLY ONE godown (the company godown; every
 * voucher line the accountant ever booked uses it). The ERP's factory day
 * bin is a real second warehouse LOCALLY — so scan-loads and bin-vs-store
 * balances work — but it must stay invisible to the accountant's books:
 * any voucher line whose warehouse is the internal bin posts under the
 * godown name of its PARENT (fallback: the single Tally-linked godown).
 *
 * One resolver (TallyGodownResolver) answers this for the voucher payload
 * builders, the voucher preview AND the readiness gate, so the three can
 * never disagree about whether a warehouse is postable.
 */
class GodownAliasingTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_GODOWN = 'SWAASHPET POLYMERS PVT LTD';

    private Warehouse $godown;

    private Warehouse $dayBin;

    private Item $resin;

    private Item $bottle;

    protected function setUp(): void
    {
        parent::setUp();

        // The one real Tally godown, and the internal day bin as its child.
        $this->godown = Warehouse::create([
            'code' => 'GDN-MAIN', 'name' => self::COMPANY_GODOWN,
            'is_active' => true, 'tally_guid' => 'gd-company',
        ]);
        $this->dayBin = Warehouse::create([
            'code' => 'WH-DAYBIN', 'name' => 'Factory Day Bin', 'is_active' => true,
            'parent_id' => $this->godown->id, 'tally_parent_name' => self::COMPANY_GODOWN,
        ]);

        // Tally-known items, so item resolution never muddies the godown assertions.
        $this->resin = Item::create([
            'sku' => 'PET-RESIN', 'name' => 'PET Polyster Chips', 'uom' => 'Kgs',
            'is_active' => true, 'tally_stock_item_guid' => 'itm-resin',
        ]);
        $this->bottle = Item::create([
            'sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos',
            'is_active' => true, 'tally_stock_item_guid' => 'itm-bottle',
        ]);
    }

    /** A completed batch producing into $fg, consuming resin from $consumeFrom. */
    private function completedEntry(Warehouse $fg, Warehouse $consumeFrom): ShiftProductionEntry
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);

        $entry = ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $fg->id,
            'production_date' => '2026-07-30',
            'batch_number' => '20260730-M01-001',
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

    public function test_a_day_bin_consumption_line_posts_under_the_parent_godown_name(): void
    {
        $entry = $this->completedEntry(fg: $this->godown, consumeFrom: $this->dayBin);

        $payload = app(TallySyncService::class)->buildBatchVoucherPayload($entry);

        // The line was issued from the internal bin, but Tally is told the
        // PARENT godown's name — the accountant's books never see the bin.
        $this->assertSame(self::COMPANY_GODOWN, $payload['consumed'][0]['godown']);
        $this->assertSame(self::COMPANY_GODOWN, $payload['godown']);
    }

    public function test_the_preview_no_longer_flags_a_day_bin_line(): void
    {
        $entry = $this->completedEntry(fg: $this->godown, consumeFrom: $this->dayBin);

        $preview = app(VoucherPreviewService::class)->forShiftProductionEntry($entry);

        $this->assertSame([], $preview['problems']);
        $this->assertSame([], collect($preview['lines'])->pluck('problems')->flatten()->all());
        $this->assertTrue($preview['postable']);
    }

    public function test_an_unparented_bin_in_a_one_godown_system_falls_back_to_the_sole_godown(): void
    {
        // No parent link at all — but the system has exactly ONE Tally-linked
        // godown, so there is nothing to choose between: this factory's reality.
        $orphanBin = Warehouse::create(['code' => 'LOOSE', 'name' => 'Loose Bin', 'is_active' => true]);
        $entry = $this->completedEntry(fg: $this->godown, consumeFrom: $orphanBin);

        $payload = app(TallySyncService::class)->buildBatchVoucherPayload($entry);
        $this->assertSame(self::COMPANY_GODOWN, $payload['consumed'][0]['godown']);

        $preview = app(VoucherPreviewService::class)->forShiftProductionEntry($entry);
        $this->assertTrue($preview['postable']);
    }

    public function test_a_multi_godown_system_without_a_parent_still_flags(): void
    {
        // With SEVERAL real godowns, an unlinked, unparented warehouse is
        // genuinely ambiguous — nothing is guessed; the preview flags it
        // exactly as before the alias rule existed.
        Warehouse::create(['code' => 'GDN-2', 'name' => 'Second Godown', 'is_active' => true, 'tally_guid' => 'gd-second']);
        $orphanBin = Warehouse::create(['code' => 'LOOSE', 'name' => 'Loose Bin', 'is_active' => true]);
        $entry = $this->completedEntry(fg: $this->godown, consumeFrom: $orphanBin);

        $payload = app(TallySyncService::class)->buildBatchVoucherPayload($entry);
        $this->assertSame('Loose Bin', $payload['consumed'][0]['godown']);

        $preview = app(VoucherPreviewService::class)->forShiftProductionEntry($entry);
        $this->assertFalse($preview['postable']);
        // The preview's sentence is in the resolver's register — what is
        // recorded here and what follows — not a claim about Tally's answer.
        $this->assertContains(
            'Godown "Loose Bin": no Tally identity is recorded here and it aliases to no Tally-known godown, so this '
            .'line will be refused unless a godown of exactly this name exists there — this ERP cannot check.',
            collect($preview['lines'])->pluck('problems')->flatten()->all(),
        );
    }

    public function test_readiness_accepts_a_warehouse_that_resolves_via_the_alias_rule(): void
    {
        config()->set('production.readiness.enforced', true);

        // The day bin (child of the godown) and an unparented bin in this
        // one-godown system both resolve — neither may be reported missing.
        foreach ([$this->dayBin, Warehouse::create(['code' => 'LOOSE', 'name' => 'Loose Bin', 'is_active' => true])] as $warehouse) {
            $assessment = app(ProductReadinessService::class)->assess($this->bottle, $warehouse);

            $codes = array_merge(
                array_column($assessment['blocking'], 'code'),
                array_column($assessment['warnings'], 'code'),
            );
            $this->assertNotContains('tally_godown', $codes, "\"{$warehouse->name}\" should alias to the company godown.");
        }
    }

    public function test_readiness_still_refuses_an_unresolvable_warehouse(): void
    {
        config()->set('production.readiness.enforced', true);

        Warehouse::create(['code' => 'GDN-2', 'name' => 'Second Godown', 'is_active' => true, 'tally_guid' => 'gd-second']);
        $orphanBin = Warehouse::create(['code' => 'LOOSE', 'name' => 'Loose Bin', 'is_active' => true]);

        $assessment = app(ProductReadinessService::class)->assess($this->bottle, $orphanBin);

        $this->assertContains('tally_godown', array_column($assessment['blocking'], 'code'));
    }

    public function test_a_goods_receipt_into_the_day_bin_also_posts_under_the_parent_godown(): void
    {
        // Same rule on the procurement voucher — the resolver is not a
        // production-only patch.
        $po = new PurchaseOrder;
        $po->setRelation('vendor', new Vendor(['name' => 'Reliance Industries']));

        $line = new GoodsReceiptNoteLine(['quantity' => '100.0000', 'unit_cost' => '118.5000']);
        $line->setRelation('item', $this->resin);

        $grn = new GoodsReceiptNote(['received_date' => now()]);
        $grn->id = 7;
        $grn->exists = true;
        $grn->setRelation('lines', collect([$line]));
        $grn->setRelation('warehouse', $this->dayBin);
        $grn->setRelation('purchaseOrder', $po);

        $entry = app(TallySyncService::class)->enqueueGoodsReceiptNote($grn);

        $this->assertSame(self::COMPANY_GODOWN, $entry->payload['godown']);
    }
}
