<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\MaterialRequestStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialRequest;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\MaterialRequestService;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 7.5 WS-A — the PRODUCTION MATERIAL REQUEST's own lifecycle:
 * draft → submitted → partially_issued → issued, and cancelled-with-reason
 * from any state that has not finished.
 *
 * WHAT A MATERIAL REQUEST IS NOT. It is not a Procurement purchase
 * requisition (a request to BUY, from a vendor, with money on it) and it is
 * not a consumption. Raising one, submitting one and even fulfilling one
 * moves NO stock out of production as consumed material: an issue puts the
 * material in Production/WIP (DEC-20260817-001) and consumption is a
 * separate, later, calculated event. Nothing in this file writes a stock
 * movement, and that is the point.
 *
 * `can` is computed by MaterialRequestService::abilities and printed by the
 * resource — the same predicate every action enforces, so no screen ever
 * re-derives the state machine.
 */
class MaterialRequestLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Item $carton;

    private Shift $shift;

    private WorkCenter $machine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->carton = Item::create(['sku' => 'PKG-CTN', 'name' => 'Carton 500ml', 'uom' => 'Nos', 'is_production_input' => true]);
        $this->shift = Shift::create(['name' => 'A', 'start_time' => '06:00:00', 'end_time' => '14:00:00', 'is_active' => true]);
        $this->machine = WorkCenter::create(['code' => 'M-01', 'name' => 'Machine 1', 'is_active' => true]);
    }

    public function test_a_request_is_raised_as_a_draft_and_numbered_m_r_id(): void
    {
        $this->actingWith(['production.manage']);

        $response = $this->postJson('/api/v1/inventory/material-requests', [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'notes' => 'Cartons for the 500ml run',
            'lines' => [
                ['item_id' => $this->carton->id, 'quantity' => '40'],
            ],
        ])->assertCreated();

        $id = $response->json('data.id');

        $this->assertSame("MR-{$id}", $response->json('data.request_number'));
        $this->assertSame('draft', $response->json('data.status'));
        $this->assertSame($this->shift->id, $response->json('data.shift_id'));
        $this->assertSame($this->machine->id, $response->json('data.work_center_id'));
        $this->assertNotNull($response->json('data.requested_at'));
        // The unit is the ITEM's own, snapshotted — never typed by the floor.
        $this->assertSame('Nos', $response->json('data.lines.0.uom'));
        $this->assertSame('40.0000', $response->json('data.lines.0.quantity'));
        $this->assertSame('0.0000', $response->json('data.lines.0.issued_quantity'));
        $this->assertSame('40.0000', $response->json('data.lines.0.remaining_quantity'));
        // A draft may be submitted or cancelled; the store cannot issue yet.
        $this->assertSame(
            ['cancel' => true, 'issue' => false, 'submit' => true],
            $this->canOf($response->json('data.can')),
        );
    }

    public function test_the_unit_comes_from_the_item_and_a_unit_typed_by_the_caller_is_ignored(): void
    {
        // FC-03 territory: a tape figure in metres filed as Nos is a
        // different number about a different thing, and that reached live
        // once. So the unit is never something a caller can assert — it is
        // read off the item, whatever the payload says.
        $this->actingWith(['production.manage']);

        $response = $this->postJson('/api/v1/inventory/material-requests', [
            'work_center_id' => $this->machine->id,
            'lines' => [['item_id' => $this->carton->id, 'quantity' => '3', 'uom' => 'Metres']],
        ])->assertCreated();

        $this->assertSame('Nos', $response->json('data.lines.0.uom'));
    }

    public function test_submitting_puts_the_request_in_the_stores_queue(): void
    {
        $this->actingWith(['production.manage']);
        $request = $this->raise();

        $response = $this->postJson("/api/v1/inventory/material-requests/{$request->id}/submit")->assertOk();

        $this->assertSame('submitted', $response->json('data.status'));
        $this->assertNotNull($response->json('data.submitted_at'));
        // Now the store may fulfil it, and it can no longer be re-submitted.
        $this->assertSame(
            ['cancel' => true, 'issue' => true, 'submit' => false],
            $this->canOf($response->json('data.can')),
        );
    }

    public function test_a_request_with_no_lines_cannot_be_submitted(): void
    {
        $this->actingWith(['production.manage']);
        $request = MaterialRequest::create(['status' => MaterialRequestStatus::Draft, 'requested_at' => now()]);

        $this->postJson("/api/v1/inventory/material-requests/{$request->id}/submit")
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'no lines'));
    }

    public function test_submitting_twice_is_refused(): void
    {
        $this->actingWith(['production.manage']);
        $request = $this->raise();

        $this->postJson("/api/v1/inventory/material-requests/{$request->id}/submit")->assertOk();
        $this->postJson("/api/v1/inventory/material-requests/{$request->id}/submit")->assertStatus(422);
    }

    public function test_a_part_issue_moves_it_to_partially_issued_and_a_full_one_to_issued(): void
    {
        $this->actingWith(['production.manage']);
        $request = $this->raise();
        $this->postJson("/api/v1/inventory/material-requests/{$request->id}/submit")->assertOk();

        $service = app(MaterialRequestService::class);
        $line = $request->lines()->first();

        $service->applyIssuedQuantities($request->fresh(), [$line->id => '15']);

        $show = $this->getJson("/api/v1/inventory/material-requests/{$request->id}")->assertOk();
        $this->assertSame('partially_issued', $show->json('data.status'));
        $this->assertSame('15.0000', $show->json('data.lines.0.issued_quantity'));
        $this->assertSame('25.0000', $show->json('data.lines.0.remaining_quantity'));
        // Still fulfillable, still cancellable (the REMAINDER is withdrawn,
        // never the fifteen already standing in production).
        $this->assertSame(
            ['cancel' => true, 'issue' => true, 'submit' => false],
            $this->canOf($show->json('data.can')),
        );

        $service->applyIssuedQuantities($request->fresh(), [$line->id => '25']);

        $show = $this->getJson("/api/v1/inventory/material-requests/{$request->id}")->assertOk();
        $this->assertSame('issued', $show->json('data.status'));
        $this->assertSame('0.0000', $show->json('data.lines.0.remaining_quantity'));
        $this->assertSame(
            ['cancel' => false, 'issue' => false, 'submit' => false],
            $this->canOf($show->json('data.can')),
        );
    }

    public function test_the_store_may_hand_over_more_than_was_asked_for_and_remaining_floors_at_zero(): void
    {
        // A bag is not divisible. Asking for 20 kg and being given a 25 kg
        // bag is the ordinary case, not an error — refusing it would stop
        // the floor. The overage is visible, and it is still not a
        // consumption.
        $this->actingWith(['production.manage']);
        $request = $this->raise();
        $this->postJson("/api/v1/inventory/material-requests/{$request->id}/submit")->assertOk();

        $line = $request->lines()->first();
        app(MaterialRequestService::class)->applyIssuedQuantities($request->fresh(), [$line->id => '55']);

        $show = $this->getJson("/api/v1/inventory/material-requests/{$request->id}")->assertOk();
        $this->assertSame('55.0000', $show->json('data.lines.0.issued_quantity'));
        $this->assertSame('0.0000', $show->json('data.lines.0.remaining_quantity'));
        $this->assertSame('issued', $show->json('data.status'));
    }

    public function test_cancelling_records_who_why_and_when(): void
    {
        $user = $this->actingWith(['production.manage']);
        $request = $this->raise();

        $response = $this->postJson("/api/v1/inventory/material-requests/{$request->id}/cancel", [
            'reason' => 'Run cancelled — mold change',
        ])->assertOk();

        $this->assertSame('cancelled', $response->json('data.status'));
        $this->assertSame('Run cancelled — mold change', $response->json('data.cancelled_reason'));
        $this->assertSame($user->id, $response->json('data.cancelled_by'));
        $this->assertNotNull($response->json('data.cancelled_at'));
        $this->assertSame(
            ['cancel' => false, 'issue' => false, 'submit' => false],
            $this->canOf($response->json('data.can')),
        );
    }

    public function test_cancelling_without_a_reason_is_refused(): void
    {
        $this->actingWith(['production.manage']);
        $request = $this->raise();

        $this->postJson("/api/v1/inventory/material-requests/{$request->id}/cancel", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_a_fully_issued_request_can_no_longer_be_cancelled(): void
    {
        $this->actingWith(['production.manage']);
        $request = $this->raise();
        $this->postJson("/api/v1/inventory/material-requests/{$request->id}/submit")->assertOk();
        app(MaterialRequestService::class)->applyIssuedQuantities($request->fresh(), [$request->lines()->first()->id => '40']);

        $this->postJson("/api/v1/inventory/material-requests/{$request->id}/cancel", ['reason' => 'too late'])
            ->assertStatus(422);
    }

    public function test_a_cancelled_request_cannot_be_submitted_or_cancelled_again(): void
    {
        $this->actingWith(['production.manage']);
        $request = $this->raise();
        $this->postJson("/api/v1/inventory/material-requests/{$request->id}/cancel", ['reason' => 'no longer needed'])->assertOk();

        $this->postJson("/api/v1/inventory/material-requests/{$request->id}/submit")->assertStatus(422);
        $this->postJson("/api/v1/inventory/material-requests/{$request->id}/cancel", ['reason' => 'again'])->assertStatus(422);
    }

    public function test_the_surface_is_append_only_no_put_and_no_delete(): void
    {
        $this->actingWith(['production.manage', 'inventory.manage']);
        $request = $this->raise();

        $this->putJson("/api/v1/inventory/material-requests/{$request->id}", ['notes' => 'edited'])->assertStatus(405);
        $this->deleteJson("/api/v1/inventory/material-requests/{$request->id}")->assertStatus(405);
    }

    public function test_raising_a_request_writes_no_stock_movement(): void
    {
        $this->actingWith(['production.manage']);
        $request = $this->raise();
        $this->postJson("/api/v1/inventory/material-requests/{$request->id}/submit")->assertOk();

        $this->assertSame(0, StockMovement::query()->count());
    }

    // ---- helpers -----------------------------------------------------------

    private function raise(): MaterialRequest
    {
        $id = $this->postJson('/api/v1/inventory/material-requests', [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'lines' => [['item_id' => $this->carton->id, 'quantity' => '40']],
        ])->assertCreated()->json('data.id');

        return MaterialRequest::findOrFail($id);
    }

    /** @param  array<string, bool>  $can */
    private function canOf(?array $can): array
    {
        $can ??= [];
        ksort($can);

        return $can;
    }

    /** @param  list<string>  $permissions */
    private function actingWith(array $permissions): User
    {
        $this->app['auth']->forgetGuards();

        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }
}
