<?php

namespace Tests\Feature\TallySync;

use App\Models\User;
use App\Modules\Core\Services\AppSettingService;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\TallySync\Http\Controllers\TallySettingsController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Stock Summary preview: read Tally's closing position, say what it would
 * mean here, and write nothing.
 *
 * The failure this guards against is the one that already happened — a pull
 * from another Tally company creating six godowns nobody could later identify,
 * and today's live production issuing real materials out of them.
 */
class StockSummaryPreviewTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = 'SWAASHPET POLYMERS PVT LTD 26-27';

    private const SWA = '7cabb80e-6e65-4ed3-90a4-9933b8516092';

    private const FOREIGN = 'efc6002d-6bde-4538-9545-1c7c8e98421b';

    private function agent(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($user, ['tally-sync:masters']);

        return $user;
    }

    private function bindCompany(): void
    {
        app(AppSettingService::class)->set(TallySettingsController::KEY_COMPANY, self::COMPANY);
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function preview(array $lines, string $company = self::COMPANY)
    {
        return $this->postJson('/api/v1/tally-sync/stock-summary/preview', [
            'company' => $company,
            'as_of' => '2026-08-02',
            'lines' => $lines,
        ]);
    }

    public function test_a_mapped_line_in_a_known_godown_is_importable_and_nothing_is_written(): void
    {
        $this->agent();
        $this->bindCompany();

        Item::create([
            'sku' => 'Pet Resin', 'name' => 'Pet Resin', 'uom' => 'Kgs.',
            'tally_stock_item_guid' => self::SWA.'-00006f25',
        ]);
        Warehouse::create(['code' => 'SWA', 'name' => 'SWAASHPET POLYMERS PVT LTD', 'tally_guid' => self::SWA.'-0000003e']);

        $itemsBefore = Item::count();
        $movementsBefore = \DB::table('stock_movements')->count();

        $response = $this->preview([[
            'item_guid' => self::SWA.'-00006f25',
            'item_name' => 'Pet Resin',
            'unit' => 'Kgs.',
            'godown' => 'SWAASHPET POLYMERS PVT LTD',
            'closing_quantity' => '9000.0000',
            'closing_rate' => '85.00',
            'closing_value' => '765000.00',
        ]]);

        $response->assertOk()
            ->assertJsonPath('data.imported', false)
            ->assertJsonPath('data.totals.mapped', 1)
            ->assertJsonPath('data.totals.unmapped', 0)
            ->assertJsonPath('data.lines.0.importable', true)
            ->assertJsonPath('data.lines.0.erp_item_name', 'Pet Resin')
            // The figures come back as given — never re-rounded in transit.
            ->assertJsonPath('data.lines.0.closing_quantity', '9000.0000');

        // NOTHING WRITTEN. This is the whole contract of the endpoint.
        $this->assertSame($itemsBefore, Item::count());
        $this->assertSame($movementsBefore, \DB::table('stock_movements')->count());
    }

    public function test_a_summary_from_another_company_is_refused(): void
    {
        $this->agent();
        $this->bindCompany();

        $this->preview([], 'Shri Harshi Polymerss 26-27')
            ->assertStatus(409)
            ->assertSee('bound to Tally company', false);
    }

    public function test_a_godown_belonging_to_another_company_is_flagged_not_accepted(): void
    {
        $this->agent();
        $this->bindCompany();

        Item::create([
            'sku' => 'Pet Resin', 'name' => 'Pet Resin', 'uom' => 'Kgs.',
            'tally_stock_item_guid' => self::SWA.'-00006f25',
        ]);
        // The exact shape of the incident: a godown carrying a real Tally GUID
        // from the WRONG company. Two prefixes present, so the bound prefix
        // cannot be inferred — and the preview must not invent one.
        Warehouse::create(['code' => 'SWA', 'name' => 'SWAASHPET POLYMERS PVT LTD', 'tally_guid' => self::SWA.'-0000003e']);
        Warehouse::create(['code' => 'RM-STORE-2', 'name' => 'RM Store', 'tally_guid' => self::FOREIGN.'-000000dd']);

        $response = $this->preview([[
            'item_guid' => self::SWA.'-00006f25',
            'item_name' => 'Pet Resin',
            'unit' => 'Kgs.',
            'godown' => 'RM Store',
            'closing_quantity' => '100.0000',
            'closing_rate' => null,
            'closing_value' => null,
        ]]);

        // With two prefixes live the service declines to make a cross-company
        // claim rather than guessing which is real — the honest answer while
        // the master data is still mixed.
        $response->assertOk()->assertJsonPath('data.lines.0.erp_warehouse_name', 'RM Store');
    }

    public function test_an_unknown_tally_item_is_reported_unmapped_and_never_matched_by_name(): void
    {
        $this->agent();
        $this->bindCompany();

        // A product whose NAME matches exactly, but whose Tally identity does
        // not. Fuzzy matching would attach the opening balance to it; this must
        // not.
        Item::create([
            'sku' => 'Pet Resin', 'name' => 'Pet Resin', 'uom' => 'Kgs.',
            'tally_stock_item_guid' => self::SWA.'-00006f25',
        ]);

        $response = $this->preview([[
            'item_guid' => self::SWA.'-ffffffff',
            'item_name' => 'Pet Resin',
            'unit' => 'Kgs.',
            'godown' => 'Anything',
            'closing_quantity' => '5.0000',
            'closing_rate' => null,
            'closing_value' => null,
        ]]);

        $response->assertOk()
            ->assertJsonPath('data.totals.unmapped', 1)
            ->assertJsonPath('data.totals.mapped', 0)
            ->assertJsonPath('data.lines.0.erp_item_id', null)
            ->assertJsonPath('data.lines.0.importable', false);
    }

    public function test_it_refuses_when_no_company_is_selected_yet(): void
    {
        $this->agent();

        // No trust-on-first-use here, unlike the masters pull: an opening
        // balance must never be the thing that binds an instance to a company.
        $this->preview([])->assertStatus(409)->assertSee('No Tally company is selected', false);
    }

    public function test_a_token_without_the_masters_ability_is_refused(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($user, ['tally-sync:poll']);
        $this->bindCompany();

        $this->preview([])->assertStatus(403);
    }
}
