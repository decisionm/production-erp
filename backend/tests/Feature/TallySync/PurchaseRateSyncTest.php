<?php

namespace Tests\Feature\TallySync;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\TallySync\Models\TallyPurchaseRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * THE AGENT'S PURCHASE-RATE PULL LANDS, AND LANDS ONCE.
 *
 * The inbound half of Procurement's Tally rate lookup: purchase-order and
 * purchase-invoice lines read out of the factory's Day Book, written to one
 * table that nothing posts from.
 *
 * EVERY FIXTURE HERE IS SYNTHETIC. The real 12-Aug exports carry live purchase
 * RATES and party names, and FC-06 puts those with Owner/Accounts — Q38 is
 * open precisely because they may not be committed. The SHAPE is taken from
 * those exports; the numbers and names are invented, exactly as
 * purchaseOrder.test.js does it on the agent side.
 */
class PurchaseRateSyncTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = 'SYNTHETIC POLYMERS PVT LTD';

    private function actAsAgent(array $abilities = ['tally-sync:masters']): void
    {
        Sanctum::actingAs(User::factory()->create(['is_active' => true]), $abilities);
    }

    private function line(array $overrides = []): array
    {
        return [
            'voucher_guid' => 'vch-synthetic-0001',
            'line_index' => 0,
            'voucher_type' => TallyPurchaseRate::TYPE_PURCHASE_ORDER,
            'voucher_number' => '77',
            'voucher_reference' => '77',
            'voucher_date' => '2026-07-01',
            'party_ledger_name' => 'SYNTHETIC SUPPLIES',
            'party_gstin' => '24AADFB7416Q1Z7',
            'stock_item_name' => 'Masterbatch - Test',
            'rate_value' => 674.0,
            'rate_unit' => 'Kgs.',
            'quantity' => 48.0,
            'quantity_unit' => 'Kgs.',
            'amount' => 32352.0,
            'cgst_rate' => 9,
            'sgst_rate' => 9,
            'igst_rate' => 18,
            'hsn_code' => '32041790',
            'purchase_ledger_name' => 'Interstate Purchase Taxable',
            ...$overrides,
        ];
    }

    private function postRates(array $lines, ?string $company = self::COMPANY)
    {
        return $this->postJson('/api/v1/tally-sync/purchase-rates', [
            'company' => $company,
            'lines' => $lines,
        ]);
    }

    public function test_a_pull_records_the_rate_with_the_unit_it_was_quoted_per(): void
    {
        $this->actAsAgent();

        $this->postRates([$this->line()])->assertOk()->assertJsonPath('data.created', 1);

        $rate = TallyPurchaseRate::sole();

        $this->assertSame('674.000000', $rate->rate_value);
        // THE UNIT IS NOT DECORATION. A bare number with no basis is what the
        // lookup refuses to prefill from — see PurchaseRateLookup.
        $this->assertSame('Kgs.', $rate->rate_unit);
        $this->assertSame('2026-07-01', $rate->voucher_date->toDateString());
        $this->assertSame(self::COMPANY, $rate->tally_company);
        $this->assertNotNull($rate->tally_synced_at);
    }

    public function test_the_gst_of_the_voucher_is_kept_per_line_and_no_item_tax_master_is_written(): void
    {
        $this->actAsAgent();

        // The SAME item at two different GST rates on two dates — measured as
        // real in this factory's books (Q39: 9 of 43 items appear under both
        // 5% and 18%). Both must survive; neither may become "the" rate.
        $this->postRates([
            $this->line(['voucher_guid' => 'vch-a', 'voucher_date' => '2026-05-01', 'cgst_rate' => 2.5, 'sgst_rate' => 2.5, 'igst_rate' => 5]),
            $this->line(['voucher_guid' => 'vch-b', 'voucher_date' => '2026-07-01', 'cgst_rate' => 9, 'sgst_rate' => 9, 'igst_rate' => 18]),
        ])->assertOk();

        $this->assertSame(
            ['5.0000', '18.0000'],
            TallyPurchaseRate::orderBy('voucher_date')->pluck('igst_rate')->all(),
        );
    }

    public function test_re_reading_the_same_voucher_updates_it_rather_than_duplicating_it(): void
    {
        $this->actAsAgent();

        $this->postRates([$this->line()])->assertOk();
        $this->postRates([$this->line(['rate_value' => 690.0])])->assertOk()->assertJsonPath('data.updated', 1);

        $this->assertSame(1, TallyPurchaseRate::count());
        $this->assertSame('690.000000', TallyPurchaseRate::sole()->rate_value);
    }

    public function test_a_line_removed_in_tally_stops_being_quotable(): void
    {
        $this->actAsAgent();

        $this->postRates([
            $this->line(['line_index' => 0]),
            $this->line(['line_index' => 1, 'stock_item_name' => 'Second Item']),
        ])->assertOk();

        $this->assertSame(2, TallyPurchaseRate::count());

        // The voucher comes back with one line. The tail is gone from Tally,
        // so it must go from here — a withdrawn rate that went on suggesting
        // itself would be worse than no lookup at all.
        $this->postRates([$this->line(['line_index' => 0])])->assertOk()->assertJsonPath('data.deleted', 1);

        $this->assertSame(['Masterbatch - Test'], TallyPurchaseRate::pluck('stock_item_name')->all());
    }

    public function test_a_line_resolves_to_the_mirrored_item_when_the_name_matches_one(): void
    {
        $this->actAsAgent();

        Item::create([
            'sku' => 'SYN-001',
            'name' => 'Masterbatch - Test',
            'uom' => 'Kgs.',
            'tally_stock_item_guid' => 'stk-synthetic-1',
        ]);

        $this->postRates([$this->line()])->assertOk();

        $this->assertSame('stk-synthetic-1', TallyPurchaseRate::sole()->tally_stock_item_guid);
    }

    public function test_an_item_the_erp_does_not_mirror_is_kept_with_no_link_rather_than_guessed(): void
    {
        $this->actAsAgent();

        $this->postRates([$this->line(['stock_item_name' => 'Something Tally Alone Knows'])])->assertOk();

        $rate = TallyPurchaseRate::sole();
        $this->assertNull($rate->tally_stock_item_guid);
        $this->assertSame('Something Tally Alone Knows', $rate->stock_item_name);
    }

    public function test_a_pull_that_found_nothing_is_recorded_rather_than_rejected(): void
    {
        // A Day Book window holding no purchase voucher is a legitimate state,
        // not an error: the factory may simply have bought nothing. Rejecting
        // it made the agent take a 422 and log the failure on the factory PC,
        // where nobody on this side can see it — leaving the ERP looking
        // exactly like a button nobody had pressed.
        $this->actAsAgent();

        $this->postRates([])->assertOk()->assertJsonPath('data.total', 0);

        $this->assertSame(0, TallyPurchaseRate::count());
    }

    public function test_an_empty_pull_does_not_delete_rates_already_recorded(): void
    {
        // The tail-deletion only ever touches vouchers THIS pull carried, so a
        // pull that carried nothing must withdraw nothing. Otherwise one empty
        // window would silently wipe the lookup.
        $this->actAsAgent();

        $this->postRates([$this->line()])->assertOk();
        $this->postRates([])->assertOk()->assertJsonPath('data.deleted', 0);

        $this->assertSame(1, TallyPurchaseRate::count());
    }

    public function test_an_unknown_voucher_kind_is_refused_rather_than_stored(): void
    {
        $this->actAsAgent();

        $this->postRates([$this->line(['voucher_type' => 'sales_invoice'])])
            ->assertStatus(422);

        $this->assertSame(0, TallyPurchaseRate::count());
    }

    public function test_rates_from_another_tally_company_are_refused(): void
    {
        $this->actAsAgent();

        // Bind the instance the way a masters pull does.
        $this->postJson('/api/v1/tally-sync/masters', ['company' => self::COMPANY, 'ledgers' => []])->assertOk();

        $this->postRates([$this->line()], 'A DIFFERENT COMPANY')->assertStatus(409);

        $this->assertSame(0, TallyPurchaseRate::count());
    }

    public function test_a_token_without_the_masters_ability_may_not_post_rates(): void
    {
        $this->actAsAgent(['tally-sync:poll']);

        $this->postRates([$this->line()])->assertStatus(403);

        $this->assertSame(0, TallyPurchaseRate::count());
    }
}
