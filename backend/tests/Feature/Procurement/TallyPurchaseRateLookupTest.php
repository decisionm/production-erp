<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\TallySync\Models\Ledger;
use App\Modules\TallySync\Models\TallyPurchaseRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * WHAT THIS VENDOR LAST CHARGED FOR THIS ITEM — and when the form may act on
 * it by itself.
 *
 * THE UNIT RULE IS THE POINT OF THIS SUITE. Tally quotes a rate as
 * `674.000/Kgs.` — a number AND the basis it is per. Q40 records 28 of 382
 * purchase-order lines carrying two units, trays and covers bought by weight
 * and counted in pieces. A bare number prefilled onto a line whose unit is the
 * other one silently restates the price of a real order, and nothing on the
 * screen would show it. So a rate whose unit disagrees is SHOWN and refuses to
 * prefill, with the reason said out loud.
 *
 * Every party name, item and figure below is invented. Purchase rates and
 * supplier identity are Owner/Accounts (FC-06) and do not belong in a fixture.
 */
class TallyPurchaseRateLookupTest extends TestCase
{
    use RefreshDatabase;

    private const PARTY = 'SYNTHETIC SUPPLIES';

    private function actAsAccounts(): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach (['finance.view', 'finance.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        Sanctum::actingAs($user);

        return $user;
    }

    private function vendor(bool $linked = true): Vendor
    {
        $vendor = Vendor::create([
            'code' => 'V-0001',
            'name' => 'Synthetic Supplies',
            'tally_ledger_name' => $linked ? self::PARTY : null,
            'is_active' => true,
        ]);

        if ($linked) {
            Ledger::create([
                'tally_guid' => 'led-synthetic',
                'name' => self::PARTY,
                'tally_group_name' => 'Sundry Creditors',
                'tally_synced_at' => Carbon::parse('2026-08-30 09:00:00'),
            ]);
            $vendor->forceFill(['tally_ledger_guid' => 'led-synthetic'])->save();
        }

        return $vendor->refresh();
    }

    private function item(string $uom = 'Kgs.', ?string $guid = 'stk-synthetic'): Item
    {
        return Item::create([
            'sku' => 'SYN-001',
            'name' => 'Masterbatch - Test',
            'uom' => $uom,
            'tally_stock_item_guid' => $guid,
        ]);
    }

    private function rate(string $type, string $date, array $overrides = []): TallyPurchaseRate
    {
        return TallyPurchaseRate::create([
            'voucher_guid' => 'vch-'.$type.'-'.$date,
            'line_index' => 0,
            'voucher_type' => $type,
            'voucher_number' => '77',
            'voucher_reference' => 'REF-77',
            'voucher_date' => $date,
            'party_ledger_name' => self::PARTY,
            'party_gstin' => '33AAAAA0000A1ZA',
            'stock_item_name' => 'Masterbatch - Test',
            'tally_stock_item_guid' => 'stk-synthetic',
            'rate_value' => 674,
            'rate_unit' => 'Kgs.',
            'quantity' => 48,
            'quantity_unit' => 'Kgs.',
            'amount' => 32352,
            'cgst_rate' => 9,
            'sgst_rate' => 9,
            'igst_rate' => 18,
            'hsn_code' => '32041790',
            'purchase_ledger_name' => 'Interstate Purchase Taxable',
            'tally_company' => 'SYNTHETIC POLYMERS PVT LTD',
            'tally_synced_at' => Carbon::parse('2026-08-30 09:00:00'),
            ...$overrides,
        ]);
    }

    private function lookup(Vendor $vendor, Item $item): array
    {
        return $this->getJson(sprintf(
            '/api/v1/procurement/tally/vendor-item-rate?vendor_id=%d&item_id=%d',
            $vendor->id,
            $item->id,
        ))->assertOk()->json('data');
    }

    // ── The answer ───────────────────────────────────────────────────────

    public function test_both_kinds_are_returned_with_their_voucher_date_reference_uom_and_gst(): void
    {
        $this->actAsAccounts();
        $vendor = $this->vendor();
        $item = $this->item();

        $this->rate(TallyPurchaseRate::TYPE_PURCHASE_ORDER, '2026-04-01', ['rate_value' => 650]);
        $this->rate(TallyPurchaseRate::TYPE_PURCHASE_INVOICE, '2026-07-01', ['rate_value' => 674]);

        $data = $this->lookup($vendor, $item);

        $this->assertSame('650.000000', $data['purchase_order']['rate_value']);
        $this->assertSame('2026-04-01', $data['purchase_order']['voucher_date']);
        $this->assertSame('674.000000', $data['purchase_invoice']['rate_value']);
        $this->assertSame('REF-77', $data['purchase_invoice']['voucher_reference']);
        $this->assertSame('Kgs.', $data['purchase_invoice']['rate_unit']);
        $this->assertSame(self::PARTY, $data['purchase_invoice']['party_ledger_name']);
        $this->assertSame('9.0000', $data['purchase_invoice']['gst']['cgst_rate']);
        $this->assertSame('18.0000', $data['purchase_invoice']['gst']['igst_rate']);
        $this->assertSame('32041790', $data['purchase_invoice']['gst']['hsn_code']);
        // The provenance the owner asked for, on every imported value.
        $this->assertSame('tally', $data['purchase_invoice']['source']);
        $this->assertSame('2026-08-30T09:00:00+00:00', $data['last_synced_at']);
    }

    public function test_the_latest_voucher_leads_the_suggestion_and_names_which_kind_it_is(): void
    {
        $this->actAsAccounts();
        $vendor = $this->vendor();
        $item = $this->item();

        $this->rate(TallyPurchaseRate::TYPE_PURCHASE_ORDER, '2026-08-01', ['rate_value' => 700]);
        $this->rate(TallyPurchaseRate::TYPE_PURCHASE_INVOICE, '2026-07-01', ['rate_value' => 674]);

        $data = $this->lookup($vendor, $item);

        // The ORDER is newer here, so it leads — an agreed rate can outrank a
        // billed one; what decides is the date, and the answer says which.
        $this->assertSame('700.000000', $data['suggestion']['rate_value']);
        $this->assertSame(TallyPurchaseRate::TYPE_PURCHASE_ORDER, $data['suggestion']['voucher_type']);
    }

    public function test_on_the_same_date_the_invoice_leads_because_it_is_what_was_paid(): void
    {
        $this->actAsAccounts();
        $vendor = $this->vendor();
        $item = $this->item();

        $this->rate(TallyPurchaseRate::TYPE_PURCHASE_ORDER, '2026-07-01', ['rate_value' => 700]);
        $this->rate(TallyPurchaseRate::TYPE_PURCHASE_INVOICE, '2026-07-01', ['rate_value' => 674]);

        $this->assertSame(
            TallyPurchaseRate::TYPE_PURCHASE_INVOICE,
            $this->lookup($vendor, $item)['suggestion']['voucher_type'],
        );
    }

    public function test_only_the_newest_of_each_kind_is_offered(): void
    {
        $this->actAsAccounts();
        $vendor = $this->vendor();
        $item = $this->item();

        $this->rate(TallyPurchaseRate::TYPE_PURCHASE_INVOICE, '2026-05-01', ['rate_value' => 600]);
        $this->rate(TallyPurchaseRate::TYPE_PURCHASE_INVOICE, '2026-07-01', ['rate_value' => 674]);

        $this->assertSame('674.000000', $this->lookup($vendor, $item)['purchase_invoice']['rate_value']);
    }

    // ── The unit rule ────────────────────────────────────────────────────

    public function test_a_rate_quoted_in_the_items_own_unit_may_prefill(): void
    {
        $this->actAsAccounts();
        $vendor = $this->vendor();
        $item = $this->item('Kgs.');

        $this->rate(TallyPurchaseRate::TYPE_PURCHASE_INVOICE, '2026-07-01', ['rate_unit' => 'Kgs.']);

        $suggestion = $this->lookup($vendor, $item)['suggestion'];

        $this->assertTrue($suggestion['unit_matches']);
        $this->assertTrue($suggestion['may_prefill']);
        $this->assertNull($suggestion['prefill_blocked_reason']);
    }

    public function test_tallys_two_spellings_of_one_unit_are_the_same_basis(): void
    {
        $this->actAsAccounts();
        $vendor = $this->vendor();
        $item = $this->item('Kgs');

        $this->rate(TallyPurchaseRate::TYPE_PURCHASE_INVOICE, '2026-07-01', ['rate_unit' => 'Kgs.']);

        $this->assertTrue($this->lookup($vendor, $item)['suggestion']['may_prefill']);
    }

    public function test_a_rate_quoted_per_a_different_unit_is_shown_and_refuses_to_prefill(): void
    {
        $this->actAsAccounts();
        $vendor = $this->vendor();
        // The Q40 case: bought by weight, held as a count.
        $item = $this->item('Nos.');

        $this->rate(TallyPurchaseRate::TYPE_PURCHASE_INVOICE, '2026-07-01', ['rate_unit' => 'Kgs.']);

        $suggestion = $this->lookup($vendor, $item)['suggestion'];

        // Still shown — the figure is real and a person may act on it.
        $this->assertSame('674.000000', $suggestion['rate_value']);
        $this->assertSame('Kgs.', $suggestion['rate_unit']);
        $this->assertSame('Nos.', $suggestion['item_uom']);
        // But nothing moves into an editable price field by itself.
        $this->assertFalse($suggestion['unit_matches']);
        $this->assertFalse($suggestion['may_prefill']);
        $this->assertStringContainsString('Kgs.', $suggestion['prefill_blocked_reason']);
        $this->assertStringContainsString('Nos.', $suggestion['prefill_blocked_reason']);
    }

    public function test_a_rate_with_no_unit_recorded_does_not_prefill_either(): void
    {
        $this->actAsAccounts();
        $vendor = $this->vendor();
        $item = $this->item('Kgs.');

        $this->rate(TallyPurchaseRate::TYPE_PURCHASE_INVOICE, '2026-07-01', ['rate_unit' => null]);

        $suggestion = $this->lookup($vendor, $item)['suggestion'];

        // "No basis recorded" is not the same fact as "the same basis".
        $this->assertFalse($suggestion['may_prefill']);
        $this->assertStringContainsString('no unit', $suggestion['prefill_blocked_reason']);
    }

    public function test_two_items_with_no_unit_at_all_are_not_treated_as_agreeing(): void
    {
        $this->actAsAccounts();
        $vendor = $this->vendor();
        $item = $this->item(uom: '');

        $this->rate(TallyPurchaseRate::TYPE_PURCHASE_INVOICE, '2026-07-01', ['rate_unit' => null]);

        $this->assertFalse($this->lookup($vendor, $item)['suggestion']['may_prefill']);
    }

    // ── When there is no answer ──────────────────────────────────────────

    public function test_a_vendor_with_no_tally_identity_is_told_so_rather_than_shown_an_empty_result(): void
    {
        $this->actAsAccounts();
        $vendor = $this->vendor(linked: false);
        $item = $this->item();

        $this->rate(TallyPurchaseRate::TYPE_PURCHASE_INVOICE, '2026-07-01');

        $data = $this->lookup($vendor, $item);

        $this->assertNull($data['suggestion']);
        $this->assertStringContainsString('not linked to a Tally ledger', $data['unavailable_reason']);
    }

    public function test_a_vendor_and_item_tally_has_no_purchase_for_says_exactly_that(): void
    {
        $this->actAsAccounts();
        $vendor = $this->vendor();
        $item = $this->item();

        $data = $this->lookup($vendor, $item);

        $this->assertNull($data['purchase_order']);
        $this->assertNull($data['purchase_invoice']);
        $this->assertStringContainsString('No Tally purchase order or purchase invoice', $data['unavailable_reason']);
    }

    public function test_another_partys_rate_for_the_same_item_is_never_offered(): void
    {
        $this->actAsAccounts();
        $vendor = $this->vendor();
        $item = $this->item();

        $this->rate(TallyPurchaseRate::TYPE_PURCHASE_INVOICE, '2026-07-01', ['party_ledger_name' => 'SOMEONE ELSE']);

        $this->assertNull($this->lookup($vendor, $item)['purchase_invoice']);
    }

    public function test_an_item_with_no_tally_identity_matches_on_the_stock_item_name(): void
    {
        $this->actAsAccounts();
        $vendor = $this->vendor();
        // An ERP-only item carries no Tally GUID; the name is all there is.
        $item = $this->item(guid: null);

        $this->rate(TallyPurchaseRate::TYPE_PURCHASE_INVOICE, '2026-07-01');

        $this->assertSame('674.000000', $this->lookup($vendor, $item)['purchase_invoice']['rate_value']);
    }

    // ── FC-06 ────────────────────────────────────────────────────────────

    public function test_a_floor_login_is_refused_the_whole_answer(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        $user->givePermissionTo('production.view');
        Sanctum::actingAs($user);

        $vendor = $this->vendor();
        $item = $this->item();

        // Refused, not thinned: a rate lookup with the rates removed is not a
        // lesser view of this, it is nothing.
        $this->getJson(sprintf('/api/v1/procurement/tally/vendor-item-rate?vendor_id=%d&item_id=%d', $vendor->id, $item->id))
            ->assertForbidden();
    }
}
