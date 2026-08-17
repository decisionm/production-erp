<?php

namespace Tests\Feature\TallySync;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\TallySync\Models\Enums\TallyLedgerRole;
use App\Modules\TallySync\Models\TallyLedgerMapping;
use App\Modules\TallySync\Services\LineMappingResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LineMappingResolver — THE resolver of a voucher's names (item, godown,
 * ledger) to their mapping state, shared by the pre-approval preview and
 * the sync page's show endpoint (MASTER-PLAN P3-04). Every state it can
 * answer is pinned here, one fixture per state, and each fixture is the
 * live condition it stands for:
 *
 *   identity   a row carrying a Tally GUID (or a configured role mapping) —
 *              and its note still says "posts IF that master still carries
 *              this name; this ERP cannot know that": a GUID recorded at the
 *              last masters pull is not a reading of Tally now
 *   name_only  a row without a GUID — Tally matches by name; the ERP cannot know
 *   unmapped   no row by that name
 *   fixture    a LOCAL- rehearsal product — never postable
 *   ambiguous  two rows share the name (items.name has no unique index) —
 *              with the count structured as `shared_count`, not only in the note
 *   none       the line carries no name for that dimension
 *
 * Also pinned: it is one `where name = ?` (equality as the database compares
 * it — never a prefix, never a longer name; case and trailing-space folding
 * are the collation's business, not this class's, so neither is asserted
 * here); it never reads Tally; and it costs one query per DISTINCT name —
 * the show endpoint asks it once per line, and a shift voucher names the
 * same resin on most of its lines.
 */
class LineMappingResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): LineMappingResolver
    {
        return app(LineMappingResolver::class);
    }

    // ---- items ---------------------------------------------------------------

    public function test_an_item_carrying_a_tally_guid_resolves_by_identity_and_still_says_the_erp_cannot_know(): void
    {
        $item = Item::create(['sku' => 'RES-1', 'name' => 'PET Resin', 'uom' => 'Kgs', 'tally_stock_item_guid' => 'itm-resin']);

        $state = $this->resolver()->item('PET Resin');

        $this->assertSame(['state', 'item_id', 'tally_stock_item_guid', 'shared_count', 'note'], array_keys($state));
        $this->assertSame('identity', $state['state']);
        $this->assertSame($item->id, $state['item_id']);
        $this->assertSame('itm-resin', $state['tally_stock_item_guid']);
        $this->assertNull($state['shared_count']);
        // Green is the strongest claim the ERP can make — and it is still
        // not "confirmed present in Tally". The note says what the GUID is
        // (a record from the last pull) and what Tally actually matches on.
        $this->assertSame(
            '"PET Resin" is linked to Tally stock item itm-resin, recorded when masters were last pulled; Tally matches '
            .'by name, so this line posts if that master still carries this name — this ERP cannot know that.',
            $state['note'],
        );
    }

    public function test_an_item_without_a_guid_is_name_only_and_says_the_erp_cannot_know(): void
    {
        $item = Item::create(['sku' => 'RES-2', 'name' => 'PET Resin (Virgin Grade)', 'uom' => 'Kgs']);

        $state = $this->resolver()->item('PET Resin (Virgin Grade)');

        $this->assertSame('name_only', $state['state']);
        $this->assertSame($item->id, $state['item_id']);
        $this->assertNull($state['tally_stock_item_guid']);
        $this->assertStringContainsString('without a Tally GUID', $state['note']);
        $this->assertStringContainsString('cannot know', $state['note']);
    }

    public function test_an_unknown_item_name_is_unmapped_and_never_fuzzy(): void
    {
        Item::create(['sku' => 'RES-1', 'name' => 'PET Resin', 'uom' => 'Kgs', 'tally_stock_item_guid' => 'itm-resin']);

        // A prefix and a longer name — neither is the name under ANY
        // collation, so neither resolves. Case ('pet resin') and trailing
        // space ('PET Resin ') are deliberately NOT asserted: MySQL's
        // utf8mb4_unicode_ci equates both and SQLite equates neither, and
        // this class adds nothing to what the database compares.
        foreach (['PET', 'PET Resin (Virgin Grade)'] as $near) {
            $state = $this->resolver()->item($near);
            $this->assertSame('unmapped', $state['state'], "\"{$near}\" must not resolve to \"PET Resin\"");
            $this->assertNull($state['item_id']);
            $this->assertStringContainsString("No item named \"{$near}\"", $state['note']);
        }
    }

    public function test_a_local_fixture_is_fixture_whatever_else_it_carries(): void
    {
        $flagged = Item::create(['sku' => 'BTL-TRAY', 'name' => '500ml Tray Pack (LOCAL FIXTURE)', 'uom' => 'Nos', 'is_local_fixture' => true]);
        $prefixed = Item::create(['sku' => 'LOCAL-OLD', 'name' => 'Old-style Fixture', 'uom' => 'Nos']);
        // The contradictory row — a fixture that somehow carries a GUID —
        // reads as a fixture too: the posting paths refuse it either way,
        // and the state must not read as postable.
        $withGuid = Item::create(['sku' => 'LOCAL-GUID', 'name' => 'Fixture With Guid', 'uom' => 'Nos', 'is_local_fixture' => true, 'tally_stock_item_guid' => 'itm-odd']);

        foreach ([$flagged, $prefixed, $withGuid] as $fixture) {
            $state = $this->resolver()->item($fixture->name);
            $this->assertSame('fixture', $state['state'], $fixture->sku);
            $this->assertSame($fixture->id, $state['item_id']);
            $this->assertStringContainsString('local rehearsal product', $state['note']);
        }
        $this->assertSame('itm-odd', $this->resolver()->item('Fixture With Guid')['tally_stock_item_guid']);
    }

    public function test_two_items_sharing_a_name_are_ambiguous_and_neither_is_picked(): void
    {
        // items.name has no unique index (2026_07_18_155120): two rows can
        // carry the same name — one Tally-known, one not. Tally would match
        // ONE by name; this ERP cannot say which, so it picks neither and
        // reports the counts.
        Item::create(['sku' => 'BTL-A', 'name' => '500ml PET Bottle', 'uom' => 'Nos', 'tally_stock_item_guid' => 'itm-a']);
        Item::create(['sku' => 'BTL-B', 'name' => '500ml PET Bottle', 'uom' => 'Nos']);

        $state = $this->resolver()->item('500ml PET Bottle');

        $this->assertSame('ambiguous', $state['state']);
        $this->assertNull($state['item_id']);
        $this->assertNull($state['tally_stock_item_guid']);
        // The count is structured, not only prose — a reader never has to
        // parse it back out of the note.
        $this->assertSame(2, $state['shared_count']);
        $this->assertStringContainsString('2 items in this ERP share the name "500ml PET Bottle"', $state['note']);
        $this->assertStringContainsString('1 with a Tally GUID', $state['note']);
        $this->assertStringContainsString('cannot say which', $state['note']);

        // The single-row accessor picks nothing; the candidate set — what
        // the preview judges uom and packing kind over — carries both.
        $this->assertNull($this->resolver()->itemRow('500ml PET Bottle'));
        $this->assertSame(['BTL-A', 'BTL-B'], $this->resolver()->itemCandidates('500ml PET Bottle')->pluck('sku')->sort()->values()->all());
        $this->assertCount(0, $this->resolver()->itemCandidates('Nothing By This Name'));
        $this->assertCount(0, $this->resolver()->itemCandidates(null));
    }

    public function test_a_line_with_no_item_name_is_none(): void
    {
        foreach ([null, ''] as $blank) {
            $state = $this->resolver()->item($blank);
            $this->assertSame('none', $state['state']);
            $this->assertNull($state['item_id']);
            $this->assertNull($this->resolver()->itemRow($blank));
        }
    }

    public function test_a_soft_deleted_item_is_not_a_master_and_reads_unmapped(): void
    {
        Item::create(['sku' => 'GONE', 'name' => 'Deleted Item', 'uom' => 'Nos', 'tally_stock_item_guid' => 'itm-gone'])->delete();

        $this->assertSame('unmapped', $this->resolver()->item('Deleted Item')['state']);
    }

    // ---- godowns -------------------------------------------------------------

    public function test_a_warehouse_with_its_own_tally_guid_is_identity_via_self_and_still_says_the_erp_cannot_know(): void
    {
        $godown = Warehouse::create(['code' => 'GDN', 'name' => 'SWAASHPET POLYMERS PVT LTD', 'is_active' => true, 'tally_guid' => 'gd-company']);

        $state = $this->resolver()->godown('SWAASHPET POLYMERS PVT LTD');

        $this->assertSame(['state', 'warehouse_id', 'tally_guid', 'resolved_via', 'shared_count', 'note'], array_keys($state));
        $this->assertSame('identity', $state['state']);
        $this->assertSame($godown->id, $state['warehouse_id']);
        $this->assertSame('gd-company', $state['tally_guid']);
        $this->assertSame('self', $state['resolved_via']);
        $this->assertNull($state['shared_count']);
        $this->assertSame(
            '"SWAASHPET POLYMERS PVT LTD" is linked to Tally godown gd-company, recorded when masters were last pulled; '
            .'Tally matches by name, so this line posts if that godown still carries this name — this ERP cannot know that.',
            $state['note'],
        );
    }

    public function test_the_day_bin_aliasing_to_its_parent_is_identity_via_ancestor(): void
    {
        // The GodownAliasingTest layout: the one real godown, and the
        // internal day bin as its child. The bin's own name resolves —
        // through the SAME TallyGodownResolver the payload builder used —
        // to the parent's GUID, and resolved_via says how.
        $godown = Warehouse::create(['code' => 'GDN', 'name' => 'SWAASHPET POLYMERS PVT LTD', 'is_active' => true, 'tally_guid' => 'gd-company']);
        $bin = Warehouse::create(['code' => 'BIN', 'name' => 'Factory Day Bin', 'is_active' => true, 'parent_id' => $godown->id]);

        $state = $this->resolver()->godown('Factory Day Bin');

        $this->assertSame('identity', $state['state']);
        $this->assertSame($bin->id, $state['warehouse_id'], 'the row is the bin');
        $this->assertSame('gd-company', $state['tally_guid'], 'the GUID is the parent\'s — what Tally will match');
        $this->assertSame('ancestor', $state['resolved_via']);
        $this->assertStringContainsString('under its Tally-known ancestor "SWAASHPET POLYMERS PVT LTD"', $state['note']);
        // And the aliased identity carries the same caveat as a direct one,
        // about the godown Tally will actually be handed.
        $this->assertStringContainsString('"SWAASHPET POLYMERS PVT LTD" is linked to Tally godown gd-company, recorded when masters were last pulled', $state['note']);
        $this->assertStringContainsString('this ERP cannot know that', $state['note']);
    }

    public function test_an_unparented_bin_in_a_one_godown_system_is_identity_via_the_sole_linked_godown(): void
    {
        Warehouse::create(['code' => 'GDN', 'name' => 'SWAASHPET POLYMERS PVT LTD', 'is_active' => true, 'tally_guid' => 'gd-company']);
        $loose = Warehouse::create(['code' => 'LOOSE', 'name' => 'Loose Bin', 'is_active' => true]);

        $state = $this->resolver()->godown('Loose Bin');

        $this->assertSame('identity', $state['state']);
        $this->assertSame($loose->id, $state['warehouse_id']);
        $this->assertSame('gd-company', $state['tally_guid']);
        $this->assertSame('sole_linked', $state['resolved_via']);
        $this->assertStringContainsString('under the sole Tally-linked godown "SWAASHPET POLYMERS PVT LTD"', $state['note']);
        $this->assertStringContainsString('this ERP cannot know that', $state['note']);
    }

    public function test_an_unlinked_unparented_warehouse_in_a_multi_godown_system_is_name_only(): void
    {
        // Two real godowns: an unlinked, unparented warehouse is genuinely
        // ambiguous to the alias rule, which guesses nothing — so the row
        // exists, no GUID stands in for it, and Tally will match by name.
        Warehouse::create(['code' => 'GDN', 'name' => 'Godown One', 'is_active' => true, 'tally_guid' => 'gd-1']);
        Warehouse::create(['code' => 'GDN-2', 'name' => 'Godown Two', 'is_active' => true, 'tally_guid' => 'gd-2']);
        $loose = Warehouse::create(['code' => 'LOOSE', 'name' => 'Loose Bin', 'is_active' => true]);

        $state = $this->resolver()->godown('Loose Bin');

        $this->assertSame('name_only', $state['state']);
        $this->assertSame($loose->id, $state['warehouse_id']);
        $this->assertNull($state['tally_guid']);
        $this->assertNull($state['resolved_via']);
        $this->assertStringContainsString('aliases to no Tally-known godown', $state['note']);
    }

    public function test_an_unknown_godown_name_is_unmapped_and_a_blank_one_is_none(): void
    {
        Warehouse::create(['code' => 'GDN', 'name' => 'RM Store', 'is_active' => true, 'tally_guid' => 'gd-rm']);

        // A name that exists NOWHERE — not a case variant of a real one. The
        // lookup is `where name = ?`, and whether that is case-sensitive is
        // the driver's collation: sqlite compares bytes ('Rm store' misses
        // 'RM Store'); MySQL's utf8mb4_unicode_ci matches it (the live
        // instance). This test is about an unknown name; the case question
        // is a driver fact, not the resolver's contract.
        $unknown = $this->resolver()->godown('Nowhere Bin');
        $this->assertSame('unmapped', $unknown['state']);
        $this->assertNull($unknown['warehouse_id']);
        $this->assertStringContainsString('No warehouse named "Nowhere Bin"', $unknown['note']);

        $this->assertSame('none', $this->resolver()->godown(null)['state']);
        $this->assertSame('none', $this->resolver()->godown('')['state']);
    }

    public function test_two_warehouses_sharing_a_name_are_ambiguous(): void
    {
        Warehouse::create(['code' => 'A', 'name' => 'Store', 'is_active' => true, 'tally_guid' => 'gd-a']);
        Warehouse::create(['code' => 'B', 'name' => 'Store', 'is_active' => true]);

        $state = $this->resolver()->godown('Store');

        $this->assertSame('ambiguous', $state['state']);
        $this->assertNull($state['warehouse_id']);
        $this->assertNull($state['tally_guid']);
        $this->assertSame(2, $state['shared_count']);
        $this->assertStringContainsString('2 warehouses in this ERP share the name "Store"', $state['note']);
    }

    // ---- ledgers -------------------------------------------------------------

    public function test_a_configured_sales_role_is_identity_and_an_unconfigured_one_is_unmapped(): void
    {
        $this->assertSame('unmapped', $this->resolver()->ledgerRole(TallyLedgerRole::Sales)['state']);
        $this->assertStringContainsString('No Tally ledger is mapped to the "Sales" role', $this->resolver()->ledgerRole(TallyLedgerRole::Sales)['note']);

        TallyLedgerMapping::create(['role' => 'sales', 'tally_ledger_name' => 'Sales A/c']);

        $configured = $this->resolver()->ledgerRole(TallyLedgerRole::Sales);
        $this->assertSame('identity', $configured['state']);
        $this->assertStringContainsString('mapped to Tally ledger "Sales A/c"', $configured['note']);
        // A configured role is an identity this ERP holds — and the note
        // still says Tally matches by name and the ERP cannot know.
        $this->assertStringContainsString('The mapping is a name this ERP holds', $configured['note']);
        $this->assertStringContainsString('this ERP cannot know that', $configured['note']);

        // The convenience dispatcher reads a role value as the role.
        $this->assertSame('identity', $this->resolver()->ledger('sales')['state']);
        $this->assertSame('unmapped', $this->resolver()->ledger('purchase')['state']);
    }

    public function test_a_role_whose_queued_name_has_drifted_from_the_mapping_says_so(): void
    {
        TallyLedgerMapping::create(['role' => 'sales', 'tally_ledger_name' => 'Sales A/c']);

        $same = $this->resolver()->ledgerRole(TallyLedgerRole::Sales, 'Sales A/c');
        $this->assertSame('identity', $same['state']);
        $this->assertStringNotContainsString('still names', $same['note']);

        $drifted = $this->resolver()->ledgerRole(TallyLedgerRole::Sales, 'Sales Account (old)');
        $this->assertSame('identity', $drifted['state']);
        $this->assertStringContainsString('still names "Sales Account (old)"', $drifted['note']);
        $this->assertStringContainsString('would carry "Sales A/c"', $drifted['note']);
    }

    public function test_a_party_name_is_name_only_with_the_no_fk_note(): void
    {
        $state = $this->resolver()->ledgerName('Reliance Industries');

        $this->assertSame('name_only', $state['state']);
        $this->assertStringContainsString('"Reliance Industries" is carried as a name only', $state['note']);
        $this->assertStringContainsString('holds no link', $state['note']);
        $this->assertStringContainsString('cannot know', $state['note']);

        // Through the dispatcher a non-role string is a name — and a party
        // that happens to be named like a role is exactly why the surface
        // calls ledgerName() directly.
        $this->assertSame('name_only', $this->resolver()->ledger('Reliance Industries')['state']);
        $this->assertSame('none', $this->resolver()->ledger(null)['state']);
        $this->assertSame('none', $this->resolver()->ledgerName('')['state']);
    }

    // ---- cost ----------------------------------------------------------------

    public function test_a_name_is_looked_up_once_however_often_it_is_asked(): void
    {
        Item::create(['sku' => 'RES-1', 'name' => 'PET Resin', 'uom' => 'Kgs', 'tally_stock_item_guid' => 'itm-resin']);
        $godown = Warehouse::create(['code' => 'GDN', 'name' => 'SWAASHPET POLYMERS PVT LTD', 'is_active' => true, 'tally_guid' => 'gd-company']);
        Warehouse::create(['code' => 'BIN', 'name' => 'Factory Day Bin', 'is_active' => true, 'parent_id' => $godown->id]);
        TallyLedgerMapping::create(['role' => 'sales', 'tally_ledger_name' => 'Sales A/c']);

        $resolver = $this->resolver();

        DB::enableQueryLog();
        DB::flushQueryLog();

        // The same resin, the same two godowns and the same role, asked the
        // way a twelve-line shift voucher asks them.
        for ($i = 0; $i < 12; $i++) {
            $resolver->item('PET Resin');
            $resolver->itemRow('PET Resin');
            $resolver->godown('SWAASHPET POLYMERS PVT LTD');
            $resolver->godown('Factory Day Bin');
            $resolver->ledgerRole(TallyLedgerRole::Sales, 'Sales A/c');
            $resolver->item(null);
            $resolver->godown(null);
        }

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // One for the item name; one per godown name plus the bin's parent
        // walk (TallyGodownResolver lazy-loads the parent once); one for the
        // role. Anything near 12× is a memo that stopped working.
        $this->assertLessThanOrEqual(6, $queries, "{$queries} queries for 4 distinct names — the per-name memo is broken");
        $this->assertGreaterThanOrEqual(4, $queries, 'each distinct name is looked up at least once');

        // And a FRESH resolver looks again — the memo is per instance (one
        // request / one preview), never a cache that could outlive a
        // mapping change.
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->resolver()->item('PET Resin');
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
    }
}
