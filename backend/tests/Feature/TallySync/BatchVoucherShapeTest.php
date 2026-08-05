<?php

namespace Tests\Feature\TallySync;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\PackingMaterialMapping;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\FactoryWarehouseResolver;
use App\Modules\Production\Services\ShiftProductionEntryService;
use App\Modules\TallySync\Services\PackingVoucherLines;
use App\Modules\TallySync\Services\TallySyncService;
use App\Modules\TallySync\Services\VoucherPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ONE VOUCHER PER BATCH, IN THE SHAPE THE OWNER RULED (30-Jul), verbatim:
 *
 *   "At final approval, prepare one Tally manufacturing or stock-journal
 *    preview for the batch: one inward finished-product line using the
 *    QC-approved quantity, and outward lines for resin, masterbatch and every
 *    packing material actually used, including cartons, trays, film pouches
 *    and tape where applicable. Raw materials from the agreed RM or
 *    machine-WIP location, packing materials from the Packing Material Store,
 *    finished goods into the FG Store. Calculate tape from the exact
 *    metres-per-carton standard. Do not post tape until its Tally unit is
 *    metres or there is an exact approved conversion. Do not create a Tally
 *    scrap-output line until the owner confirms whether rejected pieces and
 *    lumps are physically kept as stock or discarded. If the Tally preview is
 *    invalid, posting must remain unavailable."
 *
 * Every test here is one clause of that paragraph. The two rules the paragraph
 * does NOT state, and which this suite also pins because they are the ones a
 * later change would break silently:
 *
 *  - a correction REPLACES the previous calculation. A batch amended twice
 *    posts the third set of figures, never the sum of three.
 *  - a submitted line is a counted fact and is never rewritten. The tape rule
 *    is about a CALCULATED length; three rolls somebody issued are three rolls.
 */
class BatchVoucherShapeTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_GODOWN = 'SWAASHPET POLYMERS PVT LTD';

    private Warehouse $fgStore;

    private Warehouse $rmStore;

    private Warehouse $packingStore;

    private Item $bottle;

    private Item $resin;

    private Item $carton;

    private Item $tape;

    private Shift $shift;

    private WorkCenter $machine;

    protected function setUp(): void
    {
        parent::setUp();

        // Tally-known masters throughout, so nothing in these assertions is
        // really an item-resolution failure wearing a voucher-shape hat.
        $this->fgStore = Warehouse::create([
            'code' => 'FG-STORE', 'name' => self::COMPANY_GODOWN,
            'is_active' => true, 'tally_guid' => 'gd-company',
        ]);
        $this->rmStore = Warehouse::create([
            'code' => 'RM-STORE', 'name' => 'Raw Material Store',
            'is_active' => true, 'tally_guid' => 'gd-rm',
        ]);
        $this->packingStore = Warehouse::create([
            'code' => 'PACK-STORE', 'name' => 'Packing Material Store',
            'is_active' => true, 'tally_guid' => 'gd-packing',
        ]);

        $this->bottle = Item::create([
            'sku' => 'BTL-170', 'name' => '170ml PET Bottle', 'uom' => 'Nos',
            'is_active' => true, 'tally_stock_item_guid' => 'itm-bottle',
        ]);
        $this->resin = Item::create([
            'sku' => 'PET-RESIN', 'name' => 'PET Polyster Chips', 'uom' => 'Kgs',
            'is_active' => true, 'tally_stock_item_guid' => 'itm-resin',
        ]);
        $this->carton = Item::create([
            'sku' => 'BOX-170', 'name' => '170 Ml Master Box', 'uom' => 'Nos',
            'is_active' => true, 'tally_stock_item_guid' => 'itm-carton',
        ]);
        // Counted in Nos — the open metres-vs-Nos question, exactly as the
        // live catalogue carries it.
        $this->tape = Item::create([
            'sku' => 'TAPE-T', 'name' => 'Packing Tape - Transparent', 'uom' => 'Nos',
            'is_active' => true, 'tally_stock_item_guid' => 'itm-tape',
        ]);

        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->machine = WorkCenter::create(['code' => 'M-01', 'name' => 'Machine 1']);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * A completed batch, ready to be vouchered.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function completedEntry(array $attributes = []): ShiftProductionEntry
    {
        return ShiftProductionEntry::create([
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fgStore->id,
            'production_date' => '2026-07-30',
            'batch_number' => '20260730-M01-001',
            'batch_status' => BatchStatus::Completed,
            'status' => ShiftProductionEntryStatus::Pending,
            'quantity_produced' => '5000',
            'quantity_scrap' => '0',
            ...$attributes,
        ])->fresh();
    }

    private function consume(ShiftProductionEntry $entry, Item $item, string $quantity, ?Warehouse $from = null): void
    {
        $entry->materialConsumptions()->create([
            'item_id' => $item->id,
            'warehouse_id' => ($from ?? $this->rmStore)->id,
            'quantity_issued_kg' => $quantity,
        ]);
    }

    /**
     * Name the packing-material store, through the role Production owns —
     * the real setting and the real resolver, not a stand-in, because "does
     * this module read that role correctly" is half of what is being tested.
     */
    private function nameThePackingStore(): void
    {
        app(FactoryWarehouseResolver::class)->setPackingMaterialWarehouseId($this->packingStore->id);
    }

    /** The carton mapping the factory's packing master carries. */
    private function mapCarton(): void
    {
        PackingMaterialMapping::create([
            'spec_kind' => PackingMaterialMapping::KIND_CARTON,
            'spec_value' => '170ML',
            'item_id' => $this->carton->id,
        ]);
    }

    /**
     * The tape mapping AND the standard that doses it: tape is keyed by the
     * CARTON spec, because tape is dosed by the box it seals.
     */
    private function mapTape(ShiftProductionEntry $entry, string $metresPerBox = '2.2900'): void
    {
        $standard = ProductionStandard::create([
            'item_id' => $this->bottle->id,
            'source_product_name' => $this->bottle->name,
            'cavities' => 4,
            'unit_weight_grams' => '9.0000',
            'cycle_time' => '12.00',
            'status' => 'approved',
            'source_reference' => '1',
            'carton_spec' => '170ML',
        ]);

        PackingMaterialMapping::create([
            'spec_kind' => PackingMaterialMapping::KIND_TAPE,
            'spec_value' => '170ML',
            'item_id' => $this->tape->id,
            'metres_per_box' => $metresPerBox,
        ]);

        $entry->update(['production_standard_id' => $standard->id]);
    }

    /** @return array<string, mixed> */
    private function payloadFor(ShiftProductionEntry $entry): array
    {
        return app(TallySyncService::class)->buildBatchVoucherPayload($entry->fresh());
    }

    /** @return array<string, mixed> */
    private function previewFor(ShiftProductionEntry $entry): array
    {
        return app(VoucherPreviewService::class)->forShiftProductionEntry($entry->fresh());
    }

    /** @param  list<array<string, mixed>>  $withheld */
    private function withheldOfKind(array $withheld, string $kind): ?array
    {
        foreach ($withheld as $line) {
            if ($line['kind'] === $kind) {
                return $line;
            }
        }

        return null;
    }

    // -----------------------------------------------------------------
    // 1. Inward — one finished-product line, QC-approved quantity
    // -----------------------------------------------------------------

    public function test_the_inward_line_is_the_qc_approved_quantity_never_the_packed_count(): void
    {
        // 5,000 packed and sent to QC; QC rejected 200. The books may only
        // receive the 4,800 that are sellable — and only once. The owner's own
        // warning: "QC-rejected pieces are already part of the packed
        // quantity", so nothing here subtracts them a second time.
        $entry = $this->completedEntry([
            'gross_quantity_produced' => '5000',
            'quantity_produced' => '4800',
            'quality_reviewed_nos' => 5000,
            'quality_ok_nos' => 4800,
            'quality_rejected_nos' => 200,
            'quality_checked_at' => now(),
        ]);
        $this->consume($entry, $this->resin, '160.0000');

        $payload = $this->payloadFor($entry);

        $this->assertCount(1, $payload['produced'], 'One inward line per batch, never one per packing type.');
        $this->assertSame($this->bottle->name, $payload['produced'][0]['item']);
        $this->assertSame(0, bccomp('4800', (string) $payload['produced'][0]['quantity'], 4));

        // SHAPE FROZEN, deliberately re-asserted here beside the quantity: the
        // produced line names no godown of its own — the voucher-level godown
        // is the FG store the agent books it into.
        $this->assertSame(['item', 'quantity'], array_keys($payload['produced'][0]));
        $this->assertSame(self::COMPANY_GODOWN, $payload['godown']);
    }

    public function test_an_unchecked_batch_posts_the_packed_count(): void
    {
        // A factory running with the quality stage switched off has no QC
        // rejects to net, and the packed count IS the approved count. The
        // inward figure must not silently become null or zero there.
        $entry = $this->completedEntry();
        $this->consume($entry, $this->resin, '160.0000');

        $this->assertSame(0, bccomp('5000', (string) $this->payloadFor($entry)['produced'][0]['quantity'], 4));
    }

    // -----------------------------------------------------------------
    // 2. Outward — raw material from the RM side, packing from the store
    // -----------------------------------------------------------------

    public function test_packing_material_posts_out_of_the_packing_material_store(): void
    {
        $this->nameThePackingStore();
        $this->mapCarton();

        $entry = $this->completedEntry();
        // Both issued from the RM store as far as the ERP's own stock is
        // concerned; the voucher still books the carton against the packing
        // store, which is the split the owner asked for.
        $this->consume($entry, $this->resin, '160.0000');
        $this->consume($entry, $this->carton, '13.0000');

        $payload = $this->payloadFor($entry);
        $godowns = collect($payload['consumed'])->mapWithKeys(fn ($line) => [$line['item'] => $line['godown']]);

        $this->assertSame('Raw Material Store', $godowns['PET Polyster Chips']);
        $this->assertSame('Packing Material Store', $godowns['170 Ml Master Box']);
        $this->assertSame('Packing Material Store', $payload['packing_store']);

        // Shape untouched — the accountant's frozen contract.
        $this->assertSame(['item', 'quantity', 'godown'], array_keys($payload['consumed'][0]));

        $this->assertTrue($this->previewFor($entry)['postable']);
    }

    public function test_a_packing_line_with_no_packing_store_named_refuses_to_post(): void
    {
        // Fail-closed, and specific: the resin line beside it is fine, and
        // saying so is what makes the message worth reading.
        $this->mapCarton();

        $entry = $this->completedEntry();
        $this->consume($entry, $this->resin, '160.0000');
        $this->consume($entry, $this->carton, '13.0000');

        $preview = $this->previewFor($entry);

        $this->assertFalse($preview['postable']);
        $this->assertSame([], $preview['problems'], 'The fault is on one line, not on the voucher as a whole.');

        $lines = collect($preview['lines'])->keyBy('item');
        $this->assertSame([], $lines['PET Polyster Chips']['problems']);
        $this->assertStringContainsString(
            'has to be issued from the Packing Material Store',
            $lines['170 Ml Master Box']['problems'][0],
        );
        $this->assertStringContainsString(
            'Name the packing-material store in Production settings.',
            $lines['170 Ml Master Box']['problems'][0],
        );
    }

    // -----------------------------------------------------------------
    // 3. Tape — calculated, and withheld until the unit question is answered
    // -----------------------------------------------------------------

    public function test_the_tape_is_calculated_from_the_standard_and_withheld_from_posting(): void
    {
        $this->nameThePackingStore();

        $entry = $this->completedEntry(['no_of_box' => 100, 'nos_per_box' => 50]);
        $this->mapTape($entry);
        $this->consume($entry, $this->resin, '160.0000');

        $payload = $this->payloadFor($entry);

        // Not on the voucher: Tally counts this tape in Nos, and 229 metres
        // filed as 229 Nos is a different number about a different thing.
        $this->assertSame(
            ['PET Polyster Chips'],
            collect($payload['consumed'])->pluck('item')->all(),
        );

        // But calculated, and stated: 100 cartons x 2.2900 m.
        $tape = $this->withheldOfKind($payload['withheld'], PackingVoucherLines::WITHHELD_TAPE);
        $this->assertNotNull($tape, 'The tape must be calculated even though it is not posted.');
        $this->assertSame('Packing Tape - Transparent', $tape['item']);
        $this->assertSame('229.0000', $tape['quantity']);
        $this->assertSame('m', $tape['unit']);
        $this->assertStringContainsString('100 cartons × 2.2900 m = 229.0000 m.', $tape['reason']);
        $this->assertStringContainsString('NOT posted', $tape['reason']);

        // Withholding is a decision, not a defect: the voucher still posts.
        $preview = $this->previewFor($entry);
        $this->assertTrue($preview['postable']);
        $this->assertSame($payload['withheld'], $preview['withheld']);
    }

    public function test_no_tape_line_is_calculated_when_no_carton_was_packed(): void
    {
        // Nothing was sealed, so there is nothing to state — a zero-metre line
        // would be a figure about a thing that did not happen.
        $this->nameThePackingStore();

        $entry = $this->completedEntry();
        $this->mapTape($entry);
        $this->consume($entry, $this->resin, '160.0000');

        $this->assertNull($this->withheldOfKind($this->payloadFor($entry)['withheld'], PackingVoucherLines::WITHHELD_TAPE));
    }

    public function test_tape_the_supervisor_counted_posts_like_every_other_line(): void
    {
        // THE LINE THIS MODULE MUST NOT CROSS. The open question is about a
        // CALCULATED length. Three rolls issued off the shelf is a counted
        // fact in the unit Tally uses, and the server neither drops it,
        // rewrites it, nor adds the 229 m beside it.
        $this->nameThePackingStore();

        $entry = $this->completedEntry(['no_of_box' => 100, 'nos_per_box' => 50]);
        $this->mapTape($entry);
        $this->consume($entry, $this->resin, '160.0000');
        $this->consume($entry, $this->tape, '3.0000');

        $payload = $this->payloadFor($entry);
        $tapeLine = collect($payload['consumed'])->firstWhere('item', 'Packing Tape - Transparent');

        $this->assertNotNull($tapeLine, 'A counted tape issue belongs on the voucher.');
        $this->assertSame(0, bccomp('3', (string) $tapeLine['quantity'], 4));
        $this->assertSame('Packing Material Store', $tapeLine['godown']);
        $this->assertNull(
            $this->withheldOfKind($payload['withheld'], PackingVoucherLines::WITHHELD_TAPE),
            'The calculated metres must stand down once the tape is genuinely on the voucher.',
        );
    }

    // -----------------------------------------------------------------
    // 4. No scrap output line, and the preview says so
    // -----------------------------------------------------------------

    public function test_no_scrap_output_line_reaches_the_voucher_and_the_preview_says_why(): void
    {
        // 120 pieces rejected on the machine, 200 more rejected at the quality
        // check, 4.5 kg of lumps swept off the floor.
        $entry = $this->completedEntry(['quantity_scrap' => '120']);
        $this->consume($entry, $this->resin, '160.0000');
        $entry->scraps()->create(['type' => 'lumps', 'quantity_kg' => '4.5000']);
        $entry->scraps()->create(['type' => 'rejected_finished_good', 'quantity_nos' => 200]);

        $payload = $this->payloadFor($entry);

        // One inward line, one outward line, and nothing else. No scrap item,
        // no lumps item, no regrind receipt.
        $this->assertCount(1, $payload['produced']);
        $this->assertSame(['PET Polyster Chips'], collect($payload['consumed'])->pluck('item')->all());

        // The figures are still carried — held back is not the same as lost.
        $scrap = $this->withheldOfKind($payload['withheld'], PackingVoucherLines::WITHHELD_SCRAP);
        $this->assertNotNull($scrap);
        $this->assertSame('4.5000', $scrap['quantity']);
        // The run's rejects and the scrap lines' pieces are two different
        // populations (the owner's own distinction) — stated side by side,
        // never added together.
        $this->assertStringContainsString('120 pieces rejected during the run', $scrap['reason']);
        $this->assertStringContainsString('200 pieces on its scrap lines', $scrap['reason']);
        $this->assertStringContainsString('4.5000 kg of lumps and scrap', $scrap['reason']);
        // The reason states the RULING, not an open question. The owner settled
        // it on 05-Aug — rejects and lumps are discarded — and a note that still
        // says "we have not decided" is how a decision gets re-litigated.
        $this->assertStringContainsString('discards rejects and lumps', $scrap['reason']);
        $this->assertStringNotContainsString('has not yet said', $scrap['reason']);

        $preview = $this->previewFor($entry);
        $this->assertTrue($preview['postable'], 'A withheld scrap line must not block a good voucher.');
        $this->assertNotEmpty($preview['notes']);
        $this->assertStringContainsString('No scrap line is posted to Tally', implode(' ', $preview['notes']));
    }

    public function test_scrap_posts_as_a_second_produced_line_once_the_item_is_named(): void
    {
        // THE FACTORY'S OWN PRACTICE, read out of 38 real Stock Journals from
        // their Tally: "Pet Scrap" arrives as an INWARD line in 31 of them, in
        // Kgs, priced between Rs 17 and Rs 32 per kg. Confirmed by the owner
        // (05-Aug: "yes book scrap"). A voucher missing the line their
        // accountant posts daily is one they correct by hand every time.
        $scrapItem = Item::create(['sku' => 'PET-SCRAP', 'name' => 'Pet Scrap', 'uom' => 'Kgs.']);
        config(['production.scrap.rejected_item_sku' => 'PET-SCRAP']);

        $entry = $this->completedEntry(['quantity_scrap' => '120']);
        $this->consume($entry, $this->resin, '160.0000');
        $entry->scraps()->create(['type' => 'lumps', 'quantity_kg' => '4.5000']);

        $payload = $this->payloadFor($entry);

        $names = collect($payload['produced'])->pluck('item')->all();
        $this->assertContains($scrapItem->name, $names, 'Scrap must ride the voucher as a produced line.');
        $this->assertCount(2, $payload['produced'], 'The product and its scrap — nothing else.');

        // SHAPE UNCHANGED. A produced line is ['item', 'quantity'] and the
        // scrap line is not a special case with extra keys.
        $line = collect($payload['produced'])->firstWhere('item', $scrapItem->name);
        $this->assertSame(['item', 'quantity'], array_keys($line));
        $this->assertTrue(bccomp($line['quantity'], '0', 4) === 1);

        // And it is no longer described as withheld, because it is not.
        $this->assertNull($this->withheldOfKind($payload['withheld'], PackingVoucherLines::WITHHELD_SCRAP));
    }

    public function test_a_named_scrap_item_adds_no_line_when_the_shift_scrapped_nothing(): void
    {
        // A zero line would invite Tally to create a movement for nothing.
        Item::create(['sku' => 'PET-SCRAP', 'name' => 'Pet Scrap', 'uom' => 'Kgs.']);
        config(['production.scrap.rejected_item_sku' => 'PET-SCRAP']);

        $entry = $this->completedEntry();
        $this->consume($entry, $this->resin, '160.0000');

        $this->assertCount(1, $this->payloadFor($entry)['produced']);
    }

    public function test_an_unnamed_scrap_item_still_withholds_rather_than_guessing(): void
    {
        // "Pet Scrap", "PET Scrap - Amber", "PET Scrap - Lumps" and "Pet Bottles
        // Scrap" all exist in this factory's masters. With none named, the
        // figure is reported and no weight is booked against a guess.
        config(['production.scrap.rejected_item_sku' => null]);

        $entry = $this->completedEntry(['quantity_scrap' => '120']);
        $this->consume($entry, $this->resin, '160.0000');
        $entry->scraps()->create(['type' => 'lumps', 'quantity_kg' => '4.5000']);

        $payload = $this->payloadFor($entry);
        $this->assertCount(1, $payload['produced']);
        $this->assertNotNull($this->withheldOfKind($payload['withheld'], PackingVoucherLines::WITHHELD_SCRAP));
    }

    public function test_a_batch_with_no_scrap_says_nothing_about_scrap(): void
    {
        $entry = $this->completedEntry();
        $this->consume($entry, $this->resin, '160.0000');

        $this->assertSame([], $this->payloadFor($entry)['withheld']);
        $this->assertSame([], $this->previewFor($entry)['notes']);
    }

    // -----------------------------------------------------------------
    // 5. A correction REPLACES the previous calculation
    // -----------------------------------------------------------------

    public function test_amending_a_batch_twice_leaves_only_the_last_calculation_on_the_voucher(): void
    {
        // The failure this pins: consumption is booked by INSERT and reversed
        // by a compensating stock movement, so a voucher built from anything
        // other than the live rows — the ledger, the movement history, a sum —
        // would post 150 + 130 + 118 kg of resin for a shift that used 118.
        $service = app(ShiftProductionEntryService::class);

        $entry = ShiftProductionEntry::create([
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fgStore->id,
            'production_date' => '2026-07-30',
            'batch_number' => '20260730-M01-002',
            'batch_status' => BatchStatus::InProgress,
            'status' => ShiftProductionEntryStatus::Pending,
        ]);

        $completion = fn (string $produced, string $resin) => [
            'quantity_produced' => $produced,
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'warehouse_id' => $this->rmStore->id, 'quantity_issued_kg' => $resin],
            ],
        ];

        $service->completeBatch($entry, $completion('5000', '150.0000'), null);
        $service->amendCompletion($entry->fresh(), $completion('4800', '130.0000'), null);
        $service->amendCompletion($entry->fresh(), $completion('4750', '118.5000'), null);

        $payload = $this->payloadFor($entry);

        $this->assertCount(1, $payload['consumed'], 'Three completions, one resin line — not three.');
        $this->assertSame(0, bccomp('118.5', (string) $payload['consumed'][0]['quantity'], 4));
        $this->assertSame(0, bccomp('4750', (string) $payload['produced'][0]['quantity'], 4));
    }
}
