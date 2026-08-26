<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\ItemService;
use App\Modules\Production\Models\ProductionStandard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GET production/configuration/review — the list of what a person still has
 * to settle before every packing posts as ONE known Tally item (Phase 5,
 * P5-03): packagings and standards without a Tally identity, packagings
 * whose Tally name is shared by more than one item, and items still carrying
 * the SKU the masters pull seeded. Every LINKABLE row offers the existing
 * Tally items a person could LINK (exact/normalised name match, Tally-pulled
 * and never a fixture) — the ERP never creates a Tally-less item for real
 * production.
 *
 * Plus, since DEC-20260821-001, a fourth kind that offers no link at all:
 * a packing whose own Tally identity is a DIFFERENT item from the product it
 * sits under is a SEPARATE FINISHED PRODUCT, and no link on this screen makes
 * one. Those rows lead the list; the last group of tests below is theirs.
 */
class ConfigurationReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        $user->givePermissionTo('production.view');
        Sanctum::actingAs($user);
    }

    private function review(): array
    {
        return $this->getJson('/api/v1/production/configuration/review')->assertOk()->json('data');
    }

    private function tallyItem(string $sku, string $name, string $guid): Item
    {
        return Item::create(['sku' => $sku, 'name' => $name, 'uom' => 'NOS', 'is_active' => true, 'tally_stock_item_guid' => $guid]);
    }

    /**
     * The rows of one kind, in the order they arrived. Read by KIND rather
     * than by index: one packing can legitimately raise more than one row
     * (its identity is a separate product AND its name is ambiguous, say),
     * so an index is an assertion about a neighbouring question.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function rowsOfKind(array $rows, string $kind): array
    {
        return array_values(array_filter($rows, fn (array $row) => $row['kind'] === $kind));
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function oneRowOfKind(array $rows, string $kind): array
    {
        $found = $this->rowsOfKind($rows, $kind);
        $this->assertCount(1, $found, "expected exactly one {$kind} row");

        return $found[0];
    }

    private function standard(string $product, ?Item $item, array $extra = []): ProductionStandard
    {
        return ProductionStandard::create([
            'source_product_name' => $product, 'item_id' => $item?->id,
            'cavities' => 8, 'unit_weight_grams' => 18, 'cycle_time' => 12, 'status' => 'approved',
        ] + $extra);
    }

    public function test_a_fully_configured_catalogue_has_nothing_to_review(): void
    {
        $bottle = $this->tallyItem('BTL-1', 'B.200 Ml Round Pet Bottle Amber 18gms - 520 Nos', 'itm-1');
        $standard = $this->standard('200ML RA', $bottle);
        $standard->packagings()->create(['mode' => 'pouch', 'nos_per_pouch' => 130, 'pouches_per_box' => 4, 'nos_per_box' => 520]);

        $this->assertSame(['rows' => []], $this->review());
    }

    public function test_a_packaging_inheriting_a_fixture_identity_is_listed_with_the_tally_items_it_could_link(): void
    {
        // The product is a LOCAL- fixture (Tally does not carry it yet); a
        // Tally item of exactly the workbook's product name has since been
        // pulled — that is the candidate, and it is the only one.
        $fixture = Item::create(['sku' => 'LOCAL-200ML-RA', 'name' => '200ML RA (LOCAL FIXTURE)', 'uom' => 'Nos', 'is_local_fixture' => true]);
        $real = $this->tallyItem('BTL-200', '200ml  ra', 'itm-200'); // spacing and case differ: normalised match
        $this->tallyItem('BTL-OTHER', '200ML RA CLEAR', 'itm-201');    // not an exact match: not offered
        Item::create(['sku' => 'LOCAL-TWIN', 'name' => '200ML RA', 'uom' => 'Nos', 'is_local_fixture' => true]); // a fixture is never a candidate

        $standard = $this->standard('200ML RA', $fixture);
        $packaging = $standard->packagings()->create(['mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5, 'nos_per_box' => 490]);

        $rows = $this->review()['rows'];

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('packaging_no_identity', $row['kind']);
        $this->assertSame(['id' => $standard->id, 'product' => '200ML RA'], $row['standard']);
        $this->assertSame([
            'id' => $packaging->id, 'mode' => 'tray',
            'counts' => ['nos_per_pouch' => null, 'pouches_per_box' => null, 'nos_per_tray' => 98, 'trays_per_box' => 5, 'nos_per_box' => 490],
        ], $row['packaging']);
        // The identity it resolves to today — the fixture — shown, not hidden.
        $this->assertSame(['id' => $fixture->id, 'sku' => 'LOCAL-200ML-RA', 'name' => '200ML RA (LOCAL FIXTURE)'], $row['item']);
        $this->assertSame(['tally_identity'], $row['missing']);
        $this->assertSame([[
            'id' => $real->id, 'sku' => 'BTL-200', 'name' => '200ml  ra', 'guid' => 'itm-200',
        ]], $row['candidates']);
        // Where the fix goes: the existing PUT standards/{standard}/packagings/{packaging}.
        $this->assertSame('packaging_item', $row['fix_target']);
    }

    public function test_a_standard_with_no_item_and_no_packaging_is_one_standard_level_row(): void
    {
        $standard = $this->standard('90ML RIB', null);
        $candidate = $this->tallyItem('BTL-90', '90ML RIB', 'itm-90');

        $rows = $this->review()['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('packaging_no_identity', $rows[0]['kind']);
        $this->assertSame(['id' => $standard->id, 'product' => '90ML RIB'], $rows[0]['standard']);
        $this->assertNull($rows[0]['packaging']);
        $this->assertNull($rows[0]['item']);
        $this->assertSame(['tally_identity'], $rows[0]['missing']);
        $this->assertSame([$candidate->id], array_column($rows[0]['candidates'], 'id'));
        // A standard-level gap is closed by attaching the product's item.
        $this->assertSame('attach_item', $rows[0]['fix_target']);
    }

    public function test_a_packaging_with_its_own_good_identity_is_a_separate_product_row_and_the_identity_gap_stays_at_standard_level(): void
    {
        $fixture = Item::create(['sku' => 'LOCAL-X', 'name' => 'X (LOCAL FIXTURE)', 'uom' => 'Nos', 'is_local_fixture' => true]);
        $real = $this->tallyItem('BTL-X', 'X Bottle', 'itm-x');
        $standard = $this->standard('X', $fixture);
        $packaging = $standard->packagings()->create(['mode' => 'direct_box', 'nos_per_box' => 100, 'item_id' => $real->id]);

        $rows = $this->review()['rows'];

        // Nothing on the standard inherits the fixture, so the standard's
        // own gap is what is left at IDENTITY level — one row, at standard
        // level. (The packing's own identity also differs from the product's
        // fixture, which is the separate-product row beside it; the two
        // questions are independent and both are asked. Filtered by kind, not
        // by index, so neither assertion is about the other's position.)
        $identityRows = $this->rowsOfKind($rows, 'packaging_no_identity');
        $this->assertCount(1, $identityRows);
        $this->assertNull($identityRows[0]['packaging']);
        $this->assertSame('attach_item', $identityRows[0]['fix_target']);

        // ...and the separate-product row IS the packing's listing — asserted
        // rather than left to the parenthesis above, because this test's old
        // name claimed the packing was "not listed" at all, which stopped
        // being the contract when DEC-20260821-001 landed.
        $separate = $this->rowsOfKind($rows, 'packaging_separate_product');
        $this->assertCount(1, $separate);
        $this->assertSame($packaging->id, $separate[0]['packaging']['id']);
        $this->assertSame($real->id, $separate[0]['item']['id']);
    }

    public function test_a_shared_tally_name_is_listed_as_ambiguous_with_the_rows_that_share_it_as_candidates(): void
    {
        $bottle = $this->tallyItem('BTL-1', 'B.200 Ml Round Pet Bottle Amber 18gms - 520 Nos', 'itm-1');
        $a = $this->tallyItem('BTL-A', 'B.200 Ml Round Pet Bottle Amber 18gms', 'itm-a');
        $b = $this->tallyItem('BTL-B', 'B.200 Ml Round Pet Bottle Amber 18gms', 'itm-b');
        // A fixture sharing the name is what LineMappingResolver counts too,
        // but it is never OFFERED — linking it would be linking nothing.
        Item::create(['sku' => 'LOCAL-C', 'name' => 'B.200 Ml Round Pet Bottle Amber 18gms', 'uom' => 'Nos', 'is_local_fixture' => true]);

        $standard = $this->standard('200ML RA', $bottle);
        $packaging = $standard->packagings()->create([
            'mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5, 'nos_per_box' => 490, 'item_id' => $a->id,
        ]);

        $rows = $this->review()['rows'];

        // The packing's own identity ($a) also differs from the product
        // ($bottle), so it is ALSO a separate-product row — a different
        // question about the same packing, and neither suppresses the other.
        $ambiguous = $this->rowsOfKind($rows, 'packaging_ambiguous');
        $this->assertCount(1, $ambiguous);
        $row = $ambiguous[0];
        $this->assertSame('packaging_ambiguous', $row['kind']);
        $this->assertSame($packaging->id, $row['packaging']['id']);
        $this->assertSame($a->id, $row['item']['id']);
        $this->assertSame([], $row['missing']);
        $this->assertSame(['shared_name_count' => 3], $row['ambiguity']);
        $this->assertSame([$a->id, $b->id], array_column($row['candidates'], 'id'));
        // ADVISORY: linking either row does not clear the ambiguity — Tally
        // matches a voucher line by NAME, and both rows carry it. The panel
        // offers no Link here; the duplicate is a catalogue question (Q43).
        $this->assertSame('name_ambiguity', $row['fix_target']);
    }

    public function test_an_inactive_item_is_never_offered_as_a_candidate(): void
    {
        // Two kinds of row, one rule: a retired item cannot become a packing's
        // identity (the identity requests refuse it), so offering it would
        // offer a refusal.
        $fixture = Item::create(['sku' => 'LOCAL-200ML-RA', 'name' => '200ML RA (LOCAL FIXTURE)', 'uom' => 'Nos', 'is_local_fixture' => true]);
        $active = $this->tallyItem('BTL-200', '200ML RA', 'itm-200');
        Item::create(['sku' => 'BTL-200-OLD', 'name' => '200ML RA', 'uom' => 'NOS', 'is_active' => false, 'tally_stock_item_guid' => 'itm-200-old']);
        $this->standard('200ML RA', $fixture)->packagings()->create(['mode' => 'direct_box', 'nos_per_box' => 100]);

        $bottle = $this->tallyItem('BTL-1', 'Bottle One - 520 Nos', 'itm-1');
        $a = $this->tallyItem('BTL-A', 'Bottle One', 'itm-a');
        $this->tallyItem('BTL-B', 'Bottle One', 'itm-b');
        Item::create(['sku' => 'BTL-C', 'name' => 'Bottle One', 'uom' => 'NOS', 'is_active' => false, 'tally_stock_item_guid' => 'itm-c']);
        $this->standard('ONE', $bottle)->packagings()->create(['mode' => 'direct_box', 'nos_per_box' => 10, 'item_id' => $a->id]);

        $rows = $this->review()['rows'];

        $identityRow = $this->oneRowOfKind($rows, 'packaging_no_identity');
        $this->assertSame([$active->id], array_column($identityRow['candidates'], 'id'));

        // ('Bottle One''s packing carries $a while its standard carries
        // $bottle, so it is a separate-product row too — read by kind.)
        $ambiguousRow = $this->oneRowOfKind($rows, 'packaging_ambiguous');
        $this->assertNotContains('BTL-C', array_column($ambiguousRow['candidates'], 'sku'));
        $this->assertSame(['BTL-A', 'BTL-B'], array_column($ambiguousRow['candidates'], 'sku'));
    }

    public function test_an_item_still_carrying_its_seeded_sku_is_listed(): void
    {
        $item = app(ItemService::class)->upsertFromTally(['guid' => 'itm-new', 'name' => 'B.170ml Pet Bottle', 'base_unit' => 'Nos'])['item'];
        // A same-named Tally item is the one candidate worth showing beside
        // it — the duplicate a real SKU would have to distinguish from.
        $twin = $this->tallyItem('BTL-170-OLD', 'b.170ml pet bottle', 'itm-old');

        $rows = $this->review()['rows'];

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('item_provisional_sku', $row['kind']);
        $this->assertNull($row['standard']);
        $this->assertNull($row['packaging']);
        $this->assertSame(['id' => $item->id, 'sku' => 'B.170ml Pet Bottle', 'name' => 'B.170ml Pet Bottle'], $row['item']);
        $this->assertSame([], $row['missing']);
        $this->assertSame([$twin->id], array_column($row['candidates'], 'id'));
        $this->assertSame('item_sku', $row['fix_target']);

        // A person setting a SKU takes it off the list.
        Item::query()->whereKey($item->id)->update(['sku' => 'BTL-170', 'sku_provisional' => false]);
        $this->assertSame([], $this->review()['rows']);
    }

    public function test_rows_come_in_a_stable_order_separate_products_then_identity_gaps_then_ambiguity_then_provisional_skus(): void
    {
        // The separate-product row leads: a packing that belongs under
        // another product is being asked the wrong questions below.
        $trayProduct = $this->tallyItem('BTL-S', 'Split Product', 'itm-s');
        $trayIdentity = $this->tallyItem('BTL-S-TRAY', 'Split Product - Tray', 'itm-s-tray');
        $this->standard('SPLIT', $trayProduct)->packagings()->create([
            'mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5, 'nos_per_box' => 490, 'item_id' => $trayIdentity->id,
        ]);

        $fixture = Item::create(['sku' => 'LOCAL-Z', 'name' => 'Z (LOCAL FIXTURE)', 'uom' => 'Nos', 'is_local_fixture' => true]);
        $this->standard('Z', $fixture)->packagings()->create(['mode' => 'direct_box', 'nos_per_box' => 10]);

        $bottle = $this->tallyItem('BTL-1', 'Bottle One', 'itm-1');
        $this->tallyItem('BTL-1B', 'Bottle One', 'itm-1b');
        $this->standard('ONE', $bottle)->packagings()->create(['mode' => 'direct_box', 'nos_per_box' => 10]);

        app(ItemService::class)->upsertFromTally(['guid' => 'itm-p', 'name' => 'Provisional Bottle', 'base_unit' => 'Nos']);

        $kinds = array_column($this->review()['rows'], 'kind');

        $this->assertSame(
            ['packaging_separate_product', 'packaging_no_identity', 'packaging_ambiguous', 'item_provisional_sku'],
            $kinds,
        );
    }

    // ---- DEC-20260821-001 — the packing that is a separate product -----------
    //
    // EVIDENCE ONLY. These rows report configuration that ALREADY EXISTS and
    // may already have posted vouchers; nothing here refuses, clears or
    // rewrites anything. The forward guard that stops NEW ones shipped
    // separately (SeparateProductForDistinctPackingTest).
    //
    // The fixture names below are deliberately NEUTRAL ("Bottle A - Pouch").
    // The live Tally identity of the real 490 tray is not evidenced anywhere
    // in this repo and stays open in Q33 — a test fixture that looked like a
    // live name would be exactly the invented factory value AGENTS.md forbids.

    public function test_a_packing_posting_as_a_different_tally_item_from_its_product_is_listed_as_a_separate_product(): void
    {
        $pouchProduct = $this->tallyItem('BTL-P', 'Bottle A - Pouch', 'itm-pouch');
        $trayItem = $this->tallyItem('BTL-T', 'Bottle A - Tray', 'itm-tray');
        // A name match for the STANDARD's product name, so the row would have
        // candidates to offer if it asked for any. It must not: the answer to
        // this row is a separate PRODUCT, and nothing on the screen makes one.
        $this->tallyItem('BTL-N', 'BOTTLE A', 'itm-named');

        $standard = $this->standard('BOTTLE A', $pouchProduct);
        $packaging = $standard->packagings()->create([
            'mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5, 'nos_per_box' => 490,
            'item_id' => $trayItem->id,
        ]);

        $rows = $this->review()['rows'];

        // Exactly one row, and it is the new one: both items are real Tally
        // rows with GUIDs and unshared names, so no identity or ambiguity
        // question is open on this packing at all.
        $this->assertCount(1, $rows);
        $row = $rows[0];

        $this->assertSame('packaging_separate_product', $row['kind']);
        $this->assertSame(['id' => $standard->id, 'product' => 'BOTTLE A'], $row['standard']);
        $this->assertSame([
            'id' => $packaging->id, 'mode' => 'tray',
            'counts' => ['nos_per_pouch' => null, 'pouches_per_box' => null, 'nos_per_tray' => 98, 'trays_per_box' => 5, 'nos_per_box' => 490],
        ], $row['packaging']);
        // BOTH ends of the relation: what the packing posts as...
        $this->assertSame(['id' => $trayItem->id, 'sku' => 'BTL-T', 'name' => 'Bottle A - Tray', 'archived' => false], $row['item']);
        // ...and the product it is currently sitting under.
        $this->assertSame(['id' => $pouchProduct->id, 'sku' => 'BTL-P', 'name' => 'Bottle A - Pouch', 'archived' => false], $row['product_item']);
        // Nothing is "missing" — the vocabulary is unchanged (P5-06); the
        // fact this row carries is its KIND.
        $this->assertSame([], $row['missing']);
        $this->assertNull($row['ambiguity']);
        // NO CANDIDATES, and a target no writer answers to.
        $this->assertSame([], $row['candidates']);
        $this->assertSame('separate_product', $row['fix_target']);
    }

    public function test_a_packing_that_inherits_its_products_identity_is_never_a_separate_product_row(): void
    {
        // INHERITANCE — item_id null, the majority of live rows. The packing
        // posts as its product, which is exactly what the decision wants.
        $product = $this->tallyItem('BTL-P', 'Bottle B', 'itm-b');
        $standard = $this->standard('BOTTLE B', $product);
        $standard->packagings()->create(['mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5, 'nos_per_box' => 490]);

        $this->assertSame(['rows' => []], $this->review());
    }

    public function test_a_packing_restating_its_products_own_identity_is_never_a_separate_product_row(): void
    {
        // One product's identity said twice — same item on both ends. No
        // second product is implied, so there is nothing to report.
        $product = $this->tallyItem('BTL-P', 'Bottle C', 'itm-c');
        $standard = $this->standard('BOTTLE C', $product);
        $standard->packagings()->create([
            'mode' => 'pouch', 'nos_per_pouch' => 130, 'pouches_per_box' => 4, 'nos_per_box' => 520,
            'item_id' => $product->id,
        ]);

        $this->assertSame(['rows' => []], $this->review());
    }

    public function test_an_unattached_standard_has_no_product_to_conflict_with(): void
    {
        // production_standards.item_id null: the packing's identity is the
        // only postable identity the row has, so it contradicts nothing.
        // (The standard's own missing identity is still reported — by the
        // row kind that has always reported it.)
        $trayItem = $this->tallyItem('BTL-T', 'Bottle D - Tray', 'itm-d-tray');
        $standard = $this->standard('BOTTLE D', null);
        $standard->packagings()->create(['mode' => 'direct_box', 'nos_per_box' => 100, 'item_id' => $trayItem->id]);

        $rows = $this->review()['rows'];

        $this->assertSame([], $this->rowsOfKind($rows, 'packaging_separate_product'));
        $this->assertSame(['packaging_no_identity'], array_column($rows, 'kind'));
    }

    public function test_a_separate_product_row_coexists_with_every_other_kind_and_suppresses_none(): void
    {
        // ONE packing carrying three independent questions at once: its
        // identity is a different product from the standard's, that identity
        // is a LOCAL fixture (so it resolves to nothing postable), and the
        // product's own name is shared by two catalogue rows.
        $fixtureIdentity = Item::create(['sku' => 'LOCAL-E', 'name' => 'Bottle E - Tray (LOCAL FIXTURE)', 'uom' => 'Nos', 'is_local_fixture' => true]);
        $product = $this->tallyItem('BTL-E', 'Bottle E', 'itm-e');
        $standard = $this->standard('BOTTLE E', $product);
        $packaging = $standard->packagings()->create([
            'mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5, 'nos_per_box' => 490,
            'item_id' => $fixtureIdentity->id,
        ]);

        // A separate standard whose product's SKU is still the seeded one.
        app(ItemService::class)->upsertFromTally(['guid' => 'itm-prov', 'name' => 'Provisional Bottle', 'base_unit' => 'Nos']);

        $rows = $this->review()['rows'];

        // The separate-product row comes FIRST, and every pre-existing kind
        // is still raised about the same packing.
        $this->assertSame(
            ['packaging_separate_product', 'packaging_no_identity', 'item_provisional_sku'],
            array_column($rows, 'kind'),
        );
        $this->assertSame($packaging->id, $rows[0]['packaging']['id']);
        $this->assertSame($packaging->id, $rows[1]['packaging']['id']);
        // The pre-existing row still says what it always said: the resolved
        // identity is a fixture, so the Tally identity is missing, and its
        // Link target is untouched by this PR.
        $this->assertSame(['tally_identity'], $rows[1]['missing']);
        $this->assertSame('packaging_item', $rows[1]['fix_target']);
    }

    public function test_the_row_kinds_that_predate_the_decision_carry_exactly_their_old_payload(): void
    {
        // `product_item` is added ONLY to the new kind. The three older kinds
        // are a contract two screens already read; a key appearing on them
        // would be a change to rows this PR does not touch.
        $trayItem = $this->tallyItem('BTL-T', 'Bottle F - Tray', 'itm-f-tray');
        // A SECOND row carrying the tray's name, so the packing's resolved
        // identity is AMBIGUOUS and the third old kind is actually produced.
        // Without it this fixture raises only two of the three, and the loop
        // below would pin `packaging_ambiguous` vacuously.
        $this->tallyItem('BTL-T2', 'Bottle F - Tray', 'itm-f-tray-2');
        $fixture = Item::create(['sku' => 'LOCAL-F', 'name' => 'Bottle F (LOCAL FIXTURE)', 'uom' => 'Nos', 'is_local_fixture' => true]);
        $standard = $this->standard('BOTTLE F', $fixture);
        $standard->packagings()->create(['mode' => 'direct_box', 'nos_per_box' => 100, 'item_id' => $trayItem->id]);
        app(ItemService::class)->upsertFromTally(['guid' => 'itm-prov', 'name' => 'Provisional Bottle', 'base_unit' => 'Nos']);

        $rows = $this->review()['rows'];
        $old = ['kind', 'standard', 'packaging', 'item', 'missing', 'ambiguity', 'candidates', 'fix_target'];

        foreach ($rows as $row) {
            $expected = $row['kind'] === 'packaging_separate_product' ? [...$old, 'product_item'] : $old;
            $this->assertSame($expected, array_keys($row), "payload keys changed on a {$row['kind']} row");
        }

        // ...and EVERY kind the loop claims to pin was actually present, so
        // none of them passed vacuously. Named individually rather than as a
        // count: a fixture that quietly stopped producing one kind would
        // otherwise still satisfy a total.
        foreach ([
            'packaging_separate_product',
            'packaging_no_identity',
            'packaging_ambiguous',
            'item_provisional_sku',
        ] as $kind) {
            $this->assertNotSame([], $this->rowsOfKind($rows, $kind), "no {$kind} row was produced, so its keys were never pinned");
        }
    }

    public function test_a_separate_product_row_names_its_identity_even_when_that_item_row_is_archived(): void
    {
        // THE PACKING IS ACTIVE; only the ITEM it names has been retired.
        // Item soft-deletes and archiving one does not blank the packagings
        // pointing at it, so `item_id` is still plainly set — and the
        // predicate judges that column, so the row is raised. Resolving the
        // display through the identity relation that HIDES trashed rows made
        // it render "posts as no Tally identity" over an identity that is
        // set, which is simply false.
        // The product carries a REAL Tally identity on purpose. Nothing on
        // this standard inherits it (the sole packaging has its own item_id),
        // so identityRows()'s `$inheritors === 0` branch is reached — and
        // stays silent only because hasTallyIdentity($product) is true. Give
        // the product a fixture or a GUID-less item here and a third,
        // standard-level row joins the two below, for reasons that have
        // nothing to do with the archived identity this test is about.
        $product = $this->tallyItem('BTL-G', 'Bottle G', 'itm-g');
        $archivedTray = $this->tallyItem('BTL-G-T', 'Bottle G - Tray', 'itm-g-tray');
        $standard = $this->standard('BOTTLE G', $product);
        $packaging = $standard->packagings()->create([
            'mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5, 'nos_per_box' => 490,
            'item_id' => $archivedTray->id,
        ]);

        $archivedTray->delete();
        $this->assertSoftDeleted('items', ['id' => $archivedTray->id]);

        $rows = $this->review()['rows'];

        // EXACTLY ONE separate-product row; it names the archived item AND
        // says the retirement out loud (`archived` => true), because
        // "posts as sku · name" rendered from the name alone reads as a live
        // identity — and the packaging_no_identity row that corrects it can
        // sit a table page away.
        $row = $this->oneRowOfKind($rows, 'packaging_separate_product');
        $this->assertSame(
            ['id' => $archivedTray->id, 'sku' => 'BTL-G-T', 'name' => 'Bottle G - Tray', 'archived' => true],
            $row['item'],
        );
        $this->assertSame(['id' => $product->id, 'sku' => 'BTL-G', 'name' => 'Bottle G', 'archived' => false], $row['product_item']);
        $this->assertSame($packaging->id, $row['packaging']['id']);
        // Still advisory, still nothing to link.
        $this->assertSame([], $row['candidates']);
        $this->assertSame('separate_product', $row['fix_target']);
        $this->assertSame([], $row['missing']);

        // AND THE LEGACY ROW IS UNTOUCHED — deliberately, and the coexistence
        // is not a contradiction: the two rows answer different questions.
        // This one still resolves through the HIDING relation, so a retired
        // item is absent, the resolved identity is null and the packing
        // cannot post today. Same kind, same null `item`, same missing word,
        // same Link target as before this fix.
        $legacy = $this->oneRowOfKind($rows, 'packaging_no_identity');
        $this->assertSame($packaging->id, $legacy['packaging']['id']);
        $this->assertNull($legacy['item']);
        $this->assertSame(['tally_identity'], $legacy['missing']);
        $this->assertSame('packaging_item', $legacy['fix_target']);
        $this->assertSame(
            ['kind', 'standard', 'packaging', 'item', 'missing', 'ambiguity', 'candidates', 'fix_target'],
            array_keys($legacy),
            'the legacy row gained a key',
        );

        $this->assertSame(['packaging_separate_product', 'packaging_no_identity'], array_column($rows, 'kind'));
    }

    public function test_a_separate_product_row_names_its_product_even_when_that_product_item_row_is_archived(): void
    {
        // THE OTHER END OF THE SAME DEFECT. Above, the PACKING's item was
        // retired; here the PRODUCT's is. `production_standards.item_id`
        // outlives the item row exactly as the packaging column does — Item
        // soft-deletes and archiving one does not blank the standards
        // pointing at it — so `identityConflictsWithProduct` still judges two
        // set, differing columns and still raises the row. Naming the product
        // through the HIDING relation rendered "under the product no Tally
        // identity" over a product that is plainly recorded.
        //
        // The TRAY here is deliberately NOT archived: this test must fail for
        // the product resolution alone, not ride on the packaging fix.
        $product = $this->tallyItem('BTL-H', 'Bottle H', 'itm-h');
        $tray = $this->tallyItem('BTL-H-T', 'Bottle H - Tray', 'itm-h-tray');
        $standard = $this->standard('BOTTLE H', $product);
        $packaging = $standard->packagings()->create([
            'mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5, 'nos_per_box' => 490,
            'item_id' => $tray->id,
        ]);

        $product->delete();
        $this->assertSoftDeleted('items', ['id' => $product->id]);

        $rows = $this->review()['rows'];

        // EXACTLY ONE separate-product row, and it names BOTH ends: the
        // active tray it posts as, and the ARCHIVED product it sits under.
        $row = $this->oneRowOfKind($rows, 'packaging_separate_product');
        $this->assertSame(
            ['id' => $product->id, 'sku' => 'BTL-H', 'name' => 'Bottle H', 'archived' => true],
            $row['product_item'],
            'the archived product must still be named — this is the fix',
        );
        $this->assertSame(['id' => $tray->id, 'sku' => 'BTL-H-T', 'name' => 'Bottle H - Tray', 'archived' => false], $row['item']);
        $this->assertSame($packaging->id, $row['packaging']['id']);
        // Still advisory, still nothing to link.
        $this->assertSame([], $row['candidates']);
        $this->assertSame('separate_product', $row['fix_target']);
        $this->assertSame([], $row['missing']);

        // AND THE LEGACY ROWS ARE UNTOUCHED. `identityRows()` still reads the
        // HIDING relation, so the retired product is absent there — which is
        // correct for the question that list asks (what a run would post as),
        // and is why the fix is scoped to `separateProductRows()` alone. With
        // the product gone the standard has no identity of its own and no
        // packaging inheriting it, so it says so once, itself, exactly as it
        // did before this fix.
        $legacy = $this->oneRowOfKind($rows, 'packaging_no_identity');
        $this->assertNull($legacy['packaging'], 'the legacy row is standard-level');
        $this->assertNull($legacy['item'], 'the hiding relation still hides the retired product');
        $this->assertSame(['tally_identity'], $legacy['missing']);
        $this->assertSame('attach_item', $legacy['fix_target']);
        $this->assertSame(
            ['kind', 'standard', 'packaging', 'item', 'missing', 'ambiguity', 'candidates', 'fix_target'],
            array_keys($legacy),
            'the legacy row gained a key',
        );

        $this->assertSame(['packaging_separate_product', 'packaging_no_identity'], array_column($rows, 'kind'));
    }

    public function test_resolving_archived_products_costs_a_constant_number_of_queries(): void
    {
        // The PRODUCT-side twin of the query proof below. Its own test rather
        // than a widening of that one, so each has a single reason to fail:
        // this one goes red if `itemIncludingArchived` is dropped from the
        // eager load or resolved per standard, that one if
        // `packagings.tallyItemIncludingArchived` is.
        // ONE shared tray identity across every standard, and that is a
        // measurement decision, not a shortcut. `ambiguityFor()` resolves a
        // name through LineMappingResolver, which memoises per NAME
        // (`itemRows[$name] ??=`) — so a fixture giving each row its own tray
        // name would spend one query per distinct name and this test would be
        // measuring that PRE-EXISTING per-name lookup in `ambiguityRows()`
        // rather than the product eager load it is about. (The sibling test
        // below never meets it because its trays are archived, so
        // `identityFor()` is null and `ambiguityFor(null)` returns before
        // querying.) One name, one memoised lookup, and what is left varying
        // is the thing under test.
        $tray = $this->tallyItem('SHARED-T', 'Shared Tray', 'itm-shared-t');

        $build = function (int $n) use ($tray): void {
            $product = $this->tallyItem("AP-{$n}", "Archived Product {$n}", "itm-ap-{$n}");
            $this->standard("ARCHIVED PRODUCT {$n}", $product)->packagings()->create([
                'mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5, 'nos_per_box' => 490,
                'item_id' => $tray->id,
            ]);
            // The PRODUCT is retired, so every row takes the archived-product
            // resolution path.
            $product->delete();
        };

        $measure = function (int $expectedRows): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $rows = $this->review()['rows'];
            $queries = count(DB::getRawQueryLog());
            DB::disableQueryLog();

            $this->assertCount($expectedRows, $this->rowsOfKind($rows, 'packaging_separate_product'));

            return $queries;
        };

        $build(1);
        // One read before the first measurement, for the one-off permission
        // cache queries — same reason as the test below.
        $this->review();
        $one = $measure(1);

        foreach (range(2, 6) as $n) {
            $build($n);
        }

        // Six times the rows, the same number of queries.
        $this->assertSame($one, $measure(6));
    }

    public function test_resolving_archived_identities_costs_a_constant_number_of_queries(): void
    {
        // The relation is EAGER-LOADED, and this is what says so. Reading it
        // per row instead — a `loadMissing` in the loop, a `withTrashed()`
        // find, a relation dropped from `with()` — is invisible to every
        // assertion above and turns a review of forty into forty queries.
        $build = function (int $n): void {
            $product = $this->tallyItem("P-{$n}", "Product {$n}", "itm-p-{$n}");
            $tray = $this->tallyItem("T-{$n}", "Product {$n} Tray", "itm-t-{$n}");
            $this->standard("PRODUCT {$n}", $product)->packagings()->create([
                'mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5, 'nos_per_box' => 490,
                'item_id' => $tray->id,
            ]);
            // The identity this row names is retired — so every one of these
            // rows takes the archived-resolution path.
            $tray->delete();
        };

        $measure = function (int $expectedRows): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $rows = $this->review()['rows'];
            $queries = count(DB::getRawQueryLog());
            DB::disableQueryLog();

            $this->assertCount($expectedRows, $this->rowsOfKind($rows, 'packaging_separate_product'));

            return $queries;
        };

        $build(1);
        // One read before the first measurement: the very first request of a
        // test pays one-off permission-cache queries that have nothing to do
        // with the row count, and counting them into the baseline would make
        // the comparison below noise rather than evidence.
        $this->review();
        $one = $measure(1);

        foreach (range(2, 6) as $n) {
            $build($n);
        }

        // Six times the rows, the same number of queries.
        $this->assertSame($one, $measure(6));
    }

    public function test_the_read_needs_production_view(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_active' => true]));

        $this->getJson('/api/v1/production/configuration/review')->assertForbidden();
    }
}
