<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Enums\ItemIdentityWarning;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\ItemIdentityService;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ONE TEST PER WARNING CLASS, and every one of them asserts BOTH halves:
 * the row that trips it, and a near-miss row in the same database that does
 * not. A detector with no non-detection case is indistinguishable from a
 * detector that returns everything.
 *
 * ALL DATA HERE IS SYNTHETIC. No real customer, vendor or product name, and
 * no rate anywhere — AGENTS.md, and FC-06 for the rates.
 *
 * ENGINE NOTE. `duplicate_name` is whatever LineMappingResolver calls
 * ambiguous, and that rests on SQL equality: MySQL's collation folds case,
 * SQLite's does not. So the duplicate cases below use BYTE-IDENTICAL names,
 * which every engine agrees about, and the case/spacing/punctuation
 * variation lives in the `possible_duplicate_master` cases, where the fold
 * is done in PHP and is the same on both.
 */
class ItemIdentityWarningsTest extends TestCase
{
    use RefreshDatabase;

    private function identity(): ItemIdentityService
    {
        // A fresh instance per assertion block: the service memoises its
        // sweep for the lifetime of one instance (one request), so a test
        // that writes and re-reads must ask a new one.
        return app(ItemIdentityService::class);
    }

    private function item(string $sku, array $attributes = []): Item
    {
        return Item::create([
            'sku' => $sku,
            'name' => $attributes['name'] ?? $sku,
            'uom' => 'Nos.',
            ...$attributes,
        ]);
    }

    /** @return list<string> */
    private function warningKeys(Item $item): array
    {
        return array_column($this->identity()->warningsFor($item->fresh()), 'class');
    }

    private function assertWarns(Item $item, ItemIdentityWarning $warning): void
    {
        $this->assertContains(
            $warning->value,
            $this->warningKeys($item),
            "Expected item {$item->sku} to trip {$warning->value}.",
        );
    }

    private function assertDoesNotWarn(Item $item, ItemIdentityWarning $warning): void
    {
        $this->assertNotContains(
            $warning->value,
            $this->warningKeys($item),
            "Expected item {$item->sku} NOT to trip {$warning->value}.",
        );
    }

    // ---- 1. missing_tally_mapping -------------------------------------------

    public function test_missing_tally_mapping_finds_an_active_item_with_no_guid_and_spares_the_three_that_are_fine(): void
    {
        $orphan = $this->item('SYN-ORPHAN', ['name' => 'Synthetic Bottle 500']);

        $linked = $this->item('SYN-LINKED', ['name' => 'Synthetic Bottle 250', 'tally_stock_item_guid' => 'guid-syn-250']);
        $retired = $this->item('SYN-RETIRED', ['name' => 'Synthetic Bottle 100', 'is_active' => false]);
        // A fixture's missing GUID is intentional, not a gap in the masters.
        $fixture = $this->item(Item::LOCAL_FIXTURE_SKU_PREFIX.'SYN-1', ['name' => 'Synthetic Rehearsal Bottle']);

        $this->assertWarns($orphan, ItemIdentityWarning::MissingTallyMapping);
        $this->assertDoesNotWarn($linked, ItemIdentityWarning::MissingTallyMapping);
        $this->assertDoesNotWarn($retired, ItemIdentityWarning::MissingTallyMapping);
        $this->assertDoesNotWarn($fixture, ItemIdentityWarning::MissingTallyMapping);
    }

    // ---- 2. duplicate_name ---------------------------------------------------

    public function test_duplicate_name_finds_both_masters_sharing_a_name_and_leaves_a_uniquely_named_one_alone(): void
    {
        $first = $this->item('SYN-DUP-A', ['name' => 'Synthetic Tray 500']);
        $second = $this->item('SYN-DUP-B', ['name' => 'Synthetic Tray 500']);
        $unique = $this->item('SYN-SOLO', ['name' => 'Synthetic Tray 250']);

        $this->assertWarns($first, ItemIdentityWarning::DuplicateName);
        $this->assertWarns($second, ItemIdentityWarning::DuplicateName);
        $this->assertDoesNotWarn($unique, ItemIdentityWarning::DuplicateName);
    }

    public function test_a_soft_deleted_master_is_not_a_duplicate_of_the_one_that_survived_it(): void
    {
        $kept = $this->item('SYN-KEPT', ['name' => 'Synthetic Cap 28mm']);
        $archived = $this->item('SYN-GONE', ['name' => 'Synthetic Cap 28mm']);
        $archived->delete();

        $this->assertDoesNotWarn($kept, ItemIdentityWarning::DuplicateName);
    }

    // ---- 3. possible_duplicate_master ---------------------------------------

    public function test_possible_duplicate_master_folds_case_spacing_and_punctuation_but_not_a_genuinely_different_name(): void
    {
        $a = $this->item('SYN-FOLD-A', ['name' => 'Synthetic Bottle 500 ML']);
        $b = $this->item('SYN-FOLD-B', ['name' => 'synthetic-bottle,  500 ml.']);
        $different = $this->item('SYN-FOLD-C', ['name' => 'Synthetic Bottle 750 ML']);

        $this->assertWarns($a, ItemIdentityWarning::PossibleDuplicateMaster);
        $this->assertWarns($b, ItemIdentityWarning::PossibleDuplicateMaster);
        $this->assertDoesNotWarn($different, ItemIdentityWarning::PossibleDuplicateMaster);
    }

    /**
     * THE MISSING SPACE — this catalogue's own spelling defect, and the case
     * the fold above cannot be trusted on unless it is pinned separately.
     *
     * The two names here differ by NOTHING BUT AN ABSENT SPACE, so every
     * other case in this file would still pass if the fold merely replaced
     * punctuation with a space (which is what it did until 27-Aug-2026 —
     * '100ml' and '100 ml' were two keys). `lib/itemLabel.ts` documents that
     * this factory spells one thing '100ml' and '100 Ml' and strips all
     * whitespace for exactly that reason; the 26-Aug stock-master XML
     * carries pack-variant names of the same shape.
     */
    public function test_two_names_differing_only_by_an_absent_space_are_reported_as_possible_duplicates(): void
    {
        $tight = $this->item('SYN-SPACE-A', ['name' => 'Synthetic Bottle 100ml']);
        $spaced = $this->item('SYN-SPACE-B', ['name' => 'Synthetic Bottle 100 Ml']);

        $this->assertWarns($tight, ItemIdentityWarning::PossibleDuplicateMaster);
        $this->assertWarns($spaced, ItemIdentityWarning::PossibleDuplicateMaster);

        // And it is a SPELLING warning, not an exact-duplicate one: the two
        // rows do not share a `name`, so no voucher is torn between them.
        $this->assertDoesNotWarn($tight, ItemIdentityWarning::DuplicateName);
    }

    public function test_an_exact_duplicate_is_reported_once_as_a_duplicate_name_and_not_again_as_a_possible_one(): void
    {
        $first = $this->item('SYN-EXACT-A', ['name' => 'Synthetic Pouch 840']);
        $second = $this->item('SYN-EXACT-B', ['name' => 'Synthetic Pouch 840']);

        $this->assertWarns($first, ItemIdentityWarning::DuplicateName);
        $this->assertDoesNotWarn($first, ItemIdentityWarning::PossibleDuplicateMaster);
        $this->assertDoesNotWarn($second, ItemIdentityWarning::PossibleDuplicateMaster);
    }

    /**
     * The precedent this warning cites differs by WORD ORDER, and the fold
     * is documented as not reaching that. Pinned so the limit is a decision
     * somebody can revisit on purpose rather than a surprise in the field.
     */
    public function test_the_fold_deliberately_does_not_reorder_words_so_a_transposed_pair_is_not_reported(): void
    {
        $a = $this->item('SYN-ORDER-A', ['name' => 'Synthetic IFF Tray']);
        $b = $this->item('SYN-ORDER-B', ['name' => 'Synthetic Tray IFF']);

        $this->assertDoesNotWarn($a, ItemIdentityWarning::PossibleDuplicateMaster);
        $this->assertDoesNotWarn($b, ItemIdentityWarning::PossibleDuplicateMaster);
    }

    // ---- 4. outbound_ambiguity ----------------------------------------------

    public function test_outbound_ambiguity_needs_a_shared_name_with_a_tally_linked_member(): void
    {
        $linked = $this->item('SYN-AMB-A', ['name' => 'Synthetic Jar 200', 'tally_stock_item_guid' => 'guid-syn-jar']);
        $unlinked = $this->item('SYN-AMB-B', ['name' => 'Synthetic Jar 200']);

        // A shared name where NOBODY is Tally-linked: still a duplicate, but
        // no voucher can be torn between them, so not this class.
        $bothLocal1 = $this->item('SYN-AMB-C', ['name' => 'Synthetic Jar 300']);
        $bothLocal2 = $this->item('SYN-AMB-D', ['name' => 'Synthetic Jar 300']);

        $this->assertWarns($linked, ItemIdentityWarning::OutboundAmbiguity);
        $this->assertWarns($unlinked, ItemIdentityWarning::OutboundAmbiguity);

        $this->assertWarns($bothLocal1, ItemIdentityWarning::DuplicateName);
        $this->assertDoesNotWarn($bothLocal1, ItemIdentityWarning::OutboundAmbiguity);
        $this->assertDoesNotWarn($bothLocal2, ItemIdentityWarning::OutboundAmbiguity);
    }

    public function test_a_uniquely_named_tally_linked_item_is_never_outbound_ambiguous(): void
    {
        $clean = $this->item('SYN-CLEAN', ['name' => 'Synthetic Jar 400', 'tally_stock_item_guid' => 'guid-syn-400']);

        $this->assertDoesNotWarn($clean, ItemIdentityWarning::OutboundAmbiguity);
    }

    // ---- 5. unclassified -----------------------------------------------------

    public function test_unclassified_finds_a_null_category_and_not_one_recorded_as_other(): void
    {
        $unsaid = $this->item('SYN-NULLCAT', ['name' => 'Synthetic Unsaid']);
        // NULL is "nobody has said yet"; Other is a real answer. The
        // difference is the whole point of the column.
        $other = $this->item('SYN-OTHER', ['name' => 'Synthetic Spare', 'category' => ItemCategory::Other->value]);
        $wip = $this->item('SYN-WIP', ['name' => 'Synthetic Half-Made', 'category' => ItemCategory::WorkInProgress->value]);

        $this->assertWarns($unsaid, ItemIdentityWarning::Unclassified);
        $this->assertDoesNotWarn($other, ItemIdentityWarning::Unclassified);
        $this->assertDoesNotWarn($wip, ItemIdentityWarning::Unclassified);
    }

    public function test_the_unclassified_note_names_the_open_question_rather_than_proposing_an_answer(): void
    {
        $unsaid = $this->item('SYN-Q60', ['name' => 'Synthetic Unsaid Two']);

        $note = collect($this->identity()->warningsFor($unsaid))
            ->firstWhere('class', ItemIdentityWarning::Unclassified->value)['note'];

        $this->assertStringContainsString('Q60', $note);
    }

    // ---- 6. variant_uom_conflict --------------------------------------------

    public function test_variant_uom_conflict_fires_on_a_group_with_two_units_and_not_on_one_that_agrees(): void
    {
        $base = $this->item('SYN-BASE-1', ['name' => 'Synthetic Bottle Base A', 'uom' => 'Nos.']);
        $variant = $this->item('SYN-VAR-1', [
            'name' => 'Synthetic Bottle Base A Pouch',
            'uom' => 'Kgs.',
            'variant_of_item_id' => $base->id,
            'variant_label' => '840/box pouch',
        ]);

        $agreeingBase = $this->item('SYN-BASE-2', ['name' => 'Synthetic Bottle Base B', 'uom' => 'Nos.']);
        $agreeingVariant = $this->item('SYN-VAR-2', [
            'name' => 'Synthetic Bottle Base B Tray',
            'uom' => 'Nos.',
            'variant_of_item_id' => $agreeingBase->id,
            'variant_label' => '490/box tray',
        ]);

        $lonely = $this->item('SYN-LONE', ['name' => 'Synthetic Bottle Lonely', 'uom' => 'Kgs.']);

        $this->assertWarns($base, ItemIdentityWarning::VariantUomConflict);
        $this->assertWarns($variant, ItemIdentityWarning::VariantUomConflict);

        $this->assertDoesNotWarn($agreeingBase, ItemIdentityWarning::VariantUomConflict);
        $this->assertDoesNotWarn($agreeingVariant, ItemIdentityWarning::VariantUomConflict);
        $this->assertDoesNotWarn($lonely, ItemIdentityWarning::VariantUomConflict);
    }

    // ---- 7. fg_purchase_conflict --------------------------------------------

    public function test_fg_purchase_conflict_needs_both_halves_the_category_and_the_purchase_line(): void
    {
        $vendor = Vendor::create(['code' => 'SYN-V1', 'name' => 'Synthetic Supplier One']);
        $order = PurchaseOrder::create(['vendor_id' => $vendor->id, 'order_date' => '2026-08-20', 'status' => 'draft']);

        $boughtFinishedGood = $this->item('SYN-FG-BOUGHT', [
            'name' => 'Synthetic Bought Bottle',
            'category' => ItemCategory::FinishedGood->value,
        ]);
        $boughtRawMaterial = $this->item('SYN-RM-BOUGHT', [
            'name' => 'Synthetic Bought Resin',
            'category' => ItemCategory::RawMaterial->value,
            'uom' => 'Kgs.',
        ]);
        $unboughtFinishedGood = $this->item('SYN-FG-MADE', [
            'name' => 'Synthetic Made Bottle',
            'category' => ItemCategory::FinishedGood->value,
        ]);

        // `unit_price` is NOT NULL in the schema, so a value must be given —
        // it is a zero placeholder, deliberately NOT a purchase rate. No real
        // rate belongs in a test, a fixture or a doc (FC-06). This warning
        // reads the LINE'S EXISTENCE and never its money.
        $order->lines()->create(['item_id' => $boughtFinishedGood->id, 'quantity' => '10', 'unit_price' => '0']);
        $order->lines()->create(['item_id' => $boughtRawMaterial->id, 'quantity' => '25', 'unit_price' => '0']);

        $this->assertWarns($boughtFinishedGood, ItemIdentityWarning::FgPurchaseConflict);
        $this->assertDoesNotWarn($boughtRawMaterial, ItemIdentityWarning::FgPurchaseConflict);
        $this->assertDoesNotWarn($unboughtFinishedGood, ItemIdentityWarning::FgPurchaseConflict);
    }

    // ---- 8. inactive_referenced ---------------------------------------------

    public function test_inactive_referenced_fires_only_for_an_inactive_item_on_a_live_order(): void
    {
        $customer = Customer::create(['code' => 'SYN-C1', 'name' => 'Synthetic Buyer One']);

        $live = SalesOrder::create([
            'customer_id' => $customer->id,
            'order_date' => '2026-08-20',
            'status' => SalesOrderStatus::Confirmed,
        ]);
        $finished = SalesOrder::create([
            'customer_id' => $customer->id,
            'order_date' => '2026-08-01',
            'status' => SalesOrderStatus::Completed,
        ]);
        $abandoned = SalesOrder::create([
            'customer_id' => $customer->id,
            'order_date' => '2026-08-02',
            'status' => SalesOrderStatus::Cancelled,
        ]);

        $retiredButOrdered = $this->item('SYN-RET-LIVE', ['name' => 'Synthetic Retired Live', 'is_active' => false]);
        $retiredAndDone = $this->item('SYN-RET-DONE', ['name' => 'Synthetic Retired Done', 'is_active' => false]);
        $retiredAndCancelled = $this->item('SYN-RET-CXL', ['name' => 'Synthetic Retired Cancelled', 'is_active' => false]);
        $activeAndOrdered = $this->item('SYN-ACT-LIVE', ['name' => 'Synthetic Active Live']);

        $live->lines()->create(['item_id' => $retiredButOrdered->id, 'quantity' => '5', 'unit_price' => '1.00']);
        $live->lines()->create(['item_id' => $activeAndOrdered->id, 'quantity' => '5', 'unit_price' => '1.00']);
        $finished->lines()->create(['item_id' => $retiredAndDone->id, 'quantity' => '5', 'unit_price' => '1.00']);
        $abandoned->lines()->create(['item_id' => $retiredAndCancelled->id, 'quantity' => '5', 'unit_price' => '1.00']);

        $this->assertWarns($retiredButOrdered, ItemIdentityWarning::InactiveReferenced);
        $this->assertDoesNotWarn($retiredAndDone, ItemIdentityWarning::InactiveReferenced);
        $this->assertDoesNotWarn($retiredAndCancelled, ItemIdentityWarning::InactiveReferenced);
        $this->assertDoesNotWarn($activeAndOrdered, ItemIdentityWarning::InactiveReferenced);
    }

    // ---- the summary ---------------------------------------------------------

    public function test_health_reports_every_class_in_a_stable_order_even_the_empty_ones(): void
    {
        $this->item('SYN-H1', ['name' => 'Synthetic Health One', 'category' => ItemCategory::RawMaterial->value, 'tally_stock_item_guid' => 'guid-h1', 'uom' => 'Kgs.']);
        $this->item('SYN-H2', ['name' => 'Synthetic Health Two']);

        $health = $this->identity()->health();

        $this->assertSame(ItemIdentityWarning::keys(), array_column($health['warnings'], 'class'));
        $this->assertSame(2, $health['items']);
        // Only the second item is unclassified and unlinked.
        $this->assertSame(1, $health['items_with_any_warning']);

        $counts = array_column($health['warnings'], 'count', 'class');
        $this->assertSame(1, $counts[ItemIdentityWarning::Unclassified->value]);
        $this->assertSame(1, $counts[ItemIdentityWarning::MissingTallyMapping->value]);
        $this->assertSame(0, $counts[ItemIdentityWarning::DuplicateName->value]);
        $this->assertSame(0, $counts[ItemIdentityWarning::InactiveReferenced->value]);
    }

    public function test_a_clean_catalogue_produces_no_warnings_at_all(): void
    {
        $clean = $this->item('SYN-PERFECT', [
            'name' => 'Synthetic Perfect Bottle',
            'category' => ItemCategory::FinishedGood->value,
            'tally_stock_item_guid' => 'guid-perfect',
        ]);

        $this->assertSame([], $this->identity()->warningsFor($clean));
        $this->assertSame(0, $this->identity()->health()['items_with_any_warning']);
    }
}
