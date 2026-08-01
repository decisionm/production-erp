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
 * The approval chain: pending → pm_approved → approved (accountant) → Tally.
 *
 * The accountant is FINAL — their approval is the posting gate and the only
 * thing that enqueues a voucher. Stages can't be skipped, and rejection from
 * either pre-approval stage sends the entry back to the supervisor.
 */
class ApprovalChainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // This suite pins the PM → accountant chain and its concurrency
        // guards, which predate the quality gate and are unchanged by it.
        // The gate itself — including that it is ON by default, and that
        // turning it off restores exactly this chain — is covered end to end
        // by BatchQualityStageTest.
        config(['production.approvals.quality_stage_enabled' => false]);
    }

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

    public function test_there_is_no_md_approval_step(): void
    {
        // The accountant is final. An MD gate existed as dead code behind an
        // endpoint that could only ever 422, which read on the Approve screen
        // as a stage someone still had to clear. Asserted rather than merely
        // deleted so re-adding one is a deliberate act.
        $this->assertFalse(
            method_exists(ShiftProductionEntryService::class, 'mdApprove'),
            'The accountant is the final approver — there is no MD approval stage.',
        );
        $this->assertNull(
            app('router')->getRoutes()->getByName('md-approve'),
        );

        $paths = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($route) => $route->uri())
            ->filter(fn (string $uri) => str_contains($uri, 'md-approve'));

        $this->assertCount(0, $paths, 'No route may expose an MD approval step.');
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

        // A supervisor (no PM/Accounts role) hits a 403 at both gates — the
        // person who ran the batch cannot approve their own figures.
        $supervisor = User::factory()->create(['is_active' => true]);

        $this->actingAs($supervisor)
            ->postJson("/api/v1/production/shift-production-entries/{$entry->id}/pm-approve")
            ->assertStatus(403);
        $this->actingAs($supervisor)
            ->postJson("/api/v1/production/shift-production-entries/{$entry->id}/accountant-approve")
            ->assertStatus(403);
    }
}
