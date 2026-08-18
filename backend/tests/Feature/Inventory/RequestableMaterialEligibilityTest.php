<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialRequest;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Services\FactoryWarehouseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * WHAT THE FLOOR MAY ASK THE STORE FOR.
 *
 * The defect this pins, reported from live on 18-Aug-2026: the material picker
 * was fed `GET /inventory/items?per_page=1000` — the WHOLE item master — so a
 * finished good was offered as a requestable INPUT. The owner named the case:
 * a 1 Litre PET Bottle is a production TARGET and must never be a requestable
 * material unless it is independently configured as one.
 *
 * TWO THINGS THIS FILE INSISTS ON.
 *
 * 1 · The rule is CONFIGURATION, not a heuristic. Eligibility is
 *     `items.is_production_input`. It is deliberately not the unit of measure,
 *     which was the only classification this database used to carry and which
 *     is wrong in both directions: a cap is `Nos.` and IS an input; packing
 *     film is kg and is not resin. It is not the name or the SKU either — this
 *     repository already learned that lesson once and wrote it down in
 *     Item.php: "THE COLUMN DECIDES, NOT THE SKU."
 *
 * 2 · The API enforces it, not the dropdown. Every test that matters here goes
 *     through HTTP. Filtering the React list alone would have left the defect
 *     fully exploitable by anything that posts directly, which is precisely
 *     what the owner asked to be prevented.
 *
 * Eligibility is NOT the machine question. Whether a material may name a work
 * centre is a separate, owner-backed refusal (FC-01 / DEC-20260807-006), pinned
 * in MaterialRequestCommonInputRefusalTest and deliberately left alone here.
 */
class RequestableMaterialEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private Item $resin;

    private Item $cap;

    private Item $finishedGood;

    private Item $retiredMaterial;

    private Item $unrelated;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingWith(['production.manage', 'inventory.manage']);

        // A production material measured in kg — the ordinary case.
        $this->resin = Item::create([
            'sku' => 'RM-PET', 'name' => 'Relpet PET Resin', 'uom' => 'Kgs',
            'is_active' => true, 'is_production_input' => true,
        ]);

        // A production material measured in Nos. It is here because it is the
        // counter-example that kills any unit-of-measure rule: it must be
        // offered, and a uom rule would drop it.
        $this->cap = Item::create([
            'sku' => 'CAP-28', 'name' => '28mm Cap', 'uom' => 'Nos.',
            'is_active' => true, 'is_production_input' => true,
        ]);

        // The owner's own example: a production OUTPUT.
        $this->finishedGood = Item::create([
            'sku' => 'BTL-PET-1000', 'name' => '1 Litre PET Bottle', 'uom' => 'pcs',
            'is_active' => true,
        ]);

        // Configured as a material once, since retired.
        $this->retiredMaterial = Item::create([
            'sku' => 'RM-OLD', 'name' => 'Discontinued Masterbatch', 'uom' => 'Kgs',
            'is_active' => false, 'is_production_input' => true,
        ]);

        // A saleable/unrelated Tally item nobody configured for production.
        $this->unrelated = Item::create([
            'sku' => 'SVC-FREIGHT', 'name' => 'Outward Freight', 'uom' => 'Nos.',
            'is_active' => true,
        ]);

        // The two locations a handover moves between, named rather than
        // guessed at, plus opening stock seeded THROUGH THE LEDGER so the
        // balance/movement invariant is not broken before a test starts.
        $store = Warehouse::create(['code' => 'EL-RM', 'name' => 'EL Raw Material Store', 'is_active' => true, 'tally_guid' => 'el-gd']);
        $wip = Warehouse::create(['code' => 'EL-WIP', 'name' => 'EL Production WIP', 'is_active' => false]);
        app(ProductionWipLocationResolver::class)->setWarehouseId($wip->id);
        app(FactoryWarehouseResolver::class)->setRawMaterialWarehouseId($store->id);

        app(StockMovementService::class)->recordReceipt(
            itemId: $this->cap->id,
            warehouseId: $store->id,
            quantity: '5000',
            unitCost: '1.00',
            reference: 'EL opening',
        );
    }

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

    /** @return list<string> the SKUs the picker offers */
    private function offered(): array
    {
        return collect($this->getJson('/api/v1/inventory/requestable-materials')->assertOk()->json('data'))
            ->pluck('sku')
            ->all();
    }

    public function test_a_production_material_is_offered(): void
    {
        $offered = $this->offered();

        $this->assertContains('RM-PET', $offered);
        // And the Nos-measured one too — eligibility is not the unit.
        $this->assertContains('CAP-28', $offered, 'A cap is a production input measured in Nos. A unit-of-measure rule would have dropped it.');
    }

    public function test_a_finished_good_is_not_offered(): void
    {
        $this->assertNotContains(
            'BTL-PET-1000',
            $this->offered(),
            'A production output was offered as a requestable input — this is the defect reported from live.',
        );
    }

    public function test_an_inactive_production_material_is_not_offered(): void
    {
        $this->assertNotContains('RM-OLD', $this->offered());
    }

    public function test_an_unrelated_item_is_not_offered(): void
    {
        $this->assertNotContains('SVC-FREIGHT', $this->offered());
    }

    public function test_the_picker_offers_nothing_it_has_not_been_configured_to_offer(): void
    {
        // Stated as a whole-set equality rather than four absences, so a new
        // item added to this fixture cannot slip into the picker unnoticed.
        $this->assertEqualsCanonicalizing(['CAP-28', 'RM-PET'], $this->offered());
    }

    /**
     * THE ONE THAT MAKES THE OTHERS MEAN SOMETHING. Filtering a dropdown is a
     * suggestion; this is the rule.
     */
    public function test_the_api_refuses_an_ineligible_material_posted_directly(): void
    {
        foreach ([$this->finishedGood, $this->unrelated, $this->retiredMaterial] as $ineligible) {
            $this->postJson('/api/v1/inventory/material-requests', [
                'lines' => [['item_id' => $ineligible->id, 'quantity' => '10']],
            ])->assertStatus(422)->assertJsonValidationErrors('lines.0.item_id');
        }

        $this->assertSame(0, MaterialRequest::query()->count(), 'A refused request must not be half-written.');
    }

    public function test_an_eligible_material_posted_directly_is_still_accepted(): void
    {
        // The guard must guard, not merely refuse everything.
        $this->postJson('/api/v1/inventory/material-requests', [
            'lines' => [['item_id' => $this->cap->id, 'quantity' => '500']],
        ])->assertStatus(201);
    }

    /**
     * HISTORY MUST NOT BE REWRITTEN BY A LATER CONFIGURATION CHANGE.
     *
     * A request naming a material that is afterwards archived — or struck off
     * the eligible list entirely — still shows what was actually asked for.
     * This works because the line resolves its item by id through the
     * relation, never through the eligible list.
     */
    public function test_a_historical_request_still_shows_a_material_archived_since(): void
    {
        $created = $this->postJson('/api/v1/inventory/material-requests', [
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '250']],
        ])->assertStatus(201)->json('data.id');

        // The factory retires it afterwards, both ways.
        $this->resin->update(['is_active' => false, 'is_production_input' => false]);

        $line = $this->getJson("/api/v1/inventory/material-requests/{$created}")
            ->assertOk()
            ->json('data.lines.0');

        $this->assertSame('RM-PET', $line['item']['sku']);
        $this->assertSame('Relpet PET Resin', $line['item']['name']);

        // ...and it is genuinely off the offer list now, so the two behaviours
        // are proved to be independent rather than both merely passing.
        $this->assertNotContains('RM-PET', $this->offered());
    }

    /**
     * THE REGRESSION AN ADVERSARIAL REVIEW CAUGHT, and the reason the two
     * sides of the flow are gated differently.
     *
     * The request side gates a NEW ask. The issue side FULFILS one that was
     * already accepted. If the issue side re-gated eligibility, then switching
     * a material off — which Q56 explicitly invites the owner to do while
     * pruning the over-broad backfill — would refuse a handover for material
     * that is already on the trolley against an open, perfectly good request.
     * The store would be stuck at the window with no way to record what it
     * just handed over.
     *
     * History must stay ISSUABLE, not merely readable. The previous version of
     * this file tested only that such a request still RENDERS.
     */
    public function test_a_handover_against_an_accepted_request_survives_the_material_being_switched_off(): void
    {
        $request = $this->postJson('/api/v1/inventory/material-requests', [
            'lines' => [['item_id' => $this->cap->id, 'quantity' => '500']],
        ])->assertStatus(201)->json('data');

        $this->postJson("/api/v1/inventory/material-requests/{$request['id']}/submit")->assertOk();

        // The owner prunes the eligible list AFTER the floor asked.
        $this->cap->update(['is_production_input' => false]);

        // The store can still record the handover it actually made.
        $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $request['id'],
            'lines' => [[
                'material_request_line_id' => $request['lines'][0]['id'],
                'item_id' => $this->cap->id,
                'quantity' => '500',
            ]],
        ])->assertStatus(201);
    }

    /**
     * The other half of the same rule: honouring the earlier decision does not
     * mean accepting anything at all. A line that names a request line must
     * hand over the material THAT LINE asked for.
     *
     * This also closes a hole that predates the eligibility work — nothing
     * cross-checked the two, so an issue could name item X against a request
     * line for item Y and the fulfilment would credit Y.
     */
    public function test_a_handover_may_not_swap_the_material_the_request_asked_for(): void
    {
        $request = $this->postJson('/api/v1/inventory/material-requests', [
            'lines' => [['item_id' => $this->cap->id, 'quantity' => '500']],
        ])->assertStatus(201)->json('data');

        $this->postJson("/api/v1/inventory/material-requests/{$request['id']}/submit")->assertOk();

        $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $request['id'],
            'lines' => [[
                'material_request_line_id' => $request['lines'][0]['id'],
                'item_id' => $this->resin->id, // NOT what was asked for
                'quantity' => '500',
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.item_id');
    }

    /**
     * THE BYPASS THE EXEMPTION CREATED, closed and pinned.
     *
     * Honouring an earlier decision means skipping the eligibility rule, and
     * that skip is only justified if the earlier decision EXISTS. Quoting a
     * made-up request line id would otherwise have waved a finished good
     * straight past the rule — the exemption branch ends in `continue`.
     */
    public function test_an_invented_request_line_id_does_not_wave_an_ineligible_item_through(): void
    {
        $this->postJson('/api/v1/inventory/store-issues', [
            'lines' => [[
                'material_request_line_id' => 987654, // no such line
                'item_id' => $this->finishedGood->id,
                'quantity' => '5',
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.material_request_line_id');
    }

    /**
     * A WITHDRAWN ASK IS NOT AN EARLIER DECISION TO HONOUR. "History stays
     * issuable" is right for a request the store still owes; a cancelled one
     * is not owed, and letting it exempt its item would leave a permanent
     * loophole attached to a dead row.
     */
    public function test_a_cancelled_request_cannot_be_issued_against(): void
    {
        $request = $this->postJson('/api/v1/inventory/material-requests', [
            'lines' => [['item_id' => $this->cap->id, 'quantity' => '500']],
        ])->assertStatus(201)->json('data');

        $this->postJson("/api/v1/inventory/material-requests/{$request['id']}/cancel", [
            'reason' => 'The run was pulled.',
        ])->assertOk();

        $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $request['id'],
            'lines' => [[
                'material_request_line_id' => $request['lines'][0]['id'],
                'item_id' => $this->cap->id,
                'quantity' => '500',
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.material_request_line_id');
    }

    /**
     * The line must belong to the request the issue names. Otherwise stock
     * moves, the pointer is stored, and the request goes on showing the full
     * quantity owed for ever — against work already done.
     */
    public function test_a_line_may_not_name_a_request_line_from_a_different_request(): void
    {
        $one = $this->postJson('/api/v1/inventory/material-requests', [
            'lines' => [['item_id' => $this->cap->id, 'quantity' => '500']],
        ])->assertStatus(201)->json('data');
        $two = $this->postJson('/api/v1/inventory/material-requests', [
            'lines' => [['item_id' => $this->cap->id, 'quantity' => '200']],
        ])->assertStatus(201)->json('data');

        $this->postJson("/api/v1/inventory/material-requests/{$one['id']}/submit")->assertOk();

        $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $one['id'],
            'lines' => [[
                'material_request_line_id' => $two['lines'][0]['id'], // the OTHER request
                'item_id' => $this->cap->id,
                'quantity' => '200',
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.material_request_line_id');
    }

    public function test_the_store_issue_side_refuses_the_same_ineligible_material(): void
    {
        // The two halves of the flow must not disagree about what a material
        // is. The issue side used to carry a bare `exists:items,id` — neither
        // the soft-delete guard nor the is_active guard the request side had.
        $this->postJson('/api/v1/inventory/store-issues', [
            'lines' => [['item_id' => $this->finishedGood->id, 'quantity' => '5']],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.item_id');
    }
}
