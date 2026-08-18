<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialRequest;
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
