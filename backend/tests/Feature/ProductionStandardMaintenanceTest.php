<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Services\ProductionStandardImportService;
use App\Modules\Production\Services\ProductionStandardResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Finishing the import's job from the standards page.
 *
 * Two things the workbook cannot do for itself, and both used to require a
 * developer and a re-import:
 *
 *  - 62 standards carry no Tally item, because the factory's mould names look
 *    nothing like the catalogue's SKU names. The page now suggests candidates
 *    and lets a person attach one.
 *  - a product the workbook never carried had no way into the app at all.
 *
 * The rule both share, and the reason this file exists: a standard that gains
 * its item KEEPS ITS ROW. The importer already protects that (a variant
 * imported unlinked adopts its row when the link arrives); attaching by hand
 * has to protect exactly the same thing, including across the next re-import.
 */
class ProductionStandardMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        $user = User::factory()->create(['is_active' => true, 'name' => 'Vincent']);
        foreach (['production.manage', 'production.view'] as $p) {
            Permission::findOrCreate($p, 'web');
        }
        $user->givePermissionTo(['production.manage', 'production.view']);
        Sanctum::actingAs($user);

        return $user;
    }

    /** Real rows from the factory master. 90ML RIB matches no catalogue name. */
    private function factoryRows(): array
    {
        return [
            ['sl_no' => 20, 'product' => '90ML RIB', 'cavities' => 7, 'unit_weight_grams' => 8.5, 'cycle_time' => 12,
                'nos_per_tray' => 208, 'tray_nos_per_box' => 1040],
            ['sl_no' => 4, 'product' => '60ML ROUND', 'cavities' => 5, 'unit_weight_grams' => 10, 'cycle_time' => 10.8,
                'nos_per_pouch' => 245, 'pouch_nos_per_box' => 1225],
        ];
    }

    private function import(): void
    {
        app(ProductionStandardImportService::class)->import($this->factoryRows(), false, null);
    }

    private function item(string $name, bool $active = true): Item
    {
        return Item::create([
            'sku' => $name,
            'name' => $name,
            'uom' => 'NOS',
            'is_active' => $active,
            'tally_stock_item_guid' => 'guid-'.md5($name),
        ]);
    }

    private function unattached(string $product = '90ML RIB'): ProductionStandard
    {
        return ProductionStandard::where('source_product_name', $product)->whereNull('item_id')->firstOrFail();
    }

    // ---------------------------------------------------------------- candidates

    public function test_candidates_are_scored_and_carry_the_id_needed_to_attach_one(): void
    {
        $this->actor();
        $this->import();
        $rib = $this->item('B.90ml Rib Pet Bottle Clear-8.5gms');
        $this->item('A.15ml Round Pet Bottle Amber-5gms');

        $body = $this->getJson("/api/v1/production/standards/{$this->unattached()->id}/item-candidates")
            ->assertOk()
            ->json('data');

        $this->assertSame('90ML RIB', $body['source_product_name']);
        $this->assertNotEmpty($body['candidates']);

        $ids = array_column($body['candidates'], 'id');
        $this->assertContains($rib->id, $ids, 'The item that shares the mould name must be offered.');

        foreach ($body['candidates'] as $candidate) {
            $this->assertIsInt($candidate['id']);
            $this->assertIsInt($candidate['score']);
            $this->assertArrayHasKey('same_size', $candidate);
            $this->assertArrayHasKey('attached_to_same_product', $candidate);
        }

        // Ranked, not returned in catalogue order.
        $scores = array_column($body['candidates'], 'score');
        $sorted = $scores;
        rsort($sorted);
        $this->assertSame($sorted[0], max($scores));
    }

    public function test_an_item_already_carrying_a_sibling_variant_is_offered_but_flagged(): void
    {
        $this->actor();
        $this->import();
        $rib = $this->item('B.90ml Rib Pet Bottle Clear-8.5gms');

        // A second variant of the SAME mould, already attached — legitimate:
        // one mould covers every colour, and sibling variants share items.
        ProductionStandard::create([
            'item_id' => $rib->id, 'source_product_name' => '90ML RIB', 'cavities' => 8,
            'unit_weight_grams' => 8.5, 'cycle_time' => 12, 'status' => 'draft',
        ]);

        $candidates = collect($this->getJson("/api/v1/production/standards/{$this->unattached()->id}/item-candidates")
            ->assertOk()->json('data.candidates'));

        $this->assertTrue(
            (bool) $candidates->firstWhere('id', $rib->id)['attached_to_same_product'],
            'The page has to be able to say this item is already spoken for by a sibling variant.',
        );
    }

    public function test_the_exact_name_match_is_offered_even_though_the_diagnostic_skips_it(): void
    {
        // matchReport() reports what did NOT match, so a catalogue that names
        // the item exactly as the sheet does yields an empty report — correct
        // for a diagnostic, useless for a picker.
        $this->actor();
        $this->import();
        $exact = $this->item('90ML RIB');

        $candidates = $this->getJson("/api/v1/production/standards/{$this->unattached()->id}/item-candidates")
            ->assertOk()->json('data.candidates');

        $this->assertSame($exact->id, $candidates[0]['id'], 'The obvious answer has to be offered first.');
        $this->assertSame(100, $candidates[0]['score']);

        // Offered once, not twice — it is both the exact match and the top of
        // the similarity ranking.
        $this->assertSame([$exact->id], array_values(array_unique(array_column($candidates, 'id'))));
    }

    public function test_candidates_need_only_view_permission(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        $user->givePermissionTo('production.view');
        Sanctum::actingAs($user);
        $this->import();

        $this->getJson("/api/v1/production/standards/{$this->unattached()->id}/item-candidates")->assertOk();
        $this->postJson("/api/v1/production/standards/{$this->unattached()->id}/attach-item", ['item_id' => 1])
            ->assertForbidden();
    }

    // ------------------------------------------------------------------- attach

    public function test_attaching_an_item_adopts_the_existing_row_rather_than_adding_a_sibling(): void
    {
        $actor = $this->actor();
        $this->import();
        $rowId = $this->unattached()->id;
        $item = $this->item('B.90ml Rib Pet Bottle Clear-8.5gms');

        $this->postJson("/api/v1/production/standards/{$rowId}/attach-item", ['item_id' => $item->id])
            ->assertOk()
            ->assertJsonPath('data.item_id', $item->id);

        $rows = ProductionStandard::where('source_product_name', '90ML RIB')->get();
        $this->assertCount(1, $rows, 'Attaching must not spawn a second row — that is the duplicate the page exists to remove.');
        $this->assertSame($rowId, $rows->first()->id);

        // Who and when, recorded — an attachment is an inference somebody took
        // responsibility for.
        $this->assertSame($actor->id, $rows->first()->item_attached_by);
        $this->assertNotNull($rows->first()->item_attached_at);
        $this->assertStringContainsString('Vincent', (string) $rows->first()->notes);

        // The figures are untouched: the four identity columns are what makes
        // this row THIS standard, and attaching an item must not restate them.
        $this->assertSame(7, $rows->first()->cavities);
        $this->assertSame('8.5000', (string) $rows->first()->unit_weight_grams);
        $this->assertSame('12.00', (string) $rows->first()->cycle_time);
        $this->assertCount(1, $rows->first()->packagings);
    }

    public function test_a_hand_attached_item_survives_the_next_reimport(): void
    {
        $this->actor();
        $this->import();
        $rowId = $this->unattached()->id;

        // A catalogue name the importer cannot match on its own — which is the
        // entire reason a person had to attach it.
        $item = $this->item('B.90ml Rib Pet Bottle Clear-8.5gms');
        $this->postJson("/api/v1/production/standards/{$rowId}/attach-item", ['item_id' => $item->id])->assertOk();

        $this->import();

        $rows = ProductionStandard::where('source_product_name', '90ML RIB')->get();
        $this->assertCount(1, $rows, 'A re-import must not grow an unlinked sibling beside the row somebody attached.');
        $this->assertSame($rowId, $rows->first()->id);
        $this->assertSame($item->id, $rows->first()->item_id);
    }

    public function test_attaching_refuses_when_an_identical_variant_of_that_item_already_exists(): void
    {
        $this->actor();
        $this->import();
        $item = $this->item('B.90ml Rib Pet Bottle Clear-8.5gms');

        $clash = ProductionStandard::create([
            'item_id' => $item->id, 'source_product_name' => '90ML RIB', 'cavities' => 7,
            'unit_weight_grams' => 8.5, 'cycle_time' => 12, 'status' => 'draft',
        ]);

        $response = $this->postJson("/api/v1/production/standards/{$this->unattached()->id}/attach-item", ['item_id' => $item->id])
            ->assertStatus(422);

        // Named, not a raw constraint violation nobody can act on.
        $this->assertStringContainsString("#{$clash->id}", $response->json('errors.item_id.0'));
        $this->assertNull($this->unattached()->item_id);
    }

    public function test_a_soft_deleted_clash_still_blocks_because_it_still_holds_the_slot(): void
    {
        $this->actor();
        $this->import();
        $item = $this->item('B.90ml Rib Pet Bottle Clear-8.5gms');

        ProductionStandard::create([
            'item_id' => $item->id, 'source_product_name' => '90ML RIB', 'cavities' => 7,
            'unit_weight_grams' => 8.5, 'cycle_time' => 12, 'status' => 'draft',
        ])->delete();

        $this->postJson("/api/v1/production/standards/{$this->unattached()->id}/attach-item", ['item_id' => $item->id])
            ->assertStatus(422);
    }

    public function test_attaching_refuses_an_inactive_or_deleted_item(): void
    {
        $this->actor();
        $this->import();

        $inactive = $this->item('B.90ml Rib Inactive', active: false);
        $this->postJson("/api/v1/production/standards/{$this->unattached()->id}/attach-item", ['item_id' => $inactive->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('item_id');

        $deleted = $this->item('B.90ml Rib Retired');
        $deleted->delete();
        $this->postJson("/api/v1/production/standards/{$this->unattached()->id}/attach-item", ['item_id' => $deleted->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('item_id');

        $this->postJson("/api/v1/production/standards/{$this->unattached()->id}/attach-item", ['item_id' => 999999])
            ->assertStatus(422);

        $this->assertNull($this->unattached()->item_id);
    }

    public function test_attaching_refuses_a_standard_that_already_has_an_item(): void
    {
        $this->actor();
        $this->import();
        $first = $this->item('B.90ml Rib Pet Bottle Clear-8.5gms');
        $second = $this->item('B.90ml Rib Pet Bottle Amber-8.5gms');

        $standard = $this->unattached();
        $this->postJson("/api/v1/production/standards/{$standard->id}/attach-item", ['item_id' => $first->id])->assertOk();

        // Re-pointing a live standard changes whose figures every run of that
        // product uses. Refused rather than silently applied.
        $this->postJson("/api/v1/production/standards/{$standard->id}/attach-item", ['item_id' => $second->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('item_id');

        $this->assertSame($first->id, $standard->refresh()->item_id);
    }

    public function test_an_item_the_suggestion_list_filters_out_can_still_be_attached(): void
    {
        // The ranking drops catalogue names containing packaging words so the
        // report shows bottles, not master boxes. A real bottle whose Tally
        // name happens to contain one of them must not become unattachable.
        $this->actor();
        $this->import();
        $awkward = $this->item('B.90ml Rib Pet Bottle With Cap Clear-8.5gms');

        $standard = $this->unattached();

        // It is genuinely absent from the suggestions...
        $suggested = array_column(
            $this->getJson("/api/v1/production/standards/{$standard->id}/item-candidates")->assertOk()->json('data.candidates'),
            'id',
        );
        $this->assertNotContains($awkward->id, $suggested);

        // ...and attaching it works anyway. The list is a shortcut, not the vocabulary.
        $this->postJson("/api/v1/production/standards/{$standard->id}/attach-item", ['item_id' => $awkward->id])
            ->assertOk();

        $this->assertSame($awkward->id, $standard->refresh()->item_id);
    }

    // ------------------------------------------------------------------- create

    public function test_a_product_the_workbook_never_carried_can_be_added_and_is_immediately_runnable(): void
    {
        $this->actor();
        $item = $this->item('C.400ml Hexagon Pet Bottle Clear-22gms');

        $created = $this->postJson('/api/v1/production/standards', [
            'source_product_name' => '400ML HEXAGON',
            'cavities' => 4,
            'unit_weight_grams' => 22,
            'cycle_time' => 15.25,
            'item_id' => $item->id,
            'carton_spec' => 'HM 30 X 49',
            'nos_per_tray' => 100,
            'tray_nos_per_box' => 500,
        ])->assertCreated()->json('data');

        $standard = ProductionStandard::findOrFail($created['id']);
        $this->assertSame('400ML HEXAGON', $standard->source_product_name);
        $this->assertSame(4, $standard->cavities);
        $this->assertSame('22.0000', (string) $standard->unit_weight_grams);
        $this->assertSame('15.25', (string) $standard->cycle_time);
        $this->assertSame('HM 30 X 49', $standard->carton_spec);
        $this->assertSame(ProductionStandard::SOURCE_MANUAL, $standard->source);
        $this->assertSame('draft', $standard->status, 'Nothing typed into a form has been reviewed.');
        $this->assertStringContainsString('Vincent', (string) $standard->notes);

        // The importer's own packaging derivation: 500 per box ÷ 100 per tray
        // = 5 trays, and one option is the default so nobody is asked a
        // question with one answer.
        $packaging = $standard->packagings()->sole();
        $this->assertSame(ProductionStandardPackaging::MODE_TRAY, $packaging->mode);
        $this->assertSame(100, $packaging->nos_per_tray);
        $this->assertSame(5, $packaging->trays_per_box);
        $this->assertSame(500, $packaging->nos_per_box);
        $this->assertTrue($packaging->is_default);

        // It appears on the standards page. Under ALL, explicitly: that page
        // is now a workspace whose default view is production-READY, and this
        // product is not — the item this test attaches carries no colour, so
        // the workspace states exactly that and sends the reader to the item.
        // Being listed as incomplete WITH ITS REASON is the honest answer;
        // being listed with no qualification is what used to happen and is
        // how a product reached Start Batch before anyone noticed.
        $listed = collect($this->getJson('/api/v1/production/standards?view=all&per_page=100')->assertOk()->json('data'));
        $this->assertContains($standard->id, $listed->pluck('id')->all());

        $row = $listed->firstWhere('id', $standard->id);
        $this->assertSame(
            [['number' => 1, 'key' => 'colour', 'label' => 'Colour', 'sentence' => $row['gaps'][0]['sentence'], 'fix_target' => 'item_colour']],
            $row['gaps'],
            'One gap, numbered, with the screen that closes it.',
        );
        $this->assertContains($standard->id, collect(
            $this->getJson('/api/v1/production/standards?view=incomplete&per_page=100')->assertOk()->json('data'),
        )->pluck('id')->all());

        // ...in the Start Batch picker's coverage projection...
        $coverage = collect($this->getJson('/api/v1/production/standards/coverage')->assertOk()->json('data'));
        $this->assertTrue($coverage->contains(
            fn (array $row) => $row['item_id'] === $item->id && $row['source_product_name'] === '400ML HEXAGON',
        ));

        // ...and it resolves for that item, which is what Start Batch prefills from.
        $resolver = app(ProductionStandardResolver::class);
        $this->assertSame($standard->id, $resolver->variantsFor($item->id)->pluck('id')->first());
        $this->assertSame($standard->id, $resolver->resolve($item->id)?->id);
    }

    public function test_a_standard_can_be_added_unattached_and_attached_afterwards(): void
    {
        $this->actor();

        $id = $this->postJson('/api/v1/production/standards', [
            'source_product_name' => '400ML HEXAGON',
            'cavities' => 4, 'unit_weight_grams' => 22, 'cycle_time' => 15.25,
            'nos_per_pouch' => 50, 'pouch_nos_per_box' => 500,
        ])->assertCreated()->json('data.id');

        $this->assertNull(ProductionStandard::findOrFail($id)->item_id);

        $item = $this->item('C.400ml Hexagon Pet Bottle Clear-22gms');
        $this->postJson("/api/v1/production/standards/{$id}/attach-item", ['item_id' => $item->id])->assertOk();

        $this->assertSame($item->id, ProductionStandard::findOrFail($id)->item_id);
        $this->assertCount(1, ProductionStandard::where('source_product_name', '400ML HEXAGON')->get());
    }

    public function test_creating_refuses_a_second_copy_of_the_same_variant(): void
    {
        $this->actor();
        $payload = [
            'source_product_name' => '400ML HEXAGON',
            'cavities' => 4, 'unit_weight_grams' => 22, 'cycle_time' => 15.25,
        ];

        $this->postJson('/api/v1/production/standards', $payload)->assertCreated();

        // NULLs are distinct in the unique index, so the database would accept
        // this happily — the refusal has to be ours.
        $this->postJson('/api/v1/production/standards', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('source_product_name');

        $this->assertCount(1, ProductionStandard::where('source_product_name', '400ML HEXAGON')->get());
    }

    public function test_creating_validates_the_figures_every_expected_output_is_derived_from(): void
    {
        $this->actor();

        $this->postJson('/api/v1/production/standards', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['source_product_name', 'cavities', 'unit_weight_grams', 'cycle_time']);

        $this->postJson('/api/v1/production/standards', [
            'source_product_name' => '400ML HEXAGON', 'cavities' => 0,
            'unit_weight_grams' => 0, 'cycle_time' => -1,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cavities', 'unit_weight_grams', 'cycle_time']);

        $this->assertSame(0, ProductionStandard::count());
    }

    public function test_creating_needs_manage_permission(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        $user->givePermissionTo('production.view');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/production/standards', [
            'source_product_name' => '400ML HEXAGON',
            'cavities' => 4, 'unit_weight_grams' => 22, 'cycle_time' => 15.25,
        ])->assertForbidden();

        $this->assertSame(0, ProductionStandard::count());
    }
}
