<?php

namespace Tests\Feature\Configuration;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\ConfigurationStatus;
use App\Modules\Production\Models\ProductionConfiguration;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ProductionConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * THE MACHINE-PRODUCT CONFIGURATION under the Configuration Lifecycle
 * Contract (DEC-20260817-002) — Create · View · Edit · Activate/Deactivate ·
 * Safe Delete · Audit.
 *
 * This entity is the CONVERGENCE case: it already had a `deactivate` action
 * of its own that bypassed the shared mechanism entirely. Two halves of this
 * file matter more than the rest:
 *
 *  · THE APPROVAL GATE MUST SURVIVE THE WIRING. `status` is a three-case
 *    enum, so a naive ActiveFlag mapping would have offered "activate" on a
 *    DRAFT and written `approved` straight past assertComplete /
 *    assertWithinMachineCapability / assertNoOverlap, with no approver's
 *    name on it. That is the four-eyes rule removed by accident, and it is
 *    pinned below in both directions — the button and the write.
 *
 *  · THE EFFECTIVE WINDOW MUST NOT MOVE. Deactivating still stamps
 *    `effective_to` with today when the window was open, exactly as it did
 *    before, and reactivating leaves the recorded window alone.
 */
class ProductionConfigurationLifecycleTest extends ProductDefinitionLifecycleTestCase
{
    use RefreshDatabase;

    private const MODULE = ['production.view', 'production.manage'];

    private function item(string $sku = 'ITM-C'): Item
    {
        return Item::firstOrCreate(['sku' => $sku], ['name' => 'Bottle '.$sku, 'uom' => 'Nos', 'is_active' => true]);
    }

    private function machine(string $code = 'MC-01'): WorkCenter
    {
        return WorkCenter::firstOrCreate(['code' => $code], ['name' => 'Machine '.$code, 'is_active' => true]);
    }

    private function configuration(array $overrides = []): ProductionConfiguration
    {
        return ProductionConfiguration::create([
            'work_center_id' => $this->machine()->id,
            'item_id' => $this->item()->id,
            'unit_weight_grams' => '12.9000',
            'default_cycle_time' => '12.30',
            'default_cavities' => 5,
            'status' => ConfigurationStatus::Draft->value,
            ...$overrides,
        ]);
    }

    private function service(): ProductionConfigurationService
    {
        return app(ProductionConfigurationService::class);
    }

    private function approved(array $overrides = []): ProductionConfiguration
    {
        $configuration = $this->configuration($overrides);

        return $this->service()->approve($configuration, null);
    }

    // ---- Create / View / Edit -----------------------------------------

    public function test_a_configuration_is_created_viewed_and_edited(): void
    {
        $user = $this->moduleUser(...self::MODULE);

        $created = $this->actingAs($user)->postJson('/api/v1/production/configurations', [
            'work_center_id' => $this->machine('MC-09')->id,
            'item_id' => $this->item('ITM-CREATE')->id,
            'unit_weight_grams' => 12.9,
            'default_cycle_time' => 12.3,
            'default_cavities' => 5,
        ]);

        $created->assertStatus(201);
        $id = $created->json('data.id');
        $this->assertSame('draft', $created->json('data.status'));

        $shown = $this->actingAs($user)->getJson("/api/v1/production/configurations/{$id}");
        $shown->assertOk();
        $this->assertSame(
            ['activate', 'archive', 'delete', 'edit'],
            collect($shown->json('data.can'))->keys()->sort()->values()->all(),
        );
        $this->assertTrue($shown->json('data.can.edit'), 'a draft is editable');

        $this->actingAs($user)
            ->putJson("/api/v1/production/configurations/{$id}", [
                'work_center_id' => $created->json('data.work_center.id'),
                'item_id' => $created->json('data.item.id'),
                'unit_weight_grams' => 13.1,
                'default_cycle_time' => 12.3,
                'default_cavities' => 5,
            ])
            ->assertOk();
    }

    public function test_an_approved_configuration_is_not_offered_edit(): void
    {
        $user = $this->moduleUser(...self::MODULE);
        $configuration = $this->approved();

        $this->actingAs($user)
            ->getJson("/api/v1/production/configurations/{$configuration->id}")
            ->assertOk()
            ->assertJsonPath('data.can.edit', false);
    }

    public function test_two_approved_configurations_for_one_machine_and_product_may_not_overlap(): void
    {
        $first = $this->approved();

        $twin = ProductionConfiguration::create([
            'work_center_id' => $first->work_center_id,
            'item_id' => $first->item_id,
            'unit_weight_grams' => '12.9000',
            'default_cycle_time' => '12.30',
            'default_cavities' => 5,
            'status' => ConfigurationStatus::Draft->value,
        ]);

        $this->expectExceptionMessage('already approved for this machine, product, mould and colour');
        $this->service()->approve($twin, null);
    }

    // ---- Activate / Deactivate — the convergence -----------------------

    public function test_deactivate_and_archive_are_one_act_with_the_same_effective_window_stamp(): void
    {
        $user = $this->moduleUser(...self::MODULE);

        // The screen's existing verb…
        $one = $this->approved();
        $this->actingAs($user)
            ->postJson("/api/v1/production/configurations/{$one->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.effective_to', now()->toDateString());

        // …and the contract's verb. Same status, same window stamp.
        $two = $this->approved(['work_center_id' => $this->machine('MC-02')->id]);
        $this->actingAs($user)
            ->postJson("/api/v1/production/configurations/{$two->id}/archive", ['reason' => 'superseded'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.effective_to', now()->toDateString());
    }

    public function test_deactivating_an_already_inactive_configuration_is_refused_rather_than_rewritten(): void
    {
        $configuration = $this->approved();
        $this->service()->deactivate($configuration);

        $before = DB::table('production_configurations')->where('id', $configuration->id)->first();

        $this->expectExceptionMessage('already retired');
        try {
            $this->service()->deactivate($configuration->fresh());
        } finally {
            $after = DB::table('production_configurations')->where('id', $configuration->id)->first();
            $this->assertEquals($before->updated_at, $after->updated_at, 'a refused archive must not touch the row');
        }
    }

    public function test_a_draft_is_never_offered_activate_and_the_write_refuses_too(): void
    {
        $user = $this->moduleUser(...self::MODULE);
        $draft = $this->configuration();

        $this->actingAs($user)
            ->getJson("/api/v1/production/configurations/{$draft->id}")
            ->assertOk()
            ->assertJsonPath(
                'data.can.activate',
                false,
                // A draft becoming live is APPROVAL — an attributable act with
                // three gates — not the generic Activate button.
            );

        $this->actingAs($user)
            ->postJson("/api/v1/production/configurations/{$draft->id}/activate")
            ->assertStatus(422);

        $this->assertSame(
            ConfigurationStatus::Draft->value,
            (string) DB::table('production_configurations')->where('id', $draft->id)->value('status'),
        );
    }

    public function test_reactivating_re_runs_the_overlap_gate(): void
    {
        $user = $this->moduleUser(...self::MODULE);
        $original = $this->approved();
        $this->service()->deactivate($original);

        // While it was out of service, a replacement was approved for exactly
        // the same machine + product. Putting the old one back would give the
        // floor two live standards that disagree.
        $replacement = ProductionConfiguration::create([
            'work_center_id' => $original->work_center_id,
            'item_id' => $original->item_id,
            'unit_weight_grams' => '13.5000',
            'default_cycle_time' => '11.00',
            'default_cavities' => 5,
            'status' => ConfigurationStatus::Draft->value,
        ]);
        $this->service()->approve($replacement, null);

        $this->actingAs($user)
            ->postJson("/api/v1/production/configurations/{$original->id}/activate")
            ->assertStatus(422)
            ->assertJsonValidationErrors('effective_from');

        $this->assertSame(
            ConfigurationStatus::Inactive->value,
            (string) DB::table('production_configurations')->where('id', $original->id)->value('status'),
        );
    }

    public function test_reactivating_a_withdrawn_configuration_puts_it_back_in_service(): void
    {
        $user = $this->moduleUser(...self::MODULE);
        $configuration = $this->approved();
        $this->service()->deactivate($configuration);

        $this->actingAs($user)
            ->postJson("/api/v1/production/configurations/{$configuration->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        // The approval on record is the ORIGINAL one — reactivation restores
        // an approval, it does not mint a new one.
        $this->assertSame(
            ConfigurationStatus::Approved,
            ProductionConfiguration::query()->findOrFail($configuration->id)->status,
        );
    }

    public function test_a_configuration_withdrawn_while_still_a_draft_may_not_be_activated_into_approved(): void
    {
        $user = $this->moduleUser(...self::MODULE);
        $draft = $this->configuration();
        $this->service()->deactivate($draft);

        // It is `inactive` now, so the draft guard no longer catches it — but
        // there is no approval to restore, and approve() takes drafts only.
        // Reaching `approved` here would be an approved standard nobody
        // approved.
        $this->actingAs($user)
            ->postJson("/api/v1/production/configurations/{$draft->id}/activate")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    // ---- Safe delete ---------------------------------------------------

    public function test_a_module_user_without_the_owner_tier_cannot_hard_delete_but_may_archive(): void
    {
        $user = $this->moduleUser(...self::MODULE);
        $configuration = $this->approved();

        $this->actingAs($user)
            ->deleteJson("/api/v1/production/configurations/{$configuration->id}")
            ->assertStatus(403);

        $this->assertNotNull(ProductionConfiguration::withTrashed()->find($configuration->id));

        $this->actingAs($user)
            ->postJson("/api/v1/production/configurations/{$configuration->id}/archive")
            ->assertOk();
    }

    public function test_a_configuration_a_shift_ran_to_is_refused_with_counts_and_nothing_is_blanked(): void
    {
        $owner = $this->ownerUser(...self::MODULE);
        $configuration = $this->approved();

        $entry = ShiftProductionEntry::create([
            'shift_id' => Shift::create(['name' => 'A', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true])->id,
            'work_center_id' => $configuration->work_center_id,
            'item_id' => $configuration->item_id,
            'warehouse_id' => Warehouse::create(['code' => 'WH-C', 'name' => 'Store', 'is_active' => true])->id,
            'production_date' => '2026-04-01',
            'quantity_produced' => '100',
            'production_configuration_id' => $configuration->id,
            'config_snapshot' => ['configuration_id' => $configuration->id],
        ]);

        $response = $this->actingAs($owner)->deleteJson("/api/v1/production/configurations/{$configuration->id}");

        $response->assertStatus(422);
        $this->assertSame('configuration_in_use', $response->json('code'));
        $this->assertSame('archive', $response->json('alternative'));

        $blocking = collect($response->json('blocking'))->keyBy('code');
        $this->assertSame(1, $blocking['shift_production_entries']['count'], 'the SET NULL column no backstop sees');
        $this->assertSame(1, $blocking['shift_config_snapshots']['count'], 'the frozen JSON reference no FK expresses');

        $row = DB::table('shift_production_entries')->where('id', $entry->id)->first();
        $this->assertSame($configuration->id, (int) $row->production_configuration_id, 'the reference was blanked');
        $this->assertNotNull(ProductionConfiguration::withTrashed()->find($configuration->id));
    }

    public function test_a_configuration_named_only_in_a_frozen_snapshot_still_blocks(): void
    {
        $owner = $this->ownerUser(...self::MODULE);
        $configuration = $this->approved();

        // The foreign key is empty; only the frozen snapshot names it. No
        // database mechanism anywhere would notice this one.
        ShiftProductionEntry::create([
            'shift_id' => Shift::create(['name' => 'B', 'start_time' => '14:00', 'end_time' => '22:00', 'is_active' => true])->id,
            'work_center_id' => $configuration->work_center_id,
            'item_id' => $configuration->item_id,
            'warehouse_id' => Warehouse::create(['code' => 'WH-S', 'name' => 'Store', 'is_active' => true])->id,
            'production_date' => '2026-04-02',
            'quantity_produced' => '100',
            'config_snapshot' => ['configuration_id' => $configuration->id],
        ]);

        $response = $this->actingAs($owner)->deleteJson("/api/v1/production/configurations/{$configuration->id}");

        $response->assertStatus(422);
        $this->assertSame(
            ['shift_config_snapshots'],
            collect($response->json('blocking'))->pluck('code')->all(),
        );
    }

    public function test_an_unused_configuration_is_really_deleted(): void
    {
        $owner = $this->ownerUser(...self::MODULE);
        $configuration = $this->configuration();

        $this->actingAs($owner)
            ->deleteJson("/api/v1/production/configurations/{$configuration->id}")
            ->assertNoContent();

        $this->assertNull(ProductionConfiguration::withTrashed()->find($configuration->id));
    }

    // ---- The declaration itself ---------------------------------------

    public function test_every_undefended_reference_to_a_configuration_is_declared(): void
    {
        $this->assertEveryUndefendedReferenceIsDeclared($this->service(), 'production_configurations');
    }

    // ---- Audit ---------------------------------------------------------

    public function test_the_lifecycle_is_recorded_in_the_configuration_audit_trail(): void
    {
        $user = $this->moduleUser(...self::MODULE);
        $configuration = $this->approved();

        $this->actingAs($user)
            ->postJson("/api/v1/production/configurations/{$configuration->id}/archive")
            ->assertOk();

        $trail = $this->auditTrailFor($configuration);

        $this->assertContains('production_configuration.updated', $trail->pluck('description')->all());
        $this->assertSame(
            $user->id,
            (int) DB::table('production_configurations')->where('id', $configuration->id)->value('updated_by'),
        );
    }
}
