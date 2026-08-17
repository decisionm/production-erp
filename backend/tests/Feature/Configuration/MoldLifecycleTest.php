<?php

namespace Tests\Feature\Configuration;

use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\Enums\MoldStatus;
use App\Modules\Production\Models\Mold;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * THE MOULD MASTER, through the Configuration Lifecycle Contract.
 *
 * Two things are specific to this entity and both are the reason it gets
 * its own file rather than a row in a shared table:
 *
 *  1 · ITS STATE IS A THREE-CASE ENUM, not a boolean. `under_repair` is
 *      NEITHER active nor retired, so Activate and Archive are offered at
 *      the same time — asking them as each other's opposite would strand a
 *      mould under repair in a state it could never leave. Archive must
 *      write the `retired` CASE, never `false`.
 *
 *  2 · EVERY REFERENCE TO A MOULD IS `ON DELETE SET NULL`, which means
 *      there is no backstop anywhere: not in the database (the delete would
 *      succeed and simply blank the child's column) and not in
 *      SchemaCascades (which reads CASCADE only). So the delete-refused
 *      test does not merely assert a status code — it asserts that
 *      `mold_change_logs.changed_from_mold_id`, `changed_to_mold_id` and
 *      `production_configurations.mold_id` STILL HOLD THE MOULD'S ID
 *      afterwards. A missing declaration would leave those columns NULL
 *      with no error raised anywhere, and the log would stop answering the
 *      one question it exists for.
 */
class MoldLifecycleTest extends FloorMasterLifecycleTestCase
{
    protected function modulePermissions(): array
    {
        return ['production.view', 'production.manage'];
    }

    private function mould(string $code = 'MLD-01', MoldStatus $status = MoldStatus::Active): Mold
    {
        return Mold::create(['code' => $code, 'name' => 'Mould '.$code, 'cavity_count' => 4, 'status' => $status]);
    }

    // ---- Create / edit --------------------------------------------------

    public function test_a_mould_is_created_active_and_edited(): void
    {
        $this->manager();

        $mould = $this->postJson('/api/v1/production/molds', ['code' => 'MLD-09', 'name' => '60ML Liquor'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.can.archive', true)
            ->assertJsonPath('data.can.activate', false)
            ->json('data');

        $this->putJson("/api/v1/production/molds/{$mould['id']}", ['cavity_count' => 4])
            ->assertOk()
            ->assertJsonPath('data.cavity_count', 4);
    }

    public function test_a_duplicate_code_is_refused_even_when_the_holder_is_retired(): void
    {
        $this->manager();
        $mould = $this->mould('MLD-01');

        $this->postJson("/api/v1/production/molds/{$mould->id}/archive")->assertOk();

        // DEC-20260817-002 §2 — a retired code stays taken.
        $this->postJson('/api/v1/production/molds', ['code' => 'MLD-01', 'name' => 'Impostor'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    // ---- The three-case status ------------------------------------------

    public function test_archiving_writes_the_retired_case_and_never_a_boolean(): void
    {
        $this->manager();
        $mould = $this->mould();

        $this->postJson("/api/v1/production/molds/{$mould->id}/archive", ['reason' => 'cracked'])
            ->assertOk()
            ->assertJsonPath('data.status', 'retired')
            ->assertJsonPath('data.can.archive', false)
            ->assertJsonPath('data.can.activate', true);

        $this->assertSame('retired', DB::table('molds')->where('id', $mould->id)->value('status'));
    }

    public function test_a_mould_under_repair_is_offered_both_activate_and_archive(): void
    {
        $this->manager();
        $mould = $this->mould('MLD-02', MoldStatus::UnderRepair);

        // Under repair is not active (so it may be activated) and not
        // retired (so it may still be archived). Both, at once.
        $this->getJson("/api/v1/production/molds/{$mould->id}")
            ->assertOk()
            ->assertJsonPath('data.can.activate', true)
            ->assertJsonPath('data.can.archive', true);

        $this->postJson("/api/v1/production/molds/{$mould->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    public function test_a_retired_mould_reactivates(): void
    {
        $this->manager();
        $mould = $this->mould('MLD-03', MoldStatus::Retired);

        $this->postJson("/api/v1/production/molds/{$mould->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    public function test_a_retired_mould_leaves_new_selection_while_history_still_renders(): void
    {
        $this->manager();
        $mould = $this->mould();
        $configurationId = $this->configurationNaming($mould);

        $this->postJson("/api/v1/production/molds/{$mould->id}/archive")->assertOk();

        $active = $this->getJson('/api/v1/production/molds?active=1')->assertOk()->json('data');
        $this->assertSame([], array_column($active, 'code'));

        $all = $this->getJson('/api/v1/production/molds')->assertOk()->json('data');
        $this->assertSame(['MLD-01'], array_column($all, 'code'));

        // History keeps naming it — retiring a mould is not forgetting it.
        $this->assertSame($mould->id, (int) DB::table('production_configurations')->where('id', $configurationId)->value('mold_id'));
    }

    // ---- Delete ---------------------------------------------------------

    public function test_the_list_leaves_delete_undetermined_and_the_single_read_answers_it(): void
    {
        // The N+1 the contract's three-valued `delete` exists to prevent: a
        // list of masters must not pay the dependency sweep per row, so
        // index serves NULL — undetermined, ask — and show is the one place
        // the counts are paid and the answer is authoritative.
        //
        // Asked as the OWNER deliberately: for anyone without the tier the
        // answer is a flat false on both endpoints (mayHardDelete is the
        // first arm of the match), so a manager could never tell the two
        // apart and the pin would be vacuous.
        $this->owner();
        $used = $this->mould('MLD-USED');
        $this->configurationNaming($used);
        $unused = $this->mould('MLD-FREE');

        $rows = collect($this->getJson('/api/v1/production/molds')->assertOk()->json('data'))->keyBy('code');
        $this->assertNull($rows['MLD-USED']['can']['delete']);
        $this->assertNull($rows['MLD-FREE']['can']['delete']);

        $this->getJson("/api/v1/production/molds/{$used->id}")->assertOk()->assertJsonPath('data.can.delete', false);
        $this->getJson("/api/v1/production/molds/{$unused->id}")->assertOk()->assertJsonPath('data.can.delete', true);
    }

    public function test_a_stale_archive_button_is_refused_as_a_business_answer_not_a_crash(): void
    {
        // Two people on one master screen, or one stale tab. The mechanism
        // enforces this with a LogicException, which is a 500 over HTTP;
        // the endpoint answers 422 and names what CAN be done instead.
        $this->manager();
        $mould = $this->mould();

        $this->postJson("/api/v1/production/molds/{$mould->id}/archive")->assertOk();
        $this->postJson("/api/v1/production/molds/{$mould->id}/archive")
            ->assertStatus(422)
            ->assertJsonPath('code', 'configuration_action_unavailable')
            ->assertJsonPath('alternative', 'activate');

        $this->postJson("/api/v1/production/molds/{$mould->id}/activate")->assertOk();
        $this->postJson("/api/v1/production/molds/{$mould->id}/activate")
            ->assertStatus(422)
            ->assertJsonPath('code', 'configuration_action_unavailable')
            ->assertJsonPath('alternative', 'archive');

        // Refused, not half-done: the mould is exactly where it was.
        $this->assertSame('active', $mould->fresh()->status->value);
    }

    public function test_a_module_manager_without_the_tier_may_not_hard_delete_but_may_still_archive(): void
    {
        $this->manager();
        $mould = $this->mould();

        $this->deleteJson("/api/v1/production/molds/{$mould->id}")->assertForbidden();
        $this->assertDatabaseHas('molds', ['id' => $mould->id]);

        $this->getJson("/api/v1/production/molds/{$mould->id}")
            ->assertOk()
            ->assertJsonPath('data.can.delete', false)
            ->assertJsonPath('data.can.archive', true);

        $this->postJson("/api/v1/production/molds/{$mould->id}/archive")->assertOk();
    }

    public function test_deleting_a_referenced_mould_is_refused_and_no_set_null_child_is_blanked(): void
    {
        $this->owner();
        $mould = $this->mould();
        $configurationId = $this->configurationNaming($mould);
        $logId = $this->changeLogNaming($mould);

        $response = $this->deleteJson("/api/v1/production/molds/{$mould->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'configuration_in_use')
            ->assertJsonPath('alternative', 'archive');

        $blocking = collect($response->json('blocking'))->keyBy('code');
        $this->assertSame(1, $blocking['production_configurations']['count']);
        $this->assertSame(1, $blocking['mold_change_logs']['count']);
        $this->assertSame('mould change log', $blocking['mold_change_logs']['label']);

        // THE POINT. Every one of these is ON DELETE SET NULL: had the
        // refusal not held, the rows would still exist and would simply have
        // stopped saying which mould — no error, no cascade, no trace.
        $this->assertDatabaseHas('molds', ['id' => $mould->id]);
        $this->assertSame($mould->id, (int) DB::table('production_configurations')->where('id', $configurationId)->value('mold_id'));
        $this->assertSame($mould->id, (int) DB::table('mold_change_logs')->where('id', $logId)->value('changed_to_mold_id'));
        $this->assertSame($mould->id, (int) DB::table('mold_change_logs')->where('id', $logId)->value('changed_from_mold_id'));
    }

    public function test_a_withdrawn_configuration_still_blocks_the_delete(): void
    {
        $this->owner();
        $mould = $this->mould();
        $configurationId = $this->configurationNaming($mould);

        // production_configurations is the one child in this map that soft
        // deletes, and the mould's FK to it is SET NULL — so nothing implies
        // ->includeTrashed() here. It is declared by hand, and this is what
        // proves it: a withdrawn configuration is still a physical row whose
        // mold_id a delete would blank.
        DB::table('production_configurations')->where('id', $configurationId)->update(['deleted_at' => now()]);

        $this->deleteJson("/api/v1/production/molds/{$mould->id}")
            ->assertStatus(422)
            ->assertJsonPath('blocking.0.code', 'production_configurations');

        $this->assertSame($mould->id, (int) DB::table('production_configurations')->where('id', $configurationId)->value('mold_id'));
    }

    public function test_an_unused_mould_is_really_deleted_and_frees_its_code(): void
    {
        $this->owner();
        $mould = $this->mould('MLD-77');

        $this->deleteJson("/api/v1/production/molds/{$mould->id}")->assertNoContent();
        $this->assertSame(0, Mold::withTrashed()->whereKey($mould->id)->count());

        $this->postJson('/api/v1/production/molds', ['code' => 'MLD-77', 'name' => 'A new mould'])
            ->assertCreated();
    }

    // ---- Audit ----------------------------------------------------------

    public function test_every_lifecycle_act_is_recorded_in_the_configuration_audit(): void
    {
        $user = $this->manager();

        $mould = $this->postJson('/api/v1/production/molds', ['code' => 'MLD-11', 'name' => 'Mould 11'])
            ->assertCreated()->json('data');

        $this->putJson("/api/v1/production/molds/{$mould['id']}", ['name' => 'Mould Eleven'])->assertOk();
        $this->postJson("/api/v1/production/molds/{$mould['id']}/archive")->assertOk();

        $trail = Activity::query()
            ->where('log_name', Mold::CONFIGURATION_LOG_NAME)
            ->where('subject_type', (new Mold)->getMorphClass())
            ->where('subject_id', $mould['id'])
            ->orderBy('id')
            ->get();

        $this->assertSame(['mold.created', 'mold.updated', 'mold.updated'], $trail->pluck('description')->all());
        $this->assertSame([$user->id, $user->id, $user->id], $trail->pluck('causer_id')->map(fn ($id) => (int) $id)->all());

        // The archive is audited as what it really is: the status column
        // moving to the retired CASE, not a boolean going false.
        $archive = json_decode($trail[2]->attribute_changes, true);
        $this->assertSame('retired', $archive['attributes']['status']);
        $this->assertSame('active', $archive['old']['status']);
    }

    // ---- Fixtures -------------------------------------------------------

    private function configurationNaming(Mold $mould): int
    {
        $machine = WorkCenter::firstOrCreate(['code' => 'MC-01'], ['name' => 'Machine 1', 'is_active' => true]);
        $item = Item::firstOrCreate(['sku' => 'BTL-CFG'], ['name' => 'Bottle', 'uom' => 'Nos.', 'is_active' => true]);

        return DB::table('production_configurations')->insertGetId([
            'work_center_id' => $machine->id, 'item_id' => $item->id, 'mold_id' => $mould->id,
            'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function changeLogNaming(Mold $mould): int
    {
        $machine = WorkCenter::firstOrCreate(['code' => 'MC-01'], ['name' => 'Machine 1', 'is_active' => true]);
        $shift = Shift::firstOrCreate(['name' => 'Shift A'], ['start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $item = Item::firstOrCreate(['sku' => 'BTL-CFG'], ['name' => 'Bottle', 'uom' => 'Nos.', 'is_active' => true]);

        // Both columns, because a mould that came OUT is referenced as
        // surely as one that went in — one check counts them OR-ed.
        return DB::table('mold_change_logs')->insertGetId([
            'work_center_id' => $machine->id, 'shift_id' => $shift->id, 'production_date' => '2026-08-01',
            'changed_to_item_id' => $item->id, 'changed_to_mold_id' => $mould->id,
            'changed_from_mold_id' => $mould->id, 'from_time' => '08:00', 'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
