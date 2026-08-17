<?php

namespace Tests\Feature\Configuration;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\ScrapReason;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * THE SCRAP REASON MASTER, through the Configuration Lifecycle Contract.
 *
 * Two of its three references are `ON DELETE SET NULL`
 * (`shift_production_entries.scrap_reason_id`, `shift_scraps.scrap_reason_id`),
 * so there is no database backstop and — since SchemaCascades reads
 * CASCADE only — no schema backstop either. A delete that got past the
 * declared checks would leave every completed batch still standing but no
 * longer saying WHY material was scrapped. The refusal test asserts those
 * columns still hold the reason's id, not merely that the request 422'd.
 *
 * The selection half (a withdrawn reason is refused on a new completion,
 * a handover, a page row and a work-order completion, while a completed
 * batch still names it) is already pinned by ActiveSelectionTest; this file
 * does not duplicate it.
 */
class ScrapReasonLifecycleTest extends FloorMasterLifecycleTestCase
{
    protected function modulePermissions(): array
    {
        return ['production.view', 'production.manage'];
    }

    private function reason(string $code = 'SCR-01', bool $active = true): ScrapReason
    {
        return ScrapReason::create(['code' => $code, 'name' => 'Startup scrap', 'is_active' => $active]);
    }

    // ---- Create / edit --------------------------------------------------

    public function test_a_scrap_reason_is_created_active_and_edited(): void
    {
        $this->manager();

        $reason = $this->postJson('/api/v1/production/scrap-reasons', ['code' => 'SCR-09', 'name' => 'Neck defect'])
            ->assertCreated()
            ->assertJsonPath('data.is_active', true)
            ->json('data');

        // Edit did not exist on this master before the contract.
        $this->putJson("/api/v1/production/scrap-reasons/{$reason['id']}", ['name' => 'Neck finish defect'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Neck finish defect');
    }

    public function test_a_duplicate_code_is_refused_even_when_the_holder_is_withdrawn(): void
    {
        $this->manager();
        $reason = $this->reason('SCR-01');

        $this->postJson("/api/v1/production/scrap-reasons/{$reason->id}/archive")->assertOk();

        $this->postJson('/api/v1/production/scrap-reasons', ['code' => 'SCR-01', 'name' => 'Impostor'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    // ---- Archive / activate ---------------------------------------------

    public function test_a_referenced_reason_still_archives_and_reactivates(): void
    {
        $this->manager();
        $reason = $this->reason();
        $entryId = $this->batchScrappedFor($reason);

        $this->postJson("/api/v1/production/scrap-reasons/{$reason->id}/archive", ['reason' => 'superseded'])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.can.activate', true);

        $this->assertSame($reason->id, (int) DB::table('shift_production_entries')->where('id', $entryId)->value('scrap_reason_id'));

        $this->postJson("/api/v1/production/scrap-reasons/{$reason->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_a_withdrawn_reason_leaves_new_selection_while_history_still_renders(): void
    {
        $this->manager();
        $reason = $this->reason();
        $entryId = $this->batchScrappedFor($reason);

        $this->postJson("/api/v1/production/scrap-reasons/{$reason->id}/archive")->assertOk();

        $this->assertSame([], array_column($this->getJson('/api/v1/production/scrap-reasons?active=1')->assertOk()->json('data'), 'code'));
        $this->assertSame(['SCR-01'], array_column($this->getJson('/api/v1/production/scrap-reasons')->assertOk()->json('data'), 'code'));
        $this->assertSame($reason->id, (int) DB::table('shift_production_entries')->where('id', $entryId)->value('scrap_reason_id'));
    }

    // ---- Delete ---------------------------------------------------------

    public function test_a_module_manager_without_the_tier_may_not_hard_delete_but_may_still_archive(): void
    {
        $this->manager();
        $reason = $this->reason();

        $this->deleteJson("/api/v1/production/scrap-reasons/{$reason->id}")->assertForbidden();
        $this->assertDatabaseHas('scrap_reasons', ['id' => $reason->id]);

        $this->getJson("/api/v1/production/scrap-reasons/{$reason->id}")
            ->assertOk()
            ->assertJsonPath('data.can.delete', false)
            ->assertJsonPath('data.can.archive', true);

        $this->postJson("/api/v1/production/scrap-reasons/{$reason->id}/archive")->assertOk();
    }

    public function test_deleting_a_referenced_reason_is_refused_and_no_set_null_child_is_blanked(): void
    {
        $this->owner();
        $reason = $this->reason();
        $entryId = $this->batchScrappedFor($reason);
        $lineId = DB::table('shift_scraps')->insertGetId([
            'shift_production_entry_id' => $entryId, 'type' => 'startup',
            'scrap_reason_id' => $reason->id, 'quantity_kg' => '2',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->deleteJson("/api/v1/production/scrap-reasons/{$reason->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'configuration_in_use')
            ->assertJsonPath('alternative', 'archive');

        $blocking = collect($response->json('blocking'))->keyBy('code');
        $this->assertSame(1, $blocking['shift_production_entries']['count']);
        $this->assertSame(1, $blocking['shift_scraps']['count']);
        $this->assertSame('production batch', $blocking['shift_production_entries']['label']);

        // Both are ON DELETE SET NULL: the rows would have survived a wrong
        // delete and simply stopped saying why the material was scrapped.
        $this->assertDatabaseHas('scrap_reasons', ['id' => $reason->id]);
        $this->assertSame($reason->id, (int) DB::table('shift_production_entries')->where('id', $entryId)->value('scrap_reason_id'));
        $this->assertSame($reason->id, (int) DB::table('shift_scraps')->where('id', $lineId)->value('scrap_reason_id'));
    }

    public function test_an_unused_reason_is_really_deleted_and_frees_its_code(): void
    {
        $this->owner();
        $reason = $this->reason('SCR-77');

        $this->deleteJson("/api/v1/production/scrap-reasons/{$reason->id}")->assertNoContent();
        $this->assertSame(0, ScrapReason::withTrashed()->whereKey($reason->id)->count());

        $this->postJson('/api/v1/production/scrap-reasons', ['code' => 'SCR-77', 'name' => 'Reused code'])
            ->assertCreated();
    }

    // ---- Audit ----------------------------------------------------------

    public function test_every_lifecycle_act_is_recorded_in_the_configuration_audit(): void
    {
        $user = $this->manager();

        $reason = $this->postJson('/api/v1/production/scrap-reasons', ['code' => 'SCR-11', 'name' => 'Flash'])
            ->assertCreated()->json('data');

        $this->putJson("/api/v1/production/scrap-reasons/{$reason['id']}", ['name' => 'Flashing'])->assertOk();
        $this->postJson("/api/v1/production/scrap-reasons/{$reason['id']}/archive")->assertOk();

        $trail = Activity::query()
            ->where('log_name', ScrapReason::CONFIGURATION_LOG_NAME)
            ->where('subject_type', (new ScrapReason)->getMorphClass())
            ->where('subject_id', $reason['id'])
            ->orderBy('id')
            ->get();

        $this->assertSame(['scrap_reason.created', 'scrap_reason.updated', 'scrap_reason.updated'], $trail->pluck('description')->all());
        $this->assertSame([$user->id, $user->id, $user->id], $trail->pluck('causer_id')->map(fn ($id) => (int) $id)->all());

        $archive = json_decode($trail[2]->attribute_changes, true);
        $this->assertFalse($archive['attributes']['is_active']);
    }

    // ---- Fixtures -------------------------------------------------------

    private function batchScrappedFor(ScrapReason $reason): int
    {
        $shift = Shift::firstOrCreate(['name' => 'Shift A'], ['start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $machine = WorkCenter::firstOrCreate(['code' => 'MC-01'], ['name' => 'Machine 1', 'is_active' => true]);
        $item = Item::firstOrCreate(['sku' => 'BTL-1'], ['name' => 'Bottle', 'uom' => 'Nos.', 'is_active' => true]);
        $warehouse = Warehouse::firstOrCreate(['code' => 'FG-TEST'], ['name' => 'FG Test', 'is_active' => true]);

        return DB::table('shift_production_entries')->insertGetId([
            'shift_id' => $shift->id, 'work_center_id' => $machine->id, 'item_id' => $item->id,
            'warehouse_id' => $warehouse->id, 'production_date' => '2026-08-01',
            'scrap_reason_id' => $reason->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
