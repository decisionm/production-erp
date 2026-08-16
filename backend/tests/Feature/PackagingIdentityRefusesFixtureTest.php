<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A LOCAL-ONLY FIXTURE IS NOT A PACKAGING IDENTITY.
 *
 * The packaging's item_id is the Tally name a real product's batches freeze
 * and post under (DEC-20260810-003). A fixture exists in this database and
 * nowhere in Tally, so frozen there it would name something Tally cannot
 * accept — and the posting gate would then decline every batch packed that
 * way, quietly, for a product that IS in Tally (audit §4.11, Hole B's
 * front door). The edit surface used to check existence and is_active
 * only; these pin the refusal, on both signals a fixture can carry.
 */
class PackagingIdentityRefusesFixtureTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Item $realIdentity;

    private ProductionStandard $standard;

    private ProductionStandardPackaging $pouchPacking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bottle = Item::create([
            'sku' => 'BTL-200RA', 'name' => 'B.200 Ml Round Pet Bottle Amber 18gms - 520 Nos',
            'uom' => 'NOS', 'is_active' => true,
        ]);
        $this->realIdentity = Item::create([
            'sku' => 'BTL-200RA-T', 'name' => 'B.200 Ml Round Pet Bottle Amber 18gms',
            'uom' => 'NOS', 'is_active' => true,
        ]);

        $this->standard = ProductionStandard::create([
            'source_product_name' => '200ML RA', 'item_id' => $this->bottle->id,
            'cavities' => 8, 'unit_weight_grams' => 18, 'cycle_time' => 12, 'status' => 'approved',
        ]);
        $this->pouchPacking = $this->standard->packagings()->create([
            'mode' => 'pouch', 'nos_per_pouch' => 130, 'pouches_per_box' => 4, 'nos_per_box' => 520,
            'item_id' => null,
        ]);

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.manage', 'web');
        $user->givePermissionTo('production.manage');
        Sanctum::actingAs($user);
    }

    public function test_a_prefixed_fixture_is_refused_as_a_packaging_identity(): void
    {
        $fixture = Item::create([
            'sku' => 'LOCAL-200ML-RA-TRAY', 'name' => '200ML RA Tray (LOCAL FIXTURE)',
            'uom' => 'NOS', 'is_active' => true, 'is_local_fixture' => true,
        ]);

        $this->postJson("/api/v1/production/standards/{$this->standard->id}/packagings", [
            'mode' => 'direct_box', 'nos_per_box' => 300, 'item_id' => $fixture->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('item_id')
            ->assertJsonPath('errors.item_id.0', fn (string $message) => str_contains($message, 'local-only fixture'));

        $this->assertDatabaseMissing('production_standard_packagings', ['item_id' => $fixture->id]);
    }

    public function test_a_column_flagged_fixture_with_an_ordinary_sku_is_refused_too(): void
    {
        // The SKU programme's case: the flag says fixture, the SKU no longer
        // says so. A prefix-only check would wave this one through.
        $fixture = Item::create([
            'sku' => 'TRY-200-STD', 'name' => 'Renamed Fixture Tray',
            'uom' => 'NOS', 'is_active' => true, 'is_local_fixture' => true,
        ]);

        $this->postJson("/api/v1/production/standards/{$this->standard->id}/packagings", [
            'mode' => 'direct_box', 'nos_per_box' => 300, 'item_id' => $fixture->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('item_id');

        $this->assertDatabaseMissing('production_standard_packagings', ['item_id' => $fixture->id]);
    }

    public function test_editing_an_existing_packaging_onto_a_fixture_is_refused(): void
    {
        $fixture = Item::create([
            'sku' => 'LOCAL-200ML-RA-POUCH', 'name' => '200ML RA Pouch (LOCAL FIXTURE)',
            'uom' => 'NOS', 'is_active' => true, 'is_local_fixture' => true,
        ]);

        $this->putJson("/api/v1/production/standards/{$this->standard->id}/packagings/{$this->pouchPacking->id}", [
            'mode' => 'pouch', 'nos_per_pouch' => 130, 'pouches_per_box' => 4, 'item_id' => $fixture->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('item_id');

        $this->assertNull($this->pouchPacking->fresh()->item_id);
    }

    public function test_a_real_item_is_still_accepted(): void
    {
        // The control: nothing about the refusal touches a real Tally item.
        $this->postJson("/api/v1/production/standards/{$this->standard->id}/packagings", [
            'mode' => 'direct_box', 'nos_per_box' => 300, 'item_id' => $this->realIdentity->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.tally_item.id', $this->realIdentity->id);

        $this->putJson("/api/v1/production/standards/{$this->standard->id}/packagings/{$this->pouchPacking->id}", [
            'mode' => 'pouch', 'nos_per_pouch' => 130, 'pouches_per_box' => 4, 'item_id' => $this->realIdentity->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.tally_item.id', $this->realIdentity->id);
    }
}
