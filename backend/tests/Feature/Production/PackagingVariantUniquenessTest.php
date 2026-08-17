<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 5 P5-01 (D1): one standard, two packings of the SAME mode.
 *
 * The 490/box tray and the 520/box tray of one product are two real
 * packings of one standard, and until this change they were unrepresentable
 * — `psp_standard_mode_unique` (production_standard_id, mode) refused the
 * second row at the database. The variant key is now the mode PLUS its
 * counts, and "exact twin" is the only thing refused — by the service, with
 * the standard and the mode named, as a 422.
 *
 * No factory value is asserted here: 490 and 520 are the raising case's own
 * numbers used as fixtures, not a mapping (Q33 is the owner's).
 */
class PackagingVariantUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private ProductionStandard $standard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bottle = Item::create([
            'sku' => 'BTL-200RA', 'name' => 'B.200 Ml Round Pet Bottle Amber 18gms',
            'uom' => 'NOS', 'is_active' => true,
        ]);

        $this->standard = ProductionStandard::create([
            'source_product_name' => '200ML RA', 'item_id' => $this->bottle->id,
            'cavities' => 8, 'unit_weight_grams' => 18, 'cycle_time' => 12, 'status' => 'approved',
        ]);
    }

    private function actingAsProduction(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.manage', 'web');
        $user->givePermissionTo('production.manage');
        Sanctum::actingAs($user);

        return $user;
    }

    // ------------------------------------------------------ the schema ---

    public function test_the_mode_unique_index_is_gone_and_the_variant_index_is_named(): void
    {
        $indexes = collect(Schema::getIndexes('production_standard_packagings'))->keyBy('name');

        $this->assertFalse($indexes->has('psp_standard_mode_unique'), 'the old (standard, mode) unique must be dropped');
        $this->assertTrue($indexes->has('psp_standard_variant_unique'), 'the variant unique must exist under its short name');
        $this->assertTrue((bool) $indexes->get('psp_standard_variant_unique')['unique']);
        $this->assertSame(
            ['production_standard_id', 'mode', 'nos_per_box', 'nos_per_tray', 'trays_per_box', 'nos_per_pouch', 'pouches_per_box'],
            $indexes->get('psp_standard_variant_unique')['columns'],
        );
    }

    public function test_the_index_swap_migration_rolls_back_and_forward_cleanly(): void
    {
        $migration = require base_path('database/migrations/2026_08_17_130000_replace_packaging_mode_unique_with_variant_unique.php');

        $migration->down();
        $indexes = collect(Schema::getIndexes('production_standard_packagings'))->keyBy('name');
        $this->assertTrue($indexes->has('psp_standard_mode_unique'), 'down() restores the old (standard, mode) unique');
        $this->assertFalse($indexes->has('psp_standard_variant_unique'), 'down() drops the variant unique');
        $this->assertSame(['production_standard_id', 'mode'], $indexes->get('psp_standard_mode_unique')['columns']);

        $migration->up();
        $indexes = collect(Schema::getIndexes('production_standard_packagings'))->keyBy('name');
        $this->assertFalse($indexes->has('psp_standard_mode_unique'));
        $this->assertTrue($indexes->has('psp_standard_variant_unique'));

        // And up() is idempotent — a half-applied run completes.
        $migration->up();
        $this->assertTrue(collect(Schema::getIndexes('production_standard_packagings'))->keyBy('name')->has('psp_standard_variant_unique'));
    }

    // ------------------------------------------- two trays, one standard ---

    public function test_two_tray_packings_with_different_box_counts_coexist_on_one_standard(): void
    {
        $this->actingAsProduction();

        $this->postJson("/api/v1/production/standards/{$this->standard->id}/packagings", [
            'mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5,
        ])
            ->assertCreated()
            ->assertJsonPath('data.mode', 'tray')
            ->assertJsonPath('data.nos_per_box', 490);

        // The second tray packing of the SAME standard — the row the old
        // (standard, mode) unique index refused outright.
        $this->postJson("/api/v1/production/standards/{$this->standard->id}/packagings", [
            'mode' => 'tray', 'nos_per_tray' => 104, 'trays_per_box' => 5,
        ])
            ->assertCreated()
            ->assertJsonPath('data.mode', 'tray')
            ->assertJsonPath('data.nos_per_box', 520);

        $trays = ProductionStandardPackaging::query()
            ->where('production_standard_id', $this->standard->id)
            ->where('mode', 'tray')
            ->orderBy('nos_per_box')
            ->pluck('nos_per_box')
            ->all();

        $this->assertSame([490, 520], $trays);
    }

    public function test_the_model_layer_also_accepts_two_same_mode_rows(): void
    {
        // Not only the endpoint: the importer, migrations and services create
        // rows directly, and the database must accept the second tray row.
        $this->standard->packagings()->create(['mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5, 'nos_per_box' => 490]);
        $this->standard->packagings()->create(['mode' => 'tray', 'nos_per_tray' => 104, 'trays_per_box' => 5, 'nos_per_box' => 520]);

        $this->assertSame(2, $this->standard->packagings()->where('mode', 'tray')->count());
    }

    // ------------------------------------------------------ exact twins ---

    public function test_an_exact_twin_is_refused_with_the_standard_and_mode_named(): void
    {
        $this->actingAsProduction();

        $this->standard->packagings()->create(['mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5, 'nos_per_box' => 490]);

        $response = $this->postJson("/api/v1/production/standards/{$this->standard->id}/packagings", [
            'mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5,
        ]);

        $response->assertStatus(422)
            // The wire shape the standards page already reads: a field-level
            // message under `mode`, plus a stable code.
            ->assertJsonValidationErrors('mode')
            ->assertJsonPath('code', 'duplicate_packaging_variant');

        $message = (string) $response->json('message');
        $this->assertStringContainsString('tray', $message);
        $this->assertStringContainsString('200ML RA', $message);

        $this->assertSame(1, $this->standard->packagings()->count());
    }

    public function test_an_update_that_would_become_a_twin_of_a_sibling_is_refused(): void
    {
        $this->actingAsProduction();

        $this->standard->packagings()->create(['mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5, 'nos_per_box' => 490]);
        $second = $this->standard->packagings()->create(['mode' => 'tray', 'nos_per_tray' => 104, 'trays_per_box' => 5, 'nos_per_box' => 520]);

        $this->putJson("/api/v1/production/standards/{$this->standard->id}/packagings/{$second->id}", [
            'mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mode');

        // Editing a row onto ITSELF is not a twin — the row it matches is the
        // one being edited.
        $this->putJson("/api/v1/production/standards/{$this->standard->id}/packagings/{$second->id}", [
            'mode' => 'tray', 'nos_per_tray' => 104, 'trays_per_box' => 5,
        ])->assertOk();
    }

    public function test_the_same_counts_in_a_different_mode_are_not_a_twin(): void
    {
        $this->actingAsProduction();

        $this->standard->packagings()->create(['mode' => 'direct_box', 'nos_per_box' => 490]);

        // 98 × 5 also comes to 490/box, but a tray packing is not a direct
        // box: same box count, different packing.
        $this->postJson("/api/v1/production/standards/{$this->standard->id}/packagings", [
            'mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5,
        ])->assertCreated();
    }

    // -------------------------------------------- one default per standard ---

    public function test_setting_a_new_default_clears_the_previous_one(): void
    {
        $this->actingAsProduction();

        $first = $this->standard->packagings()->create([
            'mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5, 'nos_per_box' => 490, 'is_default' => true,
        ]);

        $this->postJson("/api/v1/production/standards/{$this->standard->id}/packagings", [
            'mode' => 'tray', 'nos_per_tray' => 104, 'trays_per_box' => 5, 'is_default' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.is_default', true);

        $this->assertFalse($first->fresh()->is_default, 'two defaults would put a question with one answer back on the picker');
        $this->assertSame(1, $this->standard->packagings()->where('is_default', true)->count());
    }

    public function test_updating_a_sibling_to_default_moves_the_default(): void
    {
        $this->actingAsProduction();

        $first = $this->standard->packagings()->create([
            'mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5, 'nos_per_box' => 490, 'is_default' => true,
        ]);
        $second = $this->standard->packagings()->create([
            'mode' => 'pouch', 'nos_per_pouch' => 130, 'pouches_per_box' => 4, 'nos_per_box' => 520,
        ]);

        $this->putJson("/api/v1/production/standards/{$this->standard->id}/packagings/{$second->id}", [
            'mode' => 'pouch', 'nos_per_pouch' => 130, 'pouches_per_box' => 4, 'is_default' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.is_default', true);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
        $this->assertSame(1, $this->standard->packagings()->where('is_default', true)->count());
    }

    public function test_a_default_on_another_standard_is_left_alone(): void
    {
        $this->actingAsProduction();

        $other = ProductionStandard::create([
            'source_product_name' => 'OTHER', 'cavities' => 4, 'unit_weight_grams' => 10,
            'cycle_time' => 10, 'status' => 'approved',
        ]);
        $theirs = $other->packagings()->create(['mode' => 'direct_box', 'nos_per_box' => 300, 'is_default' => true]);

        $this->postJson("/api/v1/production/standards/{$this->standard->id}/packagings", [
            'mode' => 'direct_box', 'nos_per_box' => 300, 'is_default' => true,
        ])->assertCreated();

        $this->assertTrue($theirs->fresh()->is_default);
    }

    public function test_an_edit_that_does_not_mention_the_default_keeps_it(): void
    {
        $this->actingAsProduction();

        $packaging = $this->standard->packagings()->create([
            'mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5, 'nos_per_box' => 490, 'is_default' => true,
        ]);

        $this->putJson("/api/v1/production/standards/{$this->standard->id}/packagings/{$packaging->id}", [
            'mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 6,
        ])
            ->assertOk()
            ->assertJsonPath('data.is_default', true)
            ->assertJsonPath('data.nos_per_box', 588);
    }
}
