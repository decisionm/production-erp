<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Services\ProductVariantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GET production/products/{item}/variants — the variant tree of one product
 * (item → standards → packagings), each packaging carrying its own Tally
 * identity and a configuration_status the screen REPEATS rather than
 * re-derives (Phase 5, P5-02 / P5-06). The same status rides the Start
 * Batch preview's variants, additively, so the Shift Floor and the
 * standards workspace can never disagree about what is missing.
 */
class ProductVariantsTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Item $trayIdentity;

    private ProductionStandard $standard;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        $user->givePermissionTo('production.view');
        Sanctum::actingAs($user);

        $this->bottle = Item::create([
            'sku' => 'BTL-200RA', 'name' => 'B.200 Ml Round Pet Bottle Amber 18gms - 520 Nos',
            'uom' => 'NOS', 'is_active' => true, 'tally_stock_item_guid' => 'itm-520',
        ]);
        $this->trayIdentity = Item::create([
            'sku' => 'BTL-200RA-T', 'name' => 'B.200 Ml Round Pet Bottle Amber 18gms',
            'uom' => 'NOS', 'is_active' => true, 'tally_stock_item_guid' => 'itm-490',
        ]);
        $this->standard = ProductionStandard::create([
            'source_product_name' => '200ML RA', 'item_id' => $this->bottle->id,
            'cavities' => 8, 'unit_weight_grams' => 18, 'cycle_time' => 12, 'status' => 'approved',
        ]);
    }

    private function variants(?int $itemId = null): array
    {
        return $this->getJson('/api/v1/production/products/'.($itemId ?? $this->bottle->id).'/variants')
            ->assertOk()
            ->json('data');
    }

    public function test_the_tree_carries_the_item_its_standards_and_each_packaging_with_its_tally_identity(): void
    {
        $tray = $this->standard->packagings()->create([
            'mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5, 'nos_per_box' => 490,
            'item_id' => $this->trayIdentity->id, 'is_default' => true,
        ]);
        $pouch = $this->standard->packagings()->create([
            'mode' => 'pouch', 'nos_per_pouch' => 130, 'pouches_per_box' => 4, 'nos_per_box' => 520,
        ]);

        $data = $this->variants();

        $this->assertSame([
            'id' => $this->bottle->id, 'sku' => 'BTL-200RA',
            'name' => 'B.200 Ml Round Pet Bottle Amber 18gms - 520 Nos', 'guid' => 'itm-520',
            'sku_provisional' => false,
        ], $data['item']);

        $this->assertCount(1, $data['standards']);
        $standard = $data['standards'][0];
        $this->assertSame($this->standard->id, $standard['id']);
        $this->assertSame(8, $standard['cavities']);
        $this->assertSame('approved', $standard['status']);
        $this->assertSame(['state' => 'complete', 'missing' => []], $standard['configuration_status']);

        $packagings = collect($standard['packagings'])->keyBy('id');
        $this->assertCount(2, $packagings);

        // Own identity: sku · name · guid, verbatim from the item row.
        $this->assertSame([
            'id' => $this->trayIdentity->id, 'sku' => 'BTL-200RA-T',
            'name' => 'B.200 Ml Round Pet Bottle Amber 18gms', 'guid' => 'itm-490',
        ], $packagings[$tray->id]['tally_item']);
        $this->assertSame('tray', $packagings[$tray->id]['mode']);
        $this->assertSame(490, $packagings[$tray->id]['nos_per_box']);
        $this->assertTrue($packagings[$tray->id]['is_default']);
        $this->assertSame('complete', $packagings[$tray->id]['state']);
        $this->assertSame([], $packagings[$tray->id]['missing']);
        $this->assertNull($packagings[$tray->id]['ambiguity']);

        // No identity of its own: null (the product's item is the fallback,
        // DEC-20260810-003) — and the fallback HAS a Tally identity, so
        // nothing is missing.
        $this->assertNull($packagings[$pouch->id]['tally_item']);
        $this->assertSame('complete', $packagings[$pouch->id]['state']);
        $this->assertSame([], $packagings[$pouch->id]['missing']);

        $this->assertSame(['complete' => true, 'missing' => []], $data['configuration_status']);
    }

    public function test_a_half_stated_row_is_incomplete_and_names_counts(): void
    {
        // The live 423 shape: pouch of 120, boxes unstated.
        $row = $this->standard->packagings()->create(['mode' => 'pouch', 'nos_per_pouch' => 120]);

        $data = $this->variants();
        $packaging = $data['standards'][0]['packagings'][0];

        $this->assertSame($row->id, $packaging['id']);
        $this->assertFalse($packaging['is_complete']);
        $this->assertSame('incomplete', $packaging['state']);
        $this->assertSame(['counts'], $packaging['missing']);
        // The word climbs the tree: the standard and the product both say it.
        $this->assertSame(['state' => 'incomplete', 'missing' => ['counts']], $data['standards'][0]['configuration_status']);
        $this->assertSame(['complete' => false, 'missing' => ['counts']], $data['configuration_status']);
    }

    public function test_an_identity_without_a_tally_guid_is_missing_tally_identity(): void
    {
        $tallyLess = Item::create(['sku' => 'BTL-X', 'name' => 'Not In Tally Yet', 'uom' => 'NOS', 'is_active' => true]);
        $this->standard->packagings()->create([
            'mode' => 'direct_box', 'nos_per_box' => 300, 'item_id' => $tallyLess->id,
        ]);

        $packaging = $this->variants()['standards'][0]['packagings'][0];

        // The identity is SHOWN (id · sku · name · guid null) and the gap named.
        $this->assertSame($tallyLess->id, $packaging['tally_item']['id']);
        $this->assertNull($packaging['tally_item']['guid']);
        $this->assertSame('incomplete', $packaging['state']);
        $this->assertSame(['tally_identity'], $packaging['missing']);
    }

    public function test_a_soft_deleted_identity_is_missing_tally_identity_not_a_crash(): void
    {
        // The packaging still points at the item's id, but the item has been
        // retired: the relation resolves to nothing, and the row says so.
        $retired = $this->standard->packagings()->create([
            'mode' => 'direct_box', 'nos_per_box' => 300, 'item_id' => $this->trayIdentity->id,
        ]);
        $this->trayIdentity->delete();

        $packaging = $this->variants()['standards'][0]['packagings'][0];

        $this->assertSame($retired->id, $packaging['id']);
        $this->assertNull($packaging['tally_item']);
        $this->assertSame('incomplete', $packaging['state']);
        $this->assertSame(['tally_identity'], $packaging['missing']);
    }

    public function test_a_local_fixture_product_is_missing_tally_identity_on_every_inheriting_packaging(): void
    {
        $fixture = Item::create([
            'sku' => 'LOCAL-500ML', 'name' => '500ML (LOCAL FIXTURE)', 'uom' => 'Nos',
            'is_active' => true, 'is_local_fixture' => true,
        ]);
        $standard = ProductionStandard::create([
            'source_product_name' => '500ML', 'item_id' => $fixture->id,
            'cavities' => 4, 'unit_weight_grams' => 30, 'cycle_time' => 15, 'status' => 'approved',
        ]);
        $standard->packagings()->create(['mode' => 'direct_box', 'nos_per_box' => 200]);

        $data = $this->variants($fixture->id);

        $this->assertNull($data['item']['guid']);
        $this->assertSame(['tally_identity'], $data['standards'][0]['packagings'][0]['missing']);
        $this->assertSame(['complete' => false, 'missing' => ['tally_identity']], $data['configuration_status']);
    }

    public function test_a_shared_tally_name_is_reported_as_ambiguity_with_the_count(): void
    {
        // Two ERP rows, one name — LineMappingResolver's `ambiguous` state:
        // Tally would match one of them by name and this ERP cannot say which.
        Item::create(['sku' => 'BTL-200RA-T2', 'name' => 'B.200 Ml Round Pet Bottle Amber 18gms', 'uom' => 'NOS', 'tally_stock_item_guid' => 'itm-490-dup']);
        $this->standard->packagings()->create([
            'mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5, 'nos_per_box' => 490,
            'item_id' => $this->trayIdentity->id,
        ]);

        $packaging = $this->variants()['standards'][0]['packagings'][0];

        $this->assertSame(['shared_name_count' => 2], $packaging['ambiguity']);
        // Advisory, not a gap: the identity IS set and carries a GUID; the
        // review surface lists it for a person to settle.
        $this->assertSame('complete', $packaging['state']);
        $this->assertSame([], $packaging['missing']);
    }

    public function test_a_product_with_no_standard_says_so(): void
    {
        $lonely = Item::create(['sku' => 'BTL-L', 'name' => 'Lonely Bottle', 'uom' => 'NOS', 'tally_stock_item_guid' => 'itm-l']);

        $data = $this->variants($lonely->id);

        $this->assertSame([], $data['standards']);
        $this->assertSame(['complete' => false, 'missing' => ['standard']], $data['configuration_status']);
    }

    public function test_a_standard_with_no_packaging_names_the_gap_and_the_run_figures_follow_the_gate_precedence(): void
    {
        // No packagings, and no cycle time on the standard OR the item.
        $this->standard->update(['cycle_time' => null]);

        $data = $this->variants();

        $this->assertSame(
            ['state' => 'incomplete', 'missing' => ['cycle_time', 'packaging']],
            $data['standards'][0]['configuration_status'],
        );

        // The item master supplying the figure closes it — the same
        // standard-outranks-item precedence the readiness gate applies.
        $this->bottle->update(['standard_cycle_time' => 12]);
        $data = $this->variants();
        $this->assertSame(['packaging'], $data['standards'][0]['configuration_status']['missing']);
    }

    public function test_the_missing_vocabulary_is_stable_and_ordered(): void
    {
        // The words the frontend repeats (P5-06). A rename here is a
        // contract change for two screens.
        $this->assertSame(
            ['standard', 'cavities', 'unit_weight', 'cycle_time', 'packaging', 'counts', 'tally_identity'],
            ProductVariantService::MISSING_VOCABULARY,
        );
    }

    public function test_the_batch_preview_carries_the_same_status_on_every_variant_and_packaging(): void
    {
        $tray = $this->standard->packagings()->create([
            'mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5, 'nos_per_box' => 490,
            'item_id' => $this->trayIdentity->id,
        ]);
        $half = $this->standard->packagings()->create(['mode' => 'pouch', 'nos_per_pouch' => 120]);

        $data = $this->getJson("/api/v1/production/shift-production-entries/preview?item_id={$this->bottle->id}")
            ->assertOk()
            ->json('data');

        $variant = $data['variants'][0];
        $this->assertSame(['state' => 'incomplete', 'missing' => ['counts']], $variant['configuration_status']);

        $packagings = collect($variant['packagings'])->keyBy('id');
        $this->assertSame(
            ['state' => 'complete', 'missing' => [], 'ambiguity' => null],
            $packagings[$tray->id]['configuration_status'],
        );
        $this->assertSame(
            ['state' => 'incomplete', 'missing' => ['counts'], 'ambiguity' => null],
            $packagings[$half->id]['configuration_status'],
        );
        // The preview's tally_item grew sku and guid, additively; id and
        // name are exactly what they were.
        $this->assertSame([
            'id' => $this->trayIdentity->id, 'sku' => 'BTL-200RA-T',
            'name' => 'B.200 Ml Round Pet Bottle Amber 18gms', 'guid' => 'itm-490',
        ], $packagings[$tray->id]['tally_item']);
        // And the runnable flag the picker disables on is untouched.
        $this->assertTrue($packagings[$tray->id]['is_complete']);
        $this->assertFalse($packagings[$half->id]['is_complete']);
    }

    public function test_the_read_needs_production_view_and_a_missing_item_is_404(): void
    {
        $this->getJson('/api/v1/production/products/999999/variants')->assertNotFound();

        $stranger = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($stranger);
        $this->getJson("/api/v1/production/products/{$this->bottle->id}/variants")->assertForbidden();
    }
}
