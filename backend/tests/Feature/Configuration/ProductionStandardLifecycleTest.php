<?php

namespace Tests\Feature\Configuration;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ProductionStandardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * THE PRODUCT-LEVEL STANDARD under the Configuration Lifecycle Contract
 * (DEC-20260817-002) — Create · View · Edit · Activate/Deactivate ·
 * Safe Delete · Audit.
 *
 * A standard has no in-service flag of its own, so Archive here is the soft
 * delete and Activate is the restore — and the variant identity it occupies
 * (item + product name + cavities + weight + cycle time) stays RESERVED
 * while it is archived, which is §2 working rather than a bug.
 */
class ProductionStandardLifecycleTest extends ProductDefinitionLifecycleTestCase
{
    use RefreshDatabase;

    private const MODULE = ['production.view', 'production.manage'];

    private function item(string $sku = 'ITM-1'): Item
    {
        return Item::create(['sku' => $sku, 'name' => 'Bottle '.$sku, 'uom' => 'Nos', 'is_active' => true]);
    }

    private function standard(array $overrides = []): ProductionStandard
    {
        return ProductionStandard::create([
            'source_product_name' => '100ML ROUND',
            'cavities' => 5,
            'unit_weight_grams' => '12.9000',
            'cycle_time' => '12.30',
            'status' => 'draft',
            ...$overrides,
        ]);
    }

    private function service(): ProductionStandardService
    {
        return app(ProductionStandardService::class);
    }

    /** A completed run that names the standard — both by FK and in its frozen snapshot. */
    private function entryNaming(ProductionStandard $standard, array $columns = []): ShiftProductionEntry
    {
        $item = $standard->item ?? $this->item('ITM-RUN');

        return ShiftProductionEntry::create([
            'shift_id' => Shift::create(['name' => 'A', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true])->id,
            'work_center_id' => WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'is_active' => true])->id,
            'item_id' => $item->id,
            'warehouse_id' => Warehouse::create(['code' => 'WH-R', 'name' => 'Run store', 'is_active' => true])->id,
            'production_date' => '2026-04-01',
            'quantity_produced' => '100',
            'production_standard_id' => $standard->id,
            'config_snapshot' => ['production_standard_id' => $standard->id],
            ...$columns,
        ]);
    }

    // ---- Create / View / Edit -----------------------------------------

    public function test_a_standard_is_created_viewed_and_edited(): void
    {
        $user = $this->moduleUser(...self::MODULE);
        $item = $this->item('ITM-NEW');

        $created = $this->actingAs($user)->postJson('/api/v1/production/standards', [
            'item_id' => $item->id,
            'source_product_name' => '90ML RIB',
            'cavities' => 7,
            'unit_weight_grams' => 8.5,
            'cycle_time' => 12,
            'nos_per_tray' => 208,
            'tray_nos_per_box' => 1040,
        ]);

        $created->assertStatus(201);
        $id = $created->json('data.id');

        $shown = $this->actingAs($user)->getJson("/api/v1/production/standards/{$id}");
        $shown->assertOk();
        $this->assertSame('90ML RIB', $shown->json('data.source_product_name'));
        $this->assertFalse($shown->json('data.is_archived'));
        $this->assertSame(
            ['activate', 'archive', 'delete', 'edit'],
            collect($shown->json('data.can'))->keys()->sort()->values()->all(),
        );

        // Edit: attaching the Tally item is the standards page's edit, and it
        // keeps the row rather than spawning a sibling.
        $other = $this->standard(['source_product_name' => 'EDITABLE']);
        $this->actingAs($user)
            ->postJson("/api/v1/production/standards/{$other->id}/attach-item", ['item_id' => $item->id])
            ->assertOk()
            ->assertJsonPath('data.id', $other->id)
            ->assertJsonPath('data.item_id', $item->id);
    }

    public function test_an_archived_standard_still_reserves_its_variant_identity(): void
    {
        $user = $this->moduleUser(...self::MODULE);
        $item = $this->item('ITM-DUP');
        $standard = $this->standard(['item_id' => $item->id]);

        $this->actingAs($user)
            ->postJson("/api/v1/production/standards/{$standard->id}/archive", ['reason' => 'wrong figures'])
            ->assertOk()
            ->assertJsonPath('data.is_archived', true);

        // Same item, same product name, same three figures — refused, and the
        // refusal says the archived row is still holding the slot.
        $this->actingAs($user)
            ->postJson('/api/v1/production/standards', [
                'item_id' => $item->id,
                'source_product_name' => '100ML ROUND',
                'cavities' => 5,
                'unit_weight_grams' => 12.9,
                'cycle_time' => 12.3,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('source_product_name');
    }

    // ---- Activate / Deactivate ----------------------------------------

    public function test_archive_removes_a_standard_from_new_selection_while_history_still_renders(): void
    {
        $user = $this->moduleUser(...self::MODULE);
        $item = $this->item('ITM-HIST');
        $standard = $this->standard(['item_id' => $item->id, 'status' => 'approved']);
        ProductionStandardPackaging::create([
            'production_standard_id' => $standard->id,
            'mode' => ProductionStandardPackaging::MODE_DIRECT_BOX,
            'nos_per_box' => 840,
            'is_default' => true,
        ]);

        $this->service()->archive($standard->fresh());

        // Out of the workspace list the pickers read…
        $list = $this->actingAs($user)->getJson('/api/v1/production/standards?view=all');
        $list->assertOk();
        $this->assertNotContains($standard->id, collect($list->json('data'))->pluck('id')->all());

        // …and still fully readable by id, because history has to render.
        $this->actingAs($user)
            ->getJson("/api/v1/production/standards/{$standard->id}")
            ->assertOk()
            ->assertJsonPath('data.is_archived', true)
            ->assertJsonPath('data.can.activate', true);

        $this->actingAs($user)
            ->postJson("/api/v1/production/standards/{$standard->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_archived', false);

        $back = $this->actingAs($user)->getJson('/api/v1/production/standards?view=all');
        $this->assertContains($standard->id, collect($back->json('data'))->pluck('id')->all());
    }

    public function test_the_list_offers_a_read_only_user_nothing_and_leaves_delete_undetermined_otherwise(): void
    {
        $writer = $this->moduleUser(...self::MODULE);
        $this->standard(['item_id' => $this->item('ITM-LIST')->id, 'status' => 'approved']);

        // A module user without the Owner-level tier is answered FALSE, not
        // null: that is a decision no amount of counting would change.
        $rows = $this->actingAs($writer)->getJson('/api/v1/production/standards?view=all')->json('data');
        $this->assertNotSame([], $rows);
        $this->assertTrue($rows[0]['can']['edit']);
        $this->assertFalse($rows[0]['can']['delete']);

        // For someone who MAY hard-delete, a LIST answers null — undetermined,
        // ask show() — rather than three COUNTs per row for an answer the
        // confirm dialog re-fetches anyway.
        $owner = $this->ownerUser(...self::MODULE);
        $ownerRows = $this->actingAs($owner)->getJson('/api/v1/production/standards?view=all')->json('data');
        $this->assertNull($ownerRows[0]['can']['delete']);

        // A user who may READ the standards but not write them is offered
        // nothing: the row's eligibility intersected with the grant the write
        // routes actually need.
        $reader = $this->moduleUser('production.view');
        $readerRows = $this->actingAs($reader)->getJson('/api/v1/production/standards?view=all')->json('data');
        $this->assertSame(
            ['edit' => false, 'activate' => false, 'archive' => false, 'delete' => false],
            $readerRows[0]['can'],
        );
    }

    // ---- Safe delete ---------------------------------------------------

    public function test_a_module_user_without_the_owner_tier_cannot_hard_delete_but_may_archive(): void
    {
        $user = $this->moduleUser(...self::MODULE);
        $standard = $this->standard();

        $this->actingAs($user)
            ->deleteJson("/api/v1/production/standards/{$standard->id}")
            ->assertStatus(403);

        $this->assertNotNull(ProductionStandard::withTrashed()->find($standard->id));

        $this->actingAs($user)
            ->postJson("/api/v1/production/standards/{$standard->id}/archive")
            ->assertOk();
    }

    public function test_a_referenced_standard_is_refused_with_counts_and_its_children_survive(): void
    {
        $owner = $this->ownerUser(...self::MODULE);
        $item = $this->item('ITM-USED');
        $standard = $this->standard(['item_id' => $item->id]);

        $packaging = ProductionStandardPackaging::create([
            'production_standard_id' => $standard->id,
            'mode' => ProductionStandardPackaging::MODE_DIRECT_BOX,
            'nos_per_box' => 840,
        ]);
        $entry = $this->entryNaming($standard);

        $response = $this->actingAs($owner)->deleteJson("/api/v1/production/standards/{$standard->id}");

        $response->assertStatus(422);
        $this->assertSame('configuration_in_use', $response->json('code'));
        $this->assertSame('archive', $response->json('alternative'));

        $blocking = collect($response->json('blocking'))->keyBy('code');
        $this->assertSame(1, $blocking['production_standard_packagings']['count'], 'the cascade-side child');
        $this->assertSame(1, $blocking['shift_production_entries']['count'], 'the SET NULL column no backstop sees');
        $this->assertSame(1, $blocking['shift_config_snapshots']['count'], 'the frozen JSON reference no FK expresses');

        // Nothing was destroyed and nothing was blanked to make the check pass.
        $this->assertNotNull(ProductionStandardPackaging::withTrashed()->find($packaging->id));
        $this->assertSame(
            $standard->id,
            (int) DB::table('shift_production_entries')->where('id', $entry->id)->value('production_standard_id'),
        );
        $this->assertNotNull(ProductionStandard::withTrashed()->find($standard->id));
    }

    public function test_an_unused_standard_is_really_deleted_and_frees_its_variant_identity(): void
    {
        $owner = $this->ownerUser(...self::MODULE);
        $item = $this->item('ITM-FREE');
        $standard = $this->standard(['item_id' => $item->id]);

        $this->actingAs($owner)
            ->deleteJson("/api/v1/production/standards/{$standard->id}")
            ->assertNoContent();

        $this->assertNull(ProductionStandard::withTrashed()->find($standard->id));

        // The identity is genuinely free again — nothing in history ever
        // referred to that row, which is the whole condition for §2 releasing
        // it.
        $this->actingAs($owner)
            ->postJson('/api/v1/production/standards', [
                'item_id' => $item->id,
                'source_product_name' => '100ML ROUND',
                'cavities' => 5,
                'unit_weight_grams' => 12.9,
                'cycle_time' => 12.3,
            ])
            ->assertStatus(201);
    }

    // ---- The declaration itself ---------------------------------------

    public function test_every_undefended_reference_to_a_standard_is_declared(): void
    {
        $this->assertEveryUndefendedReferenceIsDeclared($this->service(), 'production_standards');
    }

    // ---- Audit ---------------------------------------------------------

    public function test_the_lifecycle_is_recorded_in_the_configuration_audit_trail(): void
    {
        $user = $this->moduleUser(...self::MODULE);
        $standard = $this->standard();

        $this->actingAs($user)->postJson("/api/v1/production/standards/{$standard->id}/archive")->assertOk();
        $this->actingAs($user)->postJson("/api/v1/production/standards/{$standard->id}/activate")->assertOk();

        $trail = $this->auditTrailFor($standard);

        $this->assertSame(
            // The create was made directly by the test with nobody signed in,
            // so it is recorded with a NULL causer rather than a guessed one —
            // exactly what the masters pull and the seeders produce on live.
            ['production_standard.created', 'production_standard.deleted', 'production_standard.restored'],
            $trail->pluck('description')->all(),
        );
        $this->assertSame([null, $user->id, $user->id], $trail->pluck('causer_id')->map(
            fn ($id) => $id === null ? null : (int) $id,
        )->all());
    }
}
