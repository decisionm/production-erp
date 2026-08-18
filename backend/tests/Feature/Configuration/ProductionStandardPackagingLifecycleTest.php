<?php

namespace Tests\Feature\Configuration;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\ShiftProductionEntryPackingLine;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ProductionStandardPackagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * THE PACKING VARIANT under the Configuration Lifecycle Contract
 * (DEC-20260817-002) — Create · View · Edit · Activate/Deactivate ·
 * Safe Delete · Audit.
 *
 * Two things make this entity its own case rather than a copy of the
 * standard's:
 *
 *  · It had NOWHERE TO BE ARCHIVED TO. `production_standard_packagings`
 *    carried neither `is_active` nor `deleted_at`, so the shared Archive
 *    refused outright and the only way to withdraw a wrong pack was to
 *    destroy the row a past shift's prefill was derived from. Migration
 *    2026_08_18_090000 adds `deleted_at` (and the two audit stamps); these
 *    tests are what that migration is for.
 *
 *  · NOTHING CASCADES INTO IT AT ALL. Both references — the shift entry and
 *    its packing line — are SET NULL, which the shipped cascade backstop
 *    cannot see, so the module's declaration is the only guard there is.
 */
class ProductionStandardPackagingLifecycleTest extends ProductDefinitionLifecycleTestCase
{
    use RefreshDatabase;

    private const MODULE = ['production.view', 'production.manage'];

    private function item(string $sku = 'ITM-P'): Item
    {
        return Item::create(['sku' => $sku, 'name' => 'Bottle '.$sku, 'uom' => 'Nos', 'is_active' => true]);
    }

    private function standard(): ProductionStandard
    {
        return ProductionStandard::create([
            'item_id' => $this->item()->id,
            'source_product_name' => '200ML RA',
            'cavities' => 4,
            'unit_weight_grams' => '15.0000',
            'cycle_time' => '14.00',
            'status' => 'approved',
        ]);
    }

    private function packaging(ProductionStandard $standard, array $overrides = []): ProductionStandardPackaging
    {
        return ProductionStandardPackaging::create([
            'production_standard_id' => $standard->id,
            'mode' => ProductionStandardPackaging::MODE_TRAY,
            'nos_per_tray' => 70,
            'trays_per_box' => 7,
            'nos_per_box' => 490,
            ...$overrides,
        ]);
    }

    private function service(): ProductionStandardPackagingService
    {
        return app(ProductionStandardPackagingService::class);
    }

    private function entry(ProductionStandard $standard): ShiftProductionEntry
    {
        return ShiftProductionEntry::create([
            'shift_id' => Shift::create(['name' => 'A', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true])->id,
            'work_center_id' => WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'is_active' => true])->id,
            'item_id' => $standard->item_id,
            'warehouse_id' => Warehouse::create(['code' => 'WH-P', 'name' => 'Pack store', 'is_active' => true])->id,
            'production_date' => '2026-04-01',
            'quantity_produced' => '490',
        ]);
    }

    // ---- Create / View / Edit -----------------------------------------

    public function test_a_variant_is_created_viewed_and_edited(): void
    {
        $user = $this->moduleUser(...self::MODULE);
        $standard = $this->standard();

        $created = $this->actingAs($user)->postJson("/api/v1/production/standards/{$standard->id}/packagings", [
            'mode' => 'tray',
            'nos_per_tray' => 70,
            'trays_per_box' => 7,
        ]);

        $created->assertStatus(201);
        $id = $created->json('data.id');

        $shown = $this->actingAs($user)->getJson("/api/v1/production/standards/{$standard->id}/packagings/{$id}");
        $shown->assertOk();
        $this->assertSame(490, $shown->json('data.nos_per_box'));
        $this->assertFalse($shown->json('data.is_archived'));
        $this->assertSame(
            ['activate', 'archive', 'delete', 'edit'],
            collect($shown->json('data.can'))->keys()->sort()->values()->all(),
        );

        $this->actingAs($user)
            ->putJson("/api/v1/production/standards/{$standard->id}/packagings/{$id}", [
                'mode' => 'tray',
                'nos_per_tray' => 60,
                'trays_per_box' => 7,
            ])
            ->assertOk()
            ->assertJsonPath('data.nos_per_box', 420);
    }

    public function test_an_archived_variant_still_reserves_its_variant_key(): void
    {
        $user = $this->moduleUser(...self::MODULE);
        $standard = $this->standard();
        $packaging = $this->packaging($standard);

        $this->actingAs($user)
            ->postJson("/api/v1/production/standards/{$standard->id}/packagings/{$packaging->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.is_archived', true);

        // The identical twin is still refused: an archived variant keeps its
        // slot, so nobody may quietly add a second copy of it (§2).
        $this->actingAs($user)
            ->postJson("/api/v1/production/standards/{$standard->id}/packagings", [
                'mode' => 'tray',
                'nos_per_tray' => 70,
                'trays_per_box' => 7,
            ])
            ->assertStatus(422);
    }

    // ---- Activate / Deactivate ----------------------------------------

    public function test_archive_removes_a_variant_from_new_selection_while_history_still_renders(): void
    {
        $user = $this->moduleUser(...self::MODULE);
        $standard = $this->standard();
        $packaging = $this->packaging($standard, ['is_default' => true]);

        $this->service()->archive($packaging->fresh());

        // Gone from the standard's live options…
        $this->assertSame(0, $standard->packagings()->count());

        // …and still fully readable, and reversible.
        $this->actingAs($user)
            ->getJson("/api/v1/production/standards/{$standard->id}/packagings/{$packaging->id}")
            ->assertOk()
            ->assertJsonPath('data.is_archived', true)
            ->assertJsonPath('data.can.activate', true);

        $this->actingAs($user)
            ->postJson("/api/v1/production/standards/{$standard->id}/packagings/{$packaging->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_archived', false);

        $this->assertSame(1, $standard->packagings()->count());
    }

    public function test_a_variant_of_another_standard_is_never_reachable_through_this_one(): void
    {
        $user = $this->moduleUser(...self::MODULE);
        $mine = $this->standard();
        $theirs = ProductionStandard::create([
            'item_id' => $this->item('ITM-OTHER')->id,
            'source_product_name' => 'SOMEONE ELSE',
            'cavities' => 2,
            'unit_weight_grams' => '9.0000',
            'cycle_time' => '10.00',
            'status' => 'approved',
        ]);
        $foreign = $this->packaging($theirs);

        $this->actingAs($user)
            ->postJson("/api/v1/production/standards/{$mine->id}/packagings/{$foreign->id}/archive")
            ->assertStatus(422);

        $this->assertFalse(ProductionStandardPackaging::withTrashed()->find($foreign->id)->trashed());
    }

    // ---- Safe delete ---------------------------------------------------

    public function test_a_module_user_without_the_owner_tier_cannot_hard_delete_but_may_archive(): void
    {
        $user = $this->moduleUser(...self::MODULE);
        $standard = $this->standard();
        $packaging = $this->packaging($standard);

        $this->actingAs($user)
            ->deleteJson("/api/v1/production/standards/{$standard->id}/packagings/{$packaging->id}")
            ->assertStatus(403);

        $this->assertNotNull(ProductionStandardPackaging::withTrashed()->find($packaging->id));

        $this->actingAs($user)
            ->postJson("/api/v1/production/standards/{$standard->id}/packagings/{$packaging->id}/archive")
            ->assertOk();
    }

    public function test_a_referenced_variant_is_refused_with_counts_and_nothing_is_blanked(): void
    {
        $owner = $this->ownerUser(...self::MODULE);
        $standard = $this->standard();
        $packaging = $this->packaging($standard);
        $entry = $this->entry($standard);
        $entry->forceFill(['production_standard_packaging_id' => $packaging->id])->save();

        $line = ShiftProductionEntryPackingLine::create([
            'shift_production_entry_id' => $entry->id,
            'production_standard_packaging_id' => $packaging->id,
            'position' => 1,
            'mode' => 'tray',
            'boxes' => 1,
            'nos_per_box' => 490,
            'derived_pieces' => 490,
            'actual_pieces' => 490,
        ]);

        $response = $this->actingAs($owner)->deleteJson(
            "/api/v1/production/standards/{$standard->id}/packagings/{$packaging->id}",
        );

        $response->assertStatus(422);
        $this->assertSame('configuration_in_use', $response->json('code'));

        $blocking = collect($response->json('blocking'))->keyBy('code');
        $this->assertSame(1, $blocking['shift_production_entries']['count']);
        $this->assertSame(1, $blocking['shift_production_entry_packing_lines']['count']);

        // Neither SET NULL column was blanked to make the check pass.
        $this->assertSame(
            $packaging->id,
            (int) DB::table('shift_production_entries')->where('id', $entry->id)->value('production_standard_packaging_id'),
        );
        $this->assertSame(
            $packaging->id,
            (int) DB::table('shift_production_entry_packing_lines')->where('id', $line->id)->value('production_standard_packaging_id'),
        );
    }

    public function test_a_variant_carrying_its_own_tally_identity_is_never_hard_deleted(): void
    {
        $owner = $this->ownerUser(...self::MODULE);
        $standard = $this->standard();
        $packaging = $this->packaging($standard, ['item_id' => $this->item('ITM-TALLY')->id]);

        $response = $this->actingAs($owner)->deleteJson(
            "/api/v1/production/standards/{$standard->id}/packagings/{$packaging->id}",
        );

        // The Tally identity is Tally's, not ours to drop (DEC-20260817-002
        // §4). No Tally call is made to reach that answer — the attribute on
        // our own row is the whole evidence.
        $response->assertStatus(422);
        $this->assertSame(
            ['tally_identity'],
            collect($response->json('blocking'))->pluck('code')->all(),
        );
        $this->assertNotNull(ProductionStandardPackaging::withTrashed()->find($packaging->id));
    }

    public function test_an_unused_variant_is_really_deleted_and_frees_its_key(): void
    {
        $owner = $this->ownerUser(...self::MODULE);
        $standard = $this->standard();
        $packaging = $this->packaging($standard);

        $this->actingAs($owner)
            ->deleteJson("/api/v1/production/standards/{$standard->id}/packagings/{$packaging->id}")
            ->assertNoContent();

        $this->assertNull(ProductionStandardPackaging::withTrashed()->find($packaging->id));

        $this->actingAs($owner)
            ->postJson("/api/v1/production/standards/{$standard->id}/packagings", [
                'mode' => 'tray',
                'nos_per_tray' => 70,
                'trays_per_box' => 7,
            ])
            ->assertStatus(201);
    }

    // ---- The declaration itself ---------------------------------------

    public function test_every_undefended_reference_to_a_variant_is_declared(): void
    {
        $this->assertEveryUndefendedReferenceIsDeclared($this->service(), 'production_standard_packagings');
    }

    // ---- Audit ---------------------------------------------------------

    public function test_the_lifecycle_is_recorded_in_the_configuration_audit_trail(): void
    {
        $user = $this->moduleUser(...self::MODULE);
        $standard = $this->standard();

        $id = $this->actingAs($user)->postJson("/api/v1/production/standards/{$standard->id}/packagings", [
            'mode' => 'tray',
            'nos_per_tray' => 70,
            'trays_per_box' => 7,
        ])->json('data.id');

        $this->actingAs($user)
            ->postJson("/api/v1/production/standards/{$standard->id}/packagings/{$id}/archive")
            ->assertOk();

        $trail = $this->auditTrailFor(ProductionStandardPackaging::withTrashed()->findOrFail($id));

        $this->assertSame(
            ['production_standard_packaging.created', 'production_standard_packaging.deleted'],
            $trail->pluck('description')->all(),
        );
        $this->assertSame($user->id, (int) DB::table('production_standard_packagings')->where('id', $id)->value('created_by'));
    }
}
