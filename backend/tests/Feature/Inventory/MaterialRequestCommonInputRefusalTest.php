<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialRequest;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * FC-01 AT THE REQUEST DESK — a resin request names NO machine and no area.
 *
 * The factory has ONE common resin loading point, crane-fed and piped to all
 * ten machines (DEC-20260807-006, reaffirming FC-01). A request that asks
 * the store for resin "for machine 3" would be describing a factory that
 * does not exist, and it would put a bag-to-machine claim into the ledger
 * the moment the store fulfilled it. So the request is REFUSED — not
 * silently stripped of its machine, which would file the request under a
 * different meaning than the person typed.
 *
 * The opposite guardrail is equally load-bearing and is pinned here too:
 * film, cartons and tape ARE machine-specific, and a consumable request
 * carrying a work centre must go through untouched.
 *
 * The signal for "common input" is the item's kg-family unit — the same
 * predicate FactoryDayBinService's own raw-material reads use
 * (Item::KG_UOM_VARIANTS). This database has no other classification.
 */
class MaterialRequestCommonInputRefusalTest extends TestCase
{
    use RefreshDatabase;

    private Item $resin;

    private Item $masterbatch;

    private Item $carton;

    private WorkCenter $machine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingWith(['production.manage']);

        $this->resin = Item::create(['sku' => 'RM-PET', 'name' => 'Relpet PET Resin', 'uom' => 'Kgs', 'is_production_input' => true]);
        $this->masterbatch = Item::create(['sku' => 'RM-MB-BLU', 'name' => 'Blue Masterbatch', 'uom' => 'kg', 'is_production_input' => true]);
        $this->carton = Item::create(['sku' => 'PKG-CTN', 'name' => 'Carton 500ml', 'uom' => 'Nos', 'is_production_input' => true]);
        $this->machine = WorkCenter::create(['code' => 'M-03', 'name' => 'Machine 3', 'is_active' => true]);
    }

    public function test_a_resin_request_naming_a_machine_is_refused_and_the_refusal_cites_the_rule(): void
    {
        $response = $this->postJson('/api/v1/inventory/material-requests', [
            'work_center_id' => $this->machine->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '250']],
        ])->assertStatus(422);

        $message = $response->json('message');

        $this->assertStringContainsString('FC-01', $message);
        $this->assertStringContainsString('DEC-20260807-006', $message);
        $this->assertStringContainsString('Relpet PET Resin', $message);
        // Nothing was written — a refusal is not a half-saved request.
        $this->assertSame(0, MaterialRequest::query()->count());
    }

    public function test_the_refusal_holds_for_masterbatch_and_for_every_kg_family_spelling(): void
    {
        foreach (['Kgs', 'Kgs.', 'KGS', 'kg', 'kg.'] as $index => $spelling) {
            $item = Item::create(['sku' => "RM-SPELL-{$index}", 'name' => "Raw {$spelling}", 'uom' => $spelling, 'is_production_input' => true]);

            $this->postJson('/api/v1/inventory/material-requests', [
                'work_center_id' => $this->machine->id,
                'lines' => [['item_id' => $item->id, 'quantity' => '10']],
            ])->assertStatus(422);
        }

        $this->postJson('/api/v1/inventory/material-requests', [
            'work_center_id' => $this->machine->id,
            'lines' => [['item_id' => $this->masterbatch->id, 'quantity' => '5']],
        ])->assertStatus(422);

        $this->assertSame(0, MaterialRequest::query()->count());
    }

    public function test_a_resin_request_with_no_machine_is_accepted(): void
    {
        $response = $this->postJson('/api/v1/inventory/material-requests', [
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '250']],
        ])->assertCreated();

        $this->assertNull($response->json('data.work_center_id'));
        $this->assertSame('250.0000', $response->json('data.lines.0.quantity'));
        $this->assertSame('Kgs', $response->json('data.lines.0.uom'));
    }

    public function test_a_consumable_request_carries_its_machine(): void
    {
        $response = $this->postJson('/api/v1/inventory/material-requests', [
            'work_center_id' => $this->machine->id,
            'lines' => [['item_id' => $this->carton->id, 'quantity' => '40']],
        ])->assertCreated();

        $this->assertSame($this->machine->id, $response->json('data.work_center_id'));
    }

    public function test_a_mixed_request_naming_a_machine_is_refused_on_the_common_input_line(): void
    {
        $response = $this->postJson('/api/v1/inventory/material-requests', [
            'work_center_id' => $this->machine->id,
            'lines' => [
                ['item_id' => $this->carton->id, 'quantity' => '40'],
                ['item_id' => $this->resin->id, 'quantity' => '250'],
            ],
        ])->assertStatus(422);

        $this->assertStringContainsString('Relpet PET Resin', $response->json('message'));
        $this->assertSame(0, MaterialRequest::query()->count());
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
