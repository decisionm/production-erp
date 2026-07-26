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
use Tests\TestCase;

/**
 * The 4-stage approval chain (factory answer 9): pending → pm_approved →
 * accountant_approved → approved (MD) → Tally. Stages can't be skipped, only
 * the MD's final approval enqueues a voucher, and rejection from any pre-MD
 * stage sends the entry back.
 */
class ApprovalChainTest extends TestCase
{
    use RefreshDatabase;

    private function submittedEntry(): ShiftProductionEntry
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
        $item = Item::create(['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'NOS']);
        $warehouse = Warehouse::create(['code' => 'WH-1', 'name' => 'Store']);

        return ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => '2026-07-25',
            'batch_status' => BatchStatus::Completed,
            'quantity_produced' => '1000',
            'quantity_scrap' => '0',
            'status' => ShiftProductionEntryStatus::Pending,
        ]);
    }

    public function test_the_chain_advances_in_order_and_accountant_approval_posts(): void
    {
        $entry = $this->submittedEntry();
        $service = app(ShiftProductionEntryService::class);
        $pm = User::factory()->create();
        $accountant = User::factory()->create();

        $entry = $service->pmApprove($entry, $pm->id);
        $this->assertSame(ShiftProductionEntryStatus::PmApproved, $entry->status);
        $this->assertSame($pm->id, $entry->getRawOriginal('plant_manager_signed_by'));
        $this->assertSame(0, TallySyncEntry::count(), 'PM approval must not enqueue');

        // The accountant's approval IS the posting gate (team decision
        // 2026-07-26): entry goes straight to approved and enqueues.
        $entry = $service->accountantApprove($entry, $accountant->id);
        $this->assertSame(ShiftProductionEntryStatus::Approved, $entry->status);
        $this->assertSame($accountant->id, $entry->getRawOriginal('accountant_signed_by'));
        $this->assertSame(1, TallySyncEntry::count(), 'Accountant approval enqueues exactly one voucher');
    }

    public function test_stages_cannot_be_skipped(): void
    {
        $entry = $this->submittedEntry();
        $service = app(ShiftProductionEntryService::class);
        $user = User::factory()->create();

        // Straight to accountant from pending (PM not done) — blocked.
        $this->expectException(InvalidStatusTransitionException::class);
        $service->accountantApprove($entry, $user->id);
    }

    public function test_dormant_md_path_rejects_entries_not_routed_to_it(): void
    {
        // mdApprove only accepts accountant_approved — a state the normal
        // flow no longer produces (reserved for future "big approvals").
        $entry = $this->submittedEntry();
        $service = app(ShiftProductionEntryService::class);
        $user = User::factory()->create();

        $this->expectException(InvalidStatusTransitionException::class);
        $service->mdApprove($entry, $user->id);
    }

    public function test_rejection_from_a_middle_stage_sends_it_back_and_never_enqueues(): void
    {
        $entry = $this->submittedEntry();
        $service = app(ShiftProductionEntryService::class);
        $user = User::factory()->create();

        $entry = $service->pmApprove($entry, $user->id);
        $entry = $service->reject($entry, $user->id, 'Figures do not match the paper report');

        $this->assertSame(ShiftProductionEntryStatus::Rejected, $entry->status);
        $this->assertSame('Figures do not match the paper report', $entry->rejection_reason);
        $this->assertSame(0, TallySyncEntry::count());
    }

    public function test_role_gates_on_the_endpoints(): void
    {
        $entry = $this->submittedEntry();

        // A supervisor (no PM/Accounts/Administrator role) hits a 403 at every gate.
        $supervisor = User::factory()->create(['is_active' => true]);

        $this->actingAs($supervisor)
            ->postJson("/api/v1/production/shift-production-entries/{$entry->id}/pm-approve")
            ->assertStatus(403);
        $this->actingAs($supervisor)
            ->postJson("/api/v1/production/shift-production-entries/{$entry->id}/accountant-approve")
            ->assertStatus(403);
        $this->actingAs($supervisor)
            ->postJson("/api/v1/production/shift-production-entries/{$entry->id}/md-approve")
            ->assertStatus(403);
    }
}
