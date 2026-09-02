<?php

namespace Tests\Feature;

use App\Exceptions\InvalidStatusTransitionException;
use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ShiftProductionEntryService;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * FOUR EYES on the approval chain: the accountant who posts a shift to the
 * books must not be the plant manager who verified it. Two gates are only
 * two gates if two people clear them — otherwise one account signs a shift
 * into Tally alone and the audit trail records the same name twice.
 *
 * Relaxable ONLY by production.approvals.allow_same_user, for a genuine
 * one-person office. There is deliberately no Administrator exemption, and
 * that is asserted here rather than merely omitted, so re-adding one is a
 * deliberate act.
 */
class FourEyesApprovalTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // The four-eyes rule at the ACCOUNTANT gate is what this suite pins.
        // The quality gate's own four-eyes check (the checker may not be the
        // supervisor who counted the batch) is the same rule at a different
        // desk and is pinned in BatchQualityStageTest; switching the stage
        // off here keeps each test about one gate.
        config(['production.approvals.quality_stage_enabled' => false]);
    }

    private function submittedEntry(): ShiftProductionEntry
    {
        $n = ++self::$seq;
        $shift = Shift::create(['name' => "Morning {$n}", 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => "MC-0{$n}", 'name' => "Machine {$n}"]);
        $item = Item::create(['sku' => "BTL-{$n}", 'name' => 'Bottle', 'uom' => 'NOS']);
        $warehouse = Warehouse::create(['code' => "WH-{$n}", 'name' => 'Store']);

        return ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => '2026-07-30',
            'batch_status' => BatchStatus::Completed,
            'quantity_produced' => '1000',
            'quantity_scrap' => '0',
            'status' => ShiftProductionEntryStatus::Pending,
        ]);
    }

    /**
     * A completed entry whose quality check has already been recorded, by
     * $checker — the shared shape the third-comparison tests build on. Not
     * an extraction from the two other tests below (they never record a
     * quality check — this suite runs with the quality stage switched off),
     * so it is new setup, not a moved one.
     *
     * @return array{0: ShiftProductionEntry, 1: User}
     */
    private function completedEntryCheckedBy(): array
    {
        $entry = $this->submittedEntry();

        foreach (['production.manage', 'quality.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $checker = User::factory()->create(['is_active' => true]);
        $checker->givePermissionTo(['production.manage', 'quality.manage']);

        $entry->forceFill([
            'quality_checked_by' => $checker->id,
            'quality_checked_at' => now(),
        ])->save();

        return [$entry->fresh(), $checker];
    }

    public function test_one_account_cannot_clear_both_gates(): void
    {
        $entry = $this->submittedEntry();
        $service = app(ShiftProductionEntryService::class);
        $solo = User::factory()->create();

        $entry = $service->pmApprove($entry, $solo->id);

        try {
            $service->accountantApprove($entry->fresh(), $solo->id);
            $this->fail('The same account cleared both approval gates.');
        } catch (InvalidStatusTransitionException $e) {
            // The message is asserted, not just the class: this exception is
            // shared with the tolerance gate, so a class-only assertion would
            // pass for entirely the wrong reason.
            $this->assertSame('the same person cannot give both approvals', $e->getMessage());
        }

        // Refused means REFUSED: still at pm_approved, still unposted.
        $fresh = $entry->fresh();
        $this->assertSame(ShiftProductionEntryStatus::PmApproved, $fresh->status);
        $this->assertNull($fresh->getRawOriginal('accountant_signed_by'));
        $this->assertSame(0, TallySyncEntry::count(), 'A refused approval must never enqueue a voucher.');
    }

    public function test_two_different_people_clear_the_chain_normally(): void
    {
        $entry = $this->submittedEntry();
        $service = app(ShiftProductionEntryService::class);
        $pm = User::factory()->create();
        $accountant = User::factory()->create();

        $entry = $service->pmApprove($entry, $pm->id);
        $approved = $service->accountantApprove($entry->fresh(), $accountant->id);

        $this->assertSame(ShiftProductionEntryStatus::Approved, $approved->status);
        $this->assertSame($pm->id, $approved->getRawOriginal('plant_manager_signed_by'));
        $this->assertSame($accountant->id, $approved->getRawOriginal('accountant_signed_by'));
        $this->assertSame(1, TallySyncEntry::count());
    }

    public function test_the_config_flag_relaxes_it_for_a_one_person_office(): void
    {
        config(['production.approvals.allow_same_user' => true]);

        $entry = $this->submittedEntry();
        $service = app(ShiftProductionEntryService::class);
        $solo = User::factory()->create();

        $entry = $service->pmApprove($entry, $solo->id);
        $approved = $service->accountantApprove($entry->fresh(), $solo->id);

        $this->assertSame(ShiftProductionEntryStatus::Approved, $approved->status);
        $this->assertSame($solo->id, $approved->getRawOriginal('plant_manager_signed_by'));
        $this->assertSame($solo->id, $approved->getRawOriginal('accountant_signed_by'));
        $this->assertSame(1, TallySyncEntry::count());
    }

    /** DEC-20260902-010: the third comparison — checker vs plant manager. */
    public function test_the_quality_checker_cannot_approve_as_plant_manager(): void
    {
        // completedEntryCheckedBy() — completed entry, quality check already
        // recorded by $checker.
        [$entry, $checker] = $this->completedEntryCheckedBy();

        $checker->assignRole(Role::findOrCreate('Plant Manager', 'web'));
        Sanctum::actingAs($checker);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/pm-approve")
            ->assertStatus(422)
            ->assertJsonPath('message', 'the person who checked quality cannot approve the same batch as plant manager');

        $this->assertDatabaseHas('shift_production_entries', ['id' => $entry->id, 'status' => 'pending', 'plant_manager_signed_by' => null]);
    }

    public function test_the_flag_relaxes_the_checker_comparison_too(): void
    {
        config()->set('production.approvals.allow_same_user', true);
        [$entry, $checker] = $this->completedEntryCheckedBy();

        $checker->assignRole(Role::findOrCreate('Plant Manager', 'web'));
        Sanctum::actingAs($checker);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/pm-approve")->assertOk();
    }

    public function test_the_flag_defaults_to_off(): void
    {
        // The rule has to be ON without anyone editing .env — a control that
        // ships disabled is a control nobody has.
        $this->assertFalse(
            (bool) config('production.approvals.allow_same_user'),
            'Four-eyes must be enforced by default; relaxing it is the deliberate act.',
        );
    }

    public function test_an_administrator_is_not_exempt(): void
    {
        // The obvious next thought, refused deliberately: in this deployment
        // everyone who approves holds the Administrator role, so exempting it
        // would leave the rule binding nobody who could break it.
        $entry = $this->submittedEntry();
        $service = app(ShiftProductionEntryService::class);

        Role::findOrCreate('Administrator', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');

        $entry = $service->pmApprove($entry, $admin->id);

        $this->expectException(InvalidStatusTransitionException::class);
        $this->expectExceptionMessage('the same person cannot give both approvals');
        $service->accountantApprove($entry->fresh(), $admin->id);
    }

    public function test_a_wrong_status_call_still_reports_the_status_not_the_identity(): void
    {
        // Straight to the accountant with no PM signature: the real problem
        // is the skipped stage, and that is what the message must say.
        $entry = $this->submittedEntry();
        $service = app(ShiftProductionEntryService::class);
        $user = User::factory()->create();

        try {
            $service->accountantApprove($entry, $user->id);
            $this->fail('A pending entry was approved without the PM stage.');
        } catch (InvalidStatusTransitionException $e) {
            $this->assertStringContainsString('Cannot transition', $e->getMessage());
            $this->assertStringNotContainsString('same person', $e->getMessage());
        }
    }

    public function test_a_stale_model_cannot_smuggle_the_same_approver_through(): void
    {
        // The caller's copy predates the PM's approval, so its in-memory
        // plant_manager_signed_by is null. Reading identity off the model
        // instead of the row would wave through exactly the case this
        // refuses — hence the fresh read in accountantApprove().
        $entry = $this->submittedEntry();
        $service = app(ShiftProductionEntryService::class);
        $solo = User::factory()->create();

        $stale = ShiftProductionEntry::findOrFail($entry->id);
        $service->pmApprove($entry, $solo->id);
        $this->assertNull($stale->getRawOriginal('plant_manager_signed_by'), 'fixture must be stale');

        $this->expectException(InvalidStatusTransitionException::class);
        $this->expectExceptionMessage('the same person cannot give both approvals');
        $service->accountantApprove($stale, $solo->id);
    }

    public function test_the_endpoint_refuses_an_administrator_with_a_plain_422(): void
    {
        // END TO END, and the sharpest form of the rule. The controller lets
        // an Administrator act at BOTH desks by role ("Administrator can act
        // at any stage"), which is exactly how one account could sign a shift
        // into the books alone. Role says yes at both gates; four-eyes still
        // says no, in a sentence an accountant can read.
        $entry = $this->submittedEntry();
        $solo = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.manage', 'web');
        $solo->givePermissionTo('production.manage');
        Role::findOrCreate('Administrator', 'web');
        $solo->assignRole('Administrator');

        $this->actingAs($solo)
            ->postJson("/api/v1/production/shift-production-entries/{$entry->id}/pm-approve")
            ->assertOk();

        $this->actingAs($solo)
            ->postJson("/api/v1/production/shift-production-entries/{$entry->id}/accountant-approve")
            ->assertStatus(422)
            ->assertJson(['message' => 'the same person cannot give both approvals']);

        $this->assertSame(ShiftProductionEntryStatus::PmApproved, $entry->fresh()->status);
    }
}
