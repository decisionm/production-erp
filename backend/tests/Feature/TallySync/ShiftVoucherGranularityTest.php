<?php

namespace Tests\Feature\TallySync;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\PackingMaterialMapping;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\FactoryWarehouseResolver;
use App\Modules\Production\Services\ShiftProductionEntryService;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * tally-sync.voucher_granularity = 'shift': accountant-approved entries of
 * the same (production_date, shift) aggregate into ONE Stock Journal
 * (SJ-{Ymd}-S{shift_id}), consumption summed item+godown-wise and production
 * item-wise; entries approved after that voucher synced open a follow-up
 * (-2). Membership is tracked on shift_production_entries.tally_sync_entry_id
 * so an entry appears in exactly one voucher. The default 'batch' mode stays
 * byte-for-byte the original per-entry Manufacturing Journal.
 */
class ShiftVoucherGranularityTest extends TestCase
{
    use RefreshDatabase;

    private Shift $shift;

    private WorkCenter $machine;

    private Item $bottle;

    private Item $resin;

    private Item $masterbatch;

    private Warehouse $fgStore;

    private Warehouse $rmStore;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        // Voucher granularity (batch vs shift) is what this suite pins, well
        // downstream of the approval gates. The quality gate is covered in
        // BatchQualityStageTest.
        config(['production.approvals.quality_stage_enabled' => false]);

        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->machine = WorkCenter::create(['code' => 'M-01', 'name' => 'Machine 1']);
        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'NOS']);
        $this->resin = Item::create(['sku' => 'RES-1', 'name' => 'PET Resin', 'uom' => 'KG']);
        $this->masterbatch = Item::create(['sku' => 'MB-AMB', 'name' => 'Masterbatch Amber', 'uom' => 'KG']);
        $this->fgStore = Warehouse::create(['code' => 'WH-FG', 'name' => 'FG Store']);
        $this->rmStore = Warehouse::create(['code' => 'WH-RM', 'name' => 'RM Store']);
        $this->approver = User::factory()->create();
    }

    /** @param  array<int, array{0: Item, 1: string}>  $consumptions */
    private function pendingEntry(string $produced, array $consumptions, ?string $batchNumber = null): ShiftProductionEntry
    {
        $entry = ShiftProductionEntry::create([
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fgStore->id,
            'production_date' => '2026-07-23',
            'batch_status' => BatchStatus::Completed,
            'batch_number' => $batchNumber,
            'quantity_produced' => $produced,
            'quantity_scrap' => '0',
            'status' => ShiftProductionEntryStatus::Pending,
        ]);

        foreach ($consumptions as [$item, $kg]) {
            $entry->materialConsumptions()->create([
                'item_id' => $item->id,
                'warehouse_id' => $this->rmStore->id,
                'quantity_issued_kg' => $kg,
            ]);
        }

        return $entry;
    }

    private function approve(ShiftProductionEntry $entry): ShiftProductionEntry
    {
        $service = app(ShiftProductionEntryService::class);
        $service->pmApprove($entry, $this->approver->id);

        // A second account for the accountant gate (four-eyes). The voucher
        // payload carries neither approver, so the granularity assertions
        // below are unaffected by who signed.
        return $service->accountantApprove(
            $entry->fresh(),
            User::factory()->create()->id,
        );
    }

    public function test_two_approvals_in_the_same_shift_merge_into_one_summed_stock_journal(): void
    {
        config(['tally-sync.voucher_granularity' => 'shift']);

        $first = $this->approve($this->pendingEntry('5000', [[$this->resin, '250.0000']], 'B-1'));
        $second = $this->approve($this->pendingEntry('3000', [
            [$this->resin, '100.0000'],
            [$this->masterbatch, '2.0000'],
        ], 'B-2'));

        $this->assertSame(1, TallySyncEntry::count(), 'Both approvals must land in ONE shift voucher');

        $voucher = TallySyncEntry::query()->sole();
        $this->assertSame('Stock Journal', $voucher->tally_voucher_type);
        $this->assertSame("SJ-20260723-S{$this->shift->id}", $voucher->payload['voucher_number']);
        $this->assertSame('2026-07-23', $voucher->payload['voucher_date']);

        // Consumption side: item+godown-wise totals across the members.
        $this->assertSame([
            ['item' => 'PET Resin', 'quantity' => '350.0000', 'godown' => 'RM Store'],
            ['item' => 'Masterbatch Amber', 'quantity' => '2.0000', 'godown' => 'RM Store'],
        ], $voucher->payload['consumed']);

        // Production side: item-wise produced totals.
        $this->assertSame([
            ['item' => '500ml PET Bottle', 'quantity' => '8000.0000', 'godown' => 'FG Store'],
        ], $voucher->payload['produced']);

        // Explicit membership: exactly one voucher per entry, tracked on the
        // entry row itself.
        $this->assertSame([$first->id, $second->id], $voucher->payload['entry_ids']);
        $this->assertSame($voucher->id, $first->fresh()->tally_sync_entry_id);
        $this->assertSame($voucher->id, $second->fresh()->tally_sync_entry_id);
    }

    public function test_agent_ack_and_failure_fan_out_to_every_member_entry(): void
    {
        config(['tally-sync.voucher_granularity' => 'shift']);

        $first = $this->approve($this->pendingEntry('5000', [[$this->resin, '250.0000']]));
        $second = $this->approve($this->pendingEntry('3000', [[$this->resin, '100.0000']]));

        $sync = app(TallySyncService::class);
        $voucher = TallySyncEntry::query()->sole();

        $sync->markFailed($voucher, 'Tally company not loaded');
        $this->assertSame(ShiftProductionEntryStatus::Failed, $first->fresh()->status);
        $this->assertSame(ShiftProductionEntryStatus::Failed, $second->fresh()->status);

        $sync->retry($voucher);
        $sync->markSynced($voucher->fresh());
        $this->assertSame(ShiftProductionEntryStatus::Synced, $first->fresh()->status);
        $this->assertSame(ShiftProductionEntryStatus::Synced, $second->fresh()->status);
    }

    public function test_an_entry_approved_after_the_shift_voucher_synced_opens_a_follow_up_voucher(): void
    {
        config(['tally-sync.voucher_granularity' => 'shift']);

        $first = $this->approve($this->pendingEntry('5000', [[$this->resin, '250.0000']]));
        $this->approve($this->pendingEntry('3000', [[$this->resin, '100.0000']]));

        $sync = app(TallySyncService::class);
        $firstVoucher = TallySyncEntry::query()->sole();
        $sync->markSynced($firstVoucher);

        // Late paperwork: a third entry approved after the voucher is in
        // Tally's books must NOT mutate it — it opens SJ-…-2.
        $third = $this->approve($this->pendingEntry('1000', [[$this->resin, '40.0000']], 'B-3'));

        $this->assertSame(2, TallySyncEntry::count());

        $followUp = TallySyncEntry::query()->orderByDesc('id')->first();
        $this->assertSame("SJ-20260723-S{$this->shift->id}-2", $followUp->payload['voucher_number']);
        $this->assertSame([
            ['item' => 'PET Resin', 'quantity' => '40.0000', 'godown' => 'RM Store'],
        ], $followUp->payload['consumed']);
        $this->assertSame([
            ['item' => '500ml PET Bottle', 'quantity' => '1000.0000', 'godown' => 'FG Store'],
        ], $followUp->payload['produced']);
        $this->assertSame([$third->id], $followUp->payload['entry_ids']);
        $this->assertSame($followUp->id, $third->fresh()->tally_sync_entry_id);

        // The synced voucher is untouched: same members, same sums.
        $firstVoucher->refresh();
        $this->assertNotContains($third->id, $firstVoucher->payload['entry_ids']);
        $this->assertSame('350.0000', $firstVoucher->payload['consumed'][0]['quantity']);
        $this->assertSame($firstVoucher->id, $first->fresh()->tally_sync_entry_id);
    }

    public function test_re_enqueueing_a_vouchered_entry_is_idempotent(): void
    {
        config(['tally-sync.voucher_granularity' => 'shift']);

        $entry = $this->approve($this->pendingEntry('5000', [[$this->resin, '250.0000']]));
        $voucher = TallySyncEntry::query()->sole();

        // A duplicate announcement (event replay, retry) must return the
        // same voucher and must not double-count the quantities.
        $again = app(TallySyncService::class)->enqueueShiftProductionEntry($entry->fresh());

        $this->assertSame($voucher->id, $again->id);
        $this->assertSame(1, TallySyncEntry::count());
        $this->assertSame('250.0000', $voucher->fresh()->payload['consumed'][0]['quantity']);
    }

    public function test_a_delivered_voucher_is_never_merged_into(): void
    {
        config(['tally-sync.voucher_granularity' => 'shift']);
        $sync = app(TallySyncService::class);

        $first = $this->approve($this->pendingEntry('5000', [[$this->resin, '250.0000']], 'B-1'));
        $voucher = TallySyncEntry::query()->sole();

        // The agent polls: the voucher payload is now in its hands and must
        // never change under it, even though it is still Pending (unacked).
        $sync->pending();
        $this->assertNotNull($voucher->fresh()->delivered_at);

        $second = $this->approve($this->pendingEntry('3000', [[$this->resin, '100.0000']], 'B-2'));

        $this->assertSame(2, TallySyncEntry::count(), 'Second approval must open a follow-up voucher');
        $this->assertSame([$first->id], $voucher->fresh()->payload['entry_ids'], 'Delivered payload unchanged');
        $followUp = TallySyncEntry::query()->orderByDesc('id')->first();
        $this->assertSame("SJ-20260723-S{$this->shift->id}-2", $followUp->payload['voucher_number']);
        $this->assertSame([$second->id], $followUp->payload['entry_ids']);
    }

    public function test_flipping_to_shift_mode_does_not_sweep_batch_vouchered_entries(): void
    {
        // Approved under batch mode: owns a per-entry Manufacturing Journal
        // (morph-tracked, tally_sync_entry_id stays null) still Pending.
        $batchEra = $this->approve($this->pendingEntry('5000', [[$this->resin, '250.0000']], 'B-1'));
        $this->assertSame(1, TallySyncEntry::count());

        // Granularity flipped with the queue non-empty (the documented
        // wrong way) — the shift voucher must NOT absorb the batch-era
        // entry, or its quantities reach Tally twice.
        config(['tally-sync.voucher_granularity' => 'shift']);
        $shiftEra = $this->approve($this->pendingEntry('3000', [[$this->resin, '100.0000']], 'B-2'));

        $voucher = TallySyncEntry::query()->where('tally_voucher_type', 'Stock Journal')->sole();
        $this->assertSame([$shiftEra->id], $voucher->payload['entry_ids'], 'Batch-era entry must not be swept');
        $this->assertNull($batchEra->fresh()->tally_sync_entry_id);
        $this->assertSame(
            [['item' => 'PET Resin', 'quantity' => '100.0000', 'godown' => 'RM Store']],
            $voucher->payload['consumed'],
        );
    }

    public function test_default_batch_granularity_keeps_the_per_entry_manufacturing_journal(): void
    {
        // No config override — 'batch' is the default and must stay the
        // original behaviour: one Manufacturing Journal per entry, no
        // membership tracking.
        $this->assertSame('batch', config('tally-sync.voucher_granularity'));

        $entry = $this->approve($this->pendingEntry('5000', [[$this->resin, '250.0000']]));

        $voucher = TallySyncEntry::query()->sole();
        $this->assertSame('Manufacturing Journal', $voucher->tally_voucher_type);
        $this->assertSame("SPE-{$entry->id}", $voucher->payload['voucher_number']);
        $this->assertNull($entry->fresh()->tally_sync_entry_id);
    }

    /**
     * THE PACKING SPLIT HOLDS ON THIS PATH TOO.
     *
     * The owner (30-Jul): "Raw materials from the agreed RM or machine-WIP
     * location, packing materials from the Packing Material Store." Shift
     * granularity is a SECOND payload builder feeding the same Tally, and the
     * accountant's postable preview only ever evaluates the per-batch one — so
     * a split that held only there would be silently absent from every voucher
     * a factory running in shift mode actually posts.
     */
    public function test_packing_material_posts_out_of_the_packing_store_in_shift_mode_too(): void
    {
        config(['tally-sync.voucher_granularity' => 'shift']);

        $packingStore = Warehouse::create(['code' => 'WH-PACK', 'name' => 'Packing Material Store']);
        app(FactoryWarehouseResolver::class)->setPackingMaterialWarehouseId($packingStore->id);

        $carton = Item::create(['sku' => 'BOX-170', 'name' => '170 Ml Master Box', 'uom' => 'NOS']);
        PackingMaterialMapping::create([
            'spec_kind' => PackingMaterialMapping::KIND_CARTON,
            'spec_value' => '170ML',
            'item_id' => $carton->id,
        ]);

        // Both lines are issued from the RM store as far as the ERP's own
        // stock is concerned — the split is a VOUCHER-side routing decision,
        // and that is exactly why it has to be asserted on the payload.
        $this->approve($this->pendingEntry('5000', [
            [$this->resin, '250.0000'],
            [$carton, '13.0000'],
        ], 'B-1'));

        $godowns = collect(TallySyncEntry::query()->sole()->payload['consumed'])
            ->mapWithKeys(fn ($line) => [$line['item'] => $line['godown']]);

        $this->assertSame('RM Store', $godowns['PET Resin'], 'Resin keeps the store it was issued from.');
        $this->assertSame('Packing Material Store', $godowns['170 Ml Master Box']);
    }

    /**
     * And with no store named, a packing line is NOT silently redirected — it
     * keeps the godown it was issued from, the same fail-visible behaviour the
     * per-batch payload has (where the preview then refuses the post).
     */
    public function test_an_unnamed_packing_store_never_redirects_a_shift_line(): void
    {
        config(['tally-sync.voucher_granularity' => 'shift']);

        $carton = Item::create(['sku' => 'BOX-170', 'name' => '170 Ml Master Box', 'uom' => 'NOS']);
        PackingMaterialMapping::create([
            'spec_kind' => PackingMaterialMapping::KIND_CARTON,
            'spec_value' => '170ML',
            'item_id' => $carton->id,
        ]);

        $this->approve($this->pendingEntry('5000', [[$carton, '13.0000']], 'B-1'));

        $godowns = collect(TallySyncEntry::query()->sole()->payload['consumed'])
            ->mapWithKeys(fn ($line) => [$line['item'] => $line['godown']]);

        $this->assertSame('RM Store', $godowns['170 Ml Master Box']);
    }
}
