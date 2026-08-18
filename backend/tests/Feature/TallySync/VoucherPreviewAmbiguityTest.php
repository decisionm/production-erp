<?php

namespace Tests\Feature\TallySync;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\PackingMaterialMapping;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\TallySync\Services\VoucherPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * THE PREVIEW STAYS FAIL-CLOSED WHEN TWO MASTERS SHARE ONE NAME.
 *
 * items.name and warehouses.name carry no unique index, so two ERP rows can
 * share a name. Tally matches by NAME, so it would take one of them — and
 * this ERP cannot say which. Before Phase 3 the preview read the FIRST row
 * by name and so blocked (by luck of ordering) whenever that first row had
 * no GUID; Phase 3's shared resolver picks NONE for an ambiguous name, and
 * the preview's ambiguous arm was a bare `break` — so two same-named items
 * with no Tally identity previewed POSTABLE, with `uom` blank and the
 * packing-store blocker silently skipped. That is a weakened gate under the
 * owner's rule ("If the Tally preview is invalid, posting must remain
 * unavailable" — the require_postable_voucher gate reads `postable`), and
 * these pin the repair:
 *
 *   - no candidate carries a GUID  → the no-identity blocker AND the
 *                                    ambiguity blocker, whatever the row order;
 *   - one candidate carries a GUID → the ambiguity blocker alone (Tally
 *                                    might match the wrong one; the ERP
 *                                    cannot know), whatever the row order;
 *   - ANY candidate is a packing material and no packing store is named
 *                                  → the packing-store blocker still fires;
 *   - the unit is read from the candidate set (shown when they agree,
 *     null with a problem when they do not);
 *   - two same-named warehouses    → the godown ambiguity blocker.
 *
 * Whether duplicate names should BLOCK or only WARN is the owner's call
 * (PENDING-OWNER-QUESTIONS Q43); until answered the preview blocks, which
 * is what it did before Phase 3.
 */
class VoucherPreviewAmbiguityTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $fgStore;

    private Warehouse $rmStore;

    private Item $bottle;

    private Shift $shift;

    private WorkCenter $machine;

    protected function setUp(): void
    {
        parent::setUp();

        // Tally-known finished good and stores, so the only thing under
        // test is the duplicated name on the consumed line.
        $this->fgStore = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg']);
        $this->rmStore = Warehouse::create(['code' => 'RM', 'name' => 'RM Store', 'is_active' => true, 'tally_guid' => 'gd-rm']);
        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos', 'is_active' => true, 'tally_stock_item_guid' => 'itm-bottle']);
        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->machine = WorkCenter::create(['code' => 'M-01', 'name' => 'Machine 1']);
    }

    // ---- items ---------------------------------------------------------------

    public function test_two_same_named_items_neither_with_a_guid_block_with_both_the_no_identity_and_the_ambiguity_problem(): void
    {
        $first = Item::create(['sku' => 'RES-A', 'name' => 'PET Resin', 'uom' => 'Kgs', 'is_active' => true]);
        Item::create(['sku' => 'RES-B', 'name' => 'PET Resin', 'uom' => 'Kgs', 'is_active' => true]);

        $line = $this->consumedLine($this->previewConsuming($first), 'PET Resin');

        $this->assertContains(
            '"PET Resin": no Tally identity is recorded here, so this line will be refused unless a stock item of '
            .'exactly this name exists there — this ERP cannot check.',
            $line['problems'],
        );
        $this->assertContains(
            '2 items in this ERP share the name "PET Resin" — Tally would match one and this ERP cannot say which; '
            .'give them distinct names before posting.',
            $line['problems'],
        );
        // The unit is still read — both candidates agree on it.
        $this->assertSame('Kgs', $line['uom']);
    }

    public function test_two_same_named_items_one_with_a_guid_block_with_the_ambiguity_problem_alone_whatever_the_order(): void
    {
        // The GUID row FIRST — the order under which the pre-Phase-3
        // `->first()` read would have called this line clean.
        $linked = Item::create(['sku' => 'RES-A', 'name' => 'PET Resin', 'uom' => 'Kgs', 'is_active' => true, 'tally_stock_item_guid' => 'itm-resin']);
        $unlinked = Item::create(['sku' => 'RES-B', 'name' => 'PET Resin', 'uom' => 'Kgs', 'is_active' => true]);

        foreach ([$linked, $unlinked] as $consumed) {
            $preview = $this->previewConsuming($consumed);
            $line = $this->consumedLine($preview, 'PET Resin');

            $this->assertFalse($preview['postable'], "consuming {$consumed->sku}: an ambiguous name must not preview postable");
            $this->assertSame(
                ['2 items in this ERP share the name "PET Resin" — Tally would match one and this ERP cannot say which; '
                    .'give them distinct names before posting.'],
                $line['problems'],
                "consuming {$consumed->sku}: the ambiguity problem alone — one candidate IS Tally-known",
            );
            $this->assertSame('Kgs', $line['uom']);
        }
    }

    public function test_the_packing_store_blocker_still_fires_when_any_same_named_candidate_is_a_packing_material(): void
    {
        // The carton mapping points at the SECOND row; the batch consumed
        // the FIRST. A read that picked "the" row (or none) would miss the
        // packing kind; the blocker must judge the whole candidate set.
        $consumed = Item::create(['sku' => 'BOX-A', 'name' => '170 Ml Master Box', 'uom' => 'Nos', 'is_active' => true, 'tally_stock_item_guid' => 'itm-box-a']);
        $mapped = Item::create(['sku' => 'BOX-B', 'name' => '170 Ml Master Box', 'uom' => 'Nos', 'is_active' => true, 'tally_stock_item_guid' => 'itm-box-b']);
        PackingMaterialMapping::create(['spec_kind' => PackingMaterialMapping::KIND_CARTON, 'spec_value' => '170ML', 'item_id' => $mapped->id]);
        // No packing-material store is named (FactoryWarehouseResolver
        // resolves it to null) — the exact condition the blocker exists for.

        $preview = $this->previewConsuming($consumed);
        $line = $this->consumedLine($preview, '170 Ml Master Box');

        $this->assertFalse($preview['postable']);
        $this->assertNotEmpty(array_filter(
            $line['problems'],
            fn (string $problem) => str_contains($problem, 'has to be issued from the Packing Material Store'),
        ), 'the packing-store blocker went quiet on an ambiguous name: '.json_encode($line['problems']));
        $this->assertContains(
            '2 items in this ERP share the name "170 Ml Master Box" — Tally would match one and this ERP cannot say which; '
            .'give them distinct names before posting.',
            $line['problems'],
        );
    }

    public function test_the_unit_is_null_with_a_problem_when_the_same_named_candidates_disagree_on_it(): void
    {
        $first = Item::create(['sku' => 'RES-A', 'name' => 'PET Resin', 'uom' => 'Kgs', 'is_active' => true, 'tally_stock_item_guid' => 'itm-a']);
        Item::create(['sku' => 'RES-B', 'name' => 'PET Resin', 'uom' => 'Bags', 'is_active' => true, 'tally_stock_item_guid' => 'itm-b']);

        $line = $this->consumedLine($this->previewConsuming($first), 'PET Resin');

        $this->assertNull($line['uom'], 'no unit is invented when the candidates disagree');
        $this->assertContains(
            'The 2 items sharing the name "PET Resin" carry different units (Kgs, Bags), so no unit is shown for this line.',
            $line['problems'],
        );
    }

    // ---- godowns -------------------------------------------------------------

    public function test_two_same_named_warehouses_block_with_the_godown_ambiguity_problem(): void
    {
        $resin = Item::create(['sku' => 'RES-1', 'name' => 'PET Resin', 'uom' => 'Kgs', 'is_active' => true, 'tally_stock_item_guid' => 'itm-resin']);
        $store = Warehouse::create(['code' => 'ST-A', 'name' => 'Store', 'is_active' => true, 'tally_guid' => 'gd-store-a']);
        Warehouse::create(['code' => 'ST-B', 'name' => 'Store', 'is_active' => true]);

        $preview = $this->previewConsuming($resin, from: $store);
        $line = $this->consumedLine($preview, 'PET Resin');

        $this->assertFalse($preview['postable']);
        $this->assertSame(
            ['2 warehouses in this ERP share the name "Store" — Tally would match one and this ERP cannot say which; '
                .'give them distinct names before posting.'],
            $line['problems'],
        );
    }

    // ---- control -------------------------------------------------------------

    public function test_a_uniquely_named_tally_known_line_still_previews_postable(): void
    {
        $resin = Item::create(['sku' => 'RES-1', 'name' => 'PET Resin', 'uom' => 'Kgs', 'is_active' => true, 'tally_stock_item_guid' => 'itm-resin']);

        $preview = $this->previewConsuming($resin);

        $this->assertTrue($preview['postable']);
        $this->assertSame('Kgs', $this->consumedLine($preview, 'PET Resin')['uom']);
    }

    // ---- helpers ------------------------------------------------------------

    /** A completed batch consuming ONE line of $item, previewed. */
    private function previewConsuming(Item $item, ?Warehouse $from = null): array
    {
        $entry = ShiftProductionEntry::create([
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fgStore->id,
            'production_date' => '2026-07-30',
            'batch_number' => 'B-'.$item->sku,
            'batch_status' => BatchStatus::Completed,
            'status' => ShiftProductionEntryStatus::Pending,
            'quantity_produced' => '5000',
            'quantity_scrap' => '0',
        ]);
        $entry->materialConsumptions()->create([
            'item_id' => $item->id,
            'warehouse_id' => ($from ?? $this->rmStore)->id,
            'quantity_issued_kg' => '100.0000',
        ]);

        return app(VoucherPreviewService::class)->forShiftProductionEntry($entry->fresh());
    }

    /** @return array{side: string, item: ?string, quantity: mixed, uom: ?string, godown: ?string, problems: list<string>} */
    private function consumedLine(array $preview, string $itemName): array
    {
        foreach ($preview['lines'] as $line) {
            if ($line['side'] === 'consumption' && $line['item'] === $itemName) {
                return $line;
            }
        }

        $this->fail("No consumed line named \"{$itemName}\" in the preview: ".json_encode($preview['lines']));
    }
}
