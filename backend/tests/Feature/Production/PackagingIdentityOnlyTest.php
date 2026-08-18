<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 5 fix loop, P1-a: an identity-only write never touches a count.
 *
 * The importer deliberately keeps a packaging whose three figures disagree
 * (105/pouch × 5 pouches, sheet says 520/box — flagged "confirm which is
 * correct", ProductionStandardImportService::packagingInconsistency). The
 * review panel's Link used to PUT the row's mode + inner counts + item_id
 * through the full update, and attributes() re-derived nos_per_box on every
 * update — so a link quietly turned the sheet's 520 into 525: a factory
 * count the import refused to pick, picked by code.
 *
 * Two guards now: a PATCH …/identity route that sets item_id (+ provenance)
 * and nothing else, and the full update keeps a stored nos_per_box when the
 * mode's inner counts are unchanged (a genuine count edit still derives).
 *
 * No factory value is decided here: 105/5/520 are the workbook's own
 * disagreeing figures used as a fixture, and the test asserts they SURVIVE.
 */
class PackagingIdentityOnlyTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Item $tallyIdentity;

    private ProductionStandard $standard;

    private ProductionStandardPackaging $inconsistentPouch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bottle = Item::create([
            'sku' => 'BTL-200R', 'name' => 'B.200 Ml Round Pet Bottle',
            'uom' => 'NOS', 'is_active' => true, 'tally_stock_item_guid' => 'itm-200r',
        ]);
        $this->tallyIdentity = Item::create([
            'sku' => 'BTL-200R-520', 'name' => 'B.200 Ml Round Pet Bottle - 520 Nos',
            'uom' => 'NOS', 'is_active' => true, 'tally_stock_item_guid' => 'itm-200r-520',
        ]);
        $this->standard = ProductionStandard::create([
            'source_product_name' => '200ML ROUND', 'item_id' => $this->bottle->id,
            'cavities' => 4, 'unit_weight_grams' => 18, 'cycle_time' => 16.5, 'status' => 'unresolved',
        ]);
        // The importer's own inconsistent row, kept verbatim: 105 × 5 = 525
        // but the sheet says 520.
        $this->inconsistentPouch = $this->standard->packagings()->create([
            'mode' => 'pouch', 'nos_per_pouch' => 105, 'pouches_per_box' => 5, 'nos_per_box' => 520,
        ]);
    }

    private function actingAsProduction(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.manage', 'web');
        $user->givePermissionTo('production.manage');
        Sanctum::actingAs($user);

        return $user;
    }

    private function identityUrl(?ProductionStandard $standard = null, ?ProductionStandardPackaging $packaging = null): string
    {
        $standard ??= $this->standard;
        $packaging ??= $this->inconsistentPouch;

        return "/api/v1/production/standards/{$standard->id}/packagings/{$packaging->id}/identity";
    }

    // ------------------------------------------------- the identity route ---

    public function test_patch_identity_sets_the_item_and_leaves_every_count_byte_for_byte(): void
    {
        $user = $this->actingAsProduction();

        $this->patchJson($this->identityUrl(), ['item_id' => $this->tallyIdentity->id])
            ->assertOk()
            ->assertJsonPath('data.id', $this->inconsistentPouch->id)
            ->assertJsonPath('data.tally_item.id', $this->tallyIdentity->id)
            ->assertJsonPath('data.nos_per_pouch', 105)
            ->assertJsonPath('data.pouches_per_box', 5)
            ->assertJsonPath('data.nos_per_box', 520);

        $row = $this->inconsistentPouch->fresh();
        $this->assertSame($this->tallyIdentity->id, (int) $row->item_id);
        $this->assertSame(520, (int) $row->nos_per_box, 'the sheet\'s 520 must survive an identity-only link');
        $this->assertSame(105, (int) $row->nos_per_pouch);
        $this->assertSame(5, (int) $row->pouches_per_box);
        $this->assertSame('pouch', $row->mode);
        $this->assertSame($user->id, (int) $row->item_set_by);
        $this->assertNotNull($row->item_set_at);
    }

    public function test_patch_identity_can_clear_the_identity_back_to_the_product(): void
    {
        $this->actingAsProduction();
        $this->inconsistentPouch->update(['item_id' => $this->tallyIdentity->id]);

        $this->patchJson($this->identityUrl(), ['item_id' => null])
            ->assertOk()
            ->assertJsonPath('data.tally_item', null)
            ->assertJsonPath('data.uses_product_identity', true)
            ->assertJsonPath('data.nos_per_box', 520);

        $this->assertNull($this->inconsistentPouch->fresh()->item_id);
    }

    public function test_patch_identity_carries_no_count_even_when_the_payload_smuggles_one(): void
    {
        $this->actingAsProduction();

        // A client that sends counts to the identity route is ignored on the
        // counts, not obeyed — the route's contract is identity only.
        $this->patchJson($this->identityUrl(), [
            'item_id' => $this->tallyIdentity->id,
            'nos_per_pouch' => 104, 'pouches_per_box' => 5, 'nos_per_box' => 999, 'mode' => 'tray',
        ])->assertOk();

        $row = $this->inconsistentPouch->fresh();
        $this->assertSame('pouch', $row->mode);
        $this->assertSame(105, (int) $row->nos_per_pouch);
        $this->assertSame(5, (int) $row->pouches_per_box);
        $this->assertSame(520, (int) $row->nos_per_box);
    }

    public function test_the_same_refusals_as_the_full_update_apply_to_the_identity(): void
    {
        $this->actingAsProduction();

        $fixture = Item::create([
            'sku' => 'LOCAL-200R', 'name' => '200ML ROUND (LOCAL FIXTURE)', 'uom' => 'NOS',
            'is_active' => true, 'is_local_fixture' => true,
        ]);
        $inactive = Item::create([
            'sku' => 'BTL-OLD', 'name' => 'Retired Bottle', 'uom' => 'NOS',
            'is_active' => false, 'tally_stock_item_guid' => 'itm-old',
        ]);
        $guidless = Item::create([
            'sku' => 'BTL-LOCAL', 'name' => 'Bottle Tally Has Never Seen', 'uom' => 'NOS', 'is_active' => true,
        ]);
        $retired = Item::create([
            'sku' => 'BTL-GONE', 'name' => 'Deleted Bottle', 'uom' => 'NOS',
            'is_active' => true, 'tally_stock_item_guid' => 'itm-gone',
        ]);
        $retired->delete();

        foreach ([$fixture, $inactive, $guidless, $retired] as $item) {
            $this->patchJson($this->identityUrl(), ['item_id' => $item->id])
                ->assertStatus(422)
                ->assertJsonValidationErrors('item_id');
        }

        // item_id must be present (null is a real answer, absence is not).
        $this->patchJson($this->identityUrl(), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('item_id');

        $row = $this->inconsistentPouch->fresh();
        $this->assertNull($row->item_id);
        $this->assertNull($row->item_set_by);
        $this->assertSame(520, (int) $row->nos_per_box);
    }

    public function test_a_packaging_of_another_standard_is_refused(): void
    {
        $this->actingAsProduction();

        $other = ProductionStandard::create([
            'source_product_name' => 'OTHER', 'cavities' => 4, 'unit_weight_grams' => 10,
            'cycle_time' => 10, 'status' => 'approved',
        ]);

        $this->patchJson($this->identityUrl($other, $this->inconsistentPouch), ['item_id' => $this->tallyIdentity->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('packaging');

        $this->assertNull($this->inconsistentPouch->fresh()->item_id);
    }

    public function test_the_identity_route_needs_production_manage(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('sales.view', 'web');
        $user->givePermissionTo('sales.view');
        Sanctum::actingAs($user);

        $this->patchJson($this->identityUrl(), ['item_id' => $this->tallyIdentity->id])->assertForbidden();

        $this->assertNull($this->inconsistentPouch->fresh()->item_id);
    }

    public function test_provenance_stamps_only_when_the_answer_changes(): void
    {
        $this->actingAsProduction();
        $this->inconsistentPouch->update([
            'item_id' => $this->tallyIdentity->id, 'item_set_by' => null, 'item_set_at' => '2026-08-01 00:00:00',
        ]);

        $this->patchJson($this->identityUrl(), ['item_id' => $this->tallyIdentity->id])->assertOk();

        $row = $this->inconsistentPouch->fresh();
        $this->assertNull($row->item_set_by, 'the same answer again is not a re-confirmation');
        $this->assertStringStartsWith('2026-08-01', (string) $row->item_set_at);
    }

    // -------------------------------- the full update keeps a stored count ---

    public function test_a_full_update_with_unchanged_inner_counts_keeps_the_stored_box_count(): void
    {
        $this->actingAsProduction();

        // The payload the product drawer sends when only the identity (or
        // the default flag) is edited: the mode and the same inner counts.
        $this->putJson("/api/v1/production/standards/{$this->standard->id}/packagings/{$this->inconsistentPouch->id}", [
            'mode' => 'pouch', 'nos_per_pouch' => 105, 'pouches_per_box' => 5,
            'item_id' => $this->tallyIdentity->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.nos_per_box', 520)
            ->assertJsonPath('data.tally_item.id', $this->tallyIdentity->id);

        $this->assertSame(520, (int) $this->inconsistentPouch->fresh()->nos_per_box);
    }

    public function test_a_full_update_that_changes_an_inner_count_still_derives_the_box_count(): void
    {
        $this->actingAsProduction();

        // A changed pieces-per-pouch is a genuine count edit: the box count
        // is derived again, exactly as before.
        $this->putJson("/api/v1/production/standards/{$this->standard->id}/packagings/{$this->inconsistentPouch->id}", [
            'mode' => 'pouch', 'nos_per_pouch' => 100, 'pouches_per_box' => 5,
        ])
            ->assertOk()
            ->assertJsonPath('data.nos_per_box', 500);

        // And so is a changed pouches-per-box.
        $this->putJson("/api/v1/production/standards/{$this->standard->id}/packagings/{$this->inconsistentPouch->id}", [
            'mode' => 'pouch', 'nos_per_pouch' => 100, 'pouches_per_box' => 6,
        ])
            ->assertOk()
            ->assertJsonPath('data.nos_per_box', 600);
    }

    public function test_a_full_update_that_changes_the_mode_derives_from_the_new_counts(): void
    {
        $this->actingAsProduction();

        $this->putJson("/api/v1/production/standards/{$this->standard->id}/packagings/{$this->inconsistentPouch->id}", [
            'mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5,
        ])
            ->assertOk()
            ->assertJsonPath('data.mode', 'tray')
            ->assertJsonPath('data.nos_per_box', 490)
            ->assertJsonPath('data.nos_per_pouch', null);
    }
}
