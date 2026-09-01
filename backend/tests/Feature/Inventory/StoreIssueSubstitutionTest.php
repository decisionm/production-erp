<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StoreIssueLine;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * MATERIAL SUBSTITUTION — the owner's worked example, and nothing wider.
 *
 * DEC-20260901-001: required 6, original actually used 5, alternate used 1,
 * total actual 6. Recorded as TWO lines against ONE requirement, never as one
 * line edited up to 6.
 *
 * WHAT THIS FILE IS REALLY GUARDING is the shape of the record, not the
 * arithmetic. The arithmetic already worked — StoreIssueService has always
 * summed every line that names a request line — and `store_issue_lines`
 * already allowed two rows against one `material_request_line_id`. What did
 * NOT exist was any way to get the second line past validation, and any way
 * to tell afterwards that a substitution had happened rather than a mis-pick.
 *
 * So the two assertions that matter most are the ones that would still pass
 * if someone quietly reverted the decision to a simpler shape:
 *   · the ORIGINAL line still says 5, not 6 — a netted single line of 6
 *     would claim the requirement was met by the original material, which is
 *     the one thing the decision forbids;
 *   · a differing item with NO reason is still refused, exactly as before —
 *     the door opened for a recorded substitution and for nothing else.
 */
class StoreIssueSubstitutionTest extends TestCase
{
    use RefreshDatabase;

    private Item $required;

    private Item $alternate;

    private Warehouse $store;

    private Warehouse $wip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Warehouse::create(['code' => 'RM-STORE', 'name' => 'Raw Material Store', 'is_active' => true]);
        $this->wip = Warehouse::create(['code' => 'WIP', 'name' => 'Work In Progress', 'is_active' => false]);

        // Nos rather than kg, and neither is resin: DEC-20260901-001 expressly
        // does not authorise a resin substitute, so nothing here goes near one.
        $this->required = Item::create(['sku' => 'PKG-CTN-A', 'name' => 'Carton 500ml Type A', 'uom' => 'Nos', 'is_production_input' => true]);
        $this->alternate = Item::create(['sku' => 'PKG-CTN-B', 'name' => 'Carton 500ml Type B', 'uom' => 'Nos', 'is_production_input' => true]);

        foreach ([$this->required, $this->alternate] as $item) {
            app(StockMovementService::class)->recordReceipt(
                itemId: $item->id,
                warehouseId: $this->store->id,
                quantity: '100',
                unitCost: '12',
                reference: 'Opening',
                purpose: StockMovementPurpose::Opening,
            );
        }

        $storeKeeper = User::factory()->create(['is_active' => true]);
        foreach (['inventory.manage', 'production.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $storeKeeper->givePermissionTo(['inventory.manage', 'production.manage']);

        Sanctum::actingAs($storeKeeper);
    }

    /** A submitted request for 6 of the required carton: [requestId, lineId]. */
    private function requestForSix(): array
    {
        $request = $this->postJson('/api/v1/inventory/material-requests', [
            'lines' => [['item_id' => $this->required->id, 'quantity' => '6']],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/inventory/material-requests/{$request['id']}/submit")->assertOk();

        return [$request['id'], $request['lines'][0]['id']];
    }

    // ── The owner's example, end to end ─────────────────────────────────────

    public function test_five_of_the_original_and_one_alternate_close_a_requirement_for_six(): void
    {
        [$requestId, $lineId] = $this->requestForSix();

        $issue = $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $requestId,
            'lines' => [
                [
                    'item_id' => $this->required->id,
                    'quantity' => '5',
                    'material_request_line_id' => $lineId,
                    'quantity_requested' => '6',
                ],
                [
                    'item_id' => $this->alternate->id,
                    'quantity' => '1',
                    'material_request_line_id' => $lineId,
                    'quantity_requested' => '6',
                    'substitution_reason' => 'Type A ran out mid-shift; Type B is the same box.',
                ],
            ],
        ])->assertCreated()->json('data');

        $original = collect($issue['lines'])->firstWhere('item_id', $this->required->id);
        $substitute = collect($issue['lines'])->firstWhere('item_id', $this->alternate->id);

        // THE ORIGINAL LINE STILL SAYS FIVE. This is the assertion the whole
        // decision exists to protect.
        $this->assertSame('5.0000', $original['quantity_issued']);
        $this->assertFalse($original['is_substitution']);
        $this->assertNull($original['substitutes_item_id']);
        $this->assertNull($original['substitution_reason']);

        // The alternate is a line of its own, and it names what it stands for.
        $this->assertSame('1.0000', $substitute['quantity_issued']);
        $this->assertTrue($substitute['is_substitution']);
        $this->assertSame($this->required->id, $substitute['substitutes_item_id']);
        $this->assertSame('Type A ran out mid-shift; Type B is the same box.', $substitute['substitution_reason']);

        // AND THE REQUIREMENT IS CLOSED BY BOTH: 5 + 1 = 6.
        $queued = $this->getJson("/api/v1/inventory/material-requests/{$requestId}")->assertOk()->json('data');
        $this->assertSame('6.0000', $queued['lines'][0]['issued_quantity']);
        $this->assertSame('0.0000', $queued['lines'][0]['remaining_quantity']);
        $this->assertSame('issued', $queued['status']);
    }

    public function test_the_substitution_moves_its_own_material_and_not_the_originals(): void
    {
        [$requestId, $lineId] = $this->requestForSix();

        $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $requestId,
            'lines' => [
                ['item_id' => $this->required->id, 'quantity' => '5', 'material_request_line_id' => $lineId],
                [
                    'item_id' => $this->alternate->id,
                    'quantity' => '1',
                    'material_request_line_id' => $lineId,
                    'substitution_reason' => 'Type A ran out.',
                ],
            ],
        ])->assertCreated();

        // Each item moved its own quantity — a substitution is a real handover
        // of a real different material, not a relabelling of the first.
        $this->assertSame('95.0000', $this->balance($this->required->id, $this->store->id));
        $this->assertSame('5.0000', $this->balance($this->required->id, $this->wip->id));
        $this->assertSame('99.0000', $this->balance($this->alternate->id, $this->store->id));
        $this->assertSame('1.0000', $this->balance($this->alternate->id, $this->wip->id));
    }

    // ── The door opened for exactly one thing ───────────────────────────────

    public function test_a_different_material_with_no_reason_is_still_refused(): void
    {
        [$requestId, $lineId] = $this->requestForSix();

        $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $requestId,
            'lines' => [[
                'item_id' => $this->alternate->id,
                'quantity' => '1',
                'material_request_line_id' => $lineId,
            ]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.item_id']);

        $this->assertSame(0, StoreIssueLine::query()->count(), 'a refused handover writes no line at all');
        $this->assertSame('100.0000', $this->balance($this->alternate->id, $this->store->id));
    }

    public function test_a_blank_reason_is_not_a_reason(): void
    {
        [$requestId, $lineId] = $this->requestForSix();

        $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $requestId,
            'lines' => [[
                'item_id' => $this->alternate->id,
                'quantity' => '1',
                'material_request_line_id' => $lineId,
                'substitution_reason' => '   ',
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors(['lines.0.item_id']);
    }

    public function test_an_ordinary_line_matching_its_request_is_never_marked_a_substitution(): void
    {
        [$requestId, $lineId] = $this->requestForSix();

        // A reason sent on a line that substitutes for nothing is not enough
        // to make it one — the differing ITEM is what makes a substitution.
        $issue = $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $requestId,
            'lines' => [[
                'item_id' => $this->required->id,
                'quantity' => '6',
                'material_request_line_id' => $lineId,
                'substitution_reason' => 'sent by mistake',
            ]],
        ])->assertCreated()->json('data');

        $this->assertFalse($issue['lines'][0]['is_substitution']);
        $this->assertNull($issue['lines'][0]['substitutes_item_id']);
        $this->assertNull($issue['lines'][0]['substitution_reason']);
    }

    public function test_a_handover_against_no_request_substitutes_for_nothing(): void
    {
        // An unsolicited handover has no requirement behind it, so there is
        // nothing it could be standing in for. The reason is ignored, not
        // refused — the line is simply an ordinary issue.
        $issue = $this->postJson('/api/v1/inventory/store-issues', [
            'lines' => [[
                'item_id' => $this->alternate->id,
                'quantity' => '2',
                'substitution_reason' => 'no request behind this',
            ]],
        ])->assertCreated()->json('data');

        $this->assertFalse($issue['lines'][0]['is_substitution']);
        $this->assertNull($issue['lines'][0]['substitutes_item_id']);
    }

    private function balance(int $itemId, int $warehouseId): string
    {
        return (string) StockBalance::query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->value('quantity');
    }
}
