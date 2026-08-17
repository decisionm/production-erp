<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ShiftProductionEntryService;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Per-packaging Tally identity — DEC-20260810-003.
 *
 * One physical product, packed more than one way, posting as a DIFFERENT
 * Tally item per packing (the raising case: "B.200 Ml Round Pet Bottle
 * Amber 18gms" vs "...- 520 Nos"). The packaging the supervisor selects
 * decides the identity everywhere — frozen at completion, booked into
 * stock, named on the voucher, aggregated per RESOLVED identity on the
 * shift journal — and a packaging with no identity of its own falls back
 * to the product's, which is every pre-feature batch unchanged.
 */
class PackagingTallyIdentityTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Item $trayIdentity;

    private Warehouse $fgStore;

    private Shift $shift;

    private WorkCenter $machine;

    private ProductionStandard $standard;

    private ProductionStandardPackaging $trayPacking;

    private ProductionStandardPackaging $pouchPacking;

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.approvals.quality_stage_enabled' => false]);

        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
        $this->fgStore = Warehouse::create(['code' => 'WH-FG', 'name' => 'FG Store']);

        // The product's own item, and the SEPARATE Tally item its tray-packed
        // cartons post as — two real rows of one factory's books.
        $this->bottle = Item::create([
            'sku' => 'BTL-200RA', 'name' => 'B.200 Ml Round Pet Bottle Amber 18gms - 520 Nos',
            'uom' => 'NOS', 'is_active' => true,
        ]);
        $this->trayIdentity = Item::create([
            'sku' => 'BTL-200RA-T', 'name' => 'B.200 Ml Round Pet Bottle Amber 18gms',
            'uom' => 'NOS', 'is_active' => true,
        ]);

        $this->standard = ProductionStandard::create([
            'source_product_name' => '200ML RA', 'item_id' => $this->bottle->id,
            'cavities' => 8, 'unit_weight_grams' => 18, 'cycle_time' => 12, 'status' => 'approved',
        ]);
        $this->trayPacking = $this->standard->packagings()->create([
            'mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5, 'nos_per_box' => 490,
            'item_id' => $this->trayIdentity->id,
        ]);
        $this->pouchPacking = $this->standard->packagings()->create([
            'mode' => 'pouch', 'nos_per_pouch' => 130, 'pouches_per_box' => 4, 'nos_per_box' => 520,
            'item_id' => null, // no identity of its own — the product's item
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

    private function inProgressEntry(?int $packagingId): ShiftProductionEntry
    {
        return ShiftProductionEntry::create([
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fgStore->id,
            'production_date' => '2026-08-10',
            'batch_number' => '20260810-M01-'.str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT),
            'batch_status' => BatchStatus::InProgress,
            'quantity_produced' => null,
            'quantity_scrap' => '0',
            'production_standard_id' => $this->standard->id,
            'production_standard_packaging_id' => $packagingId,
            'packaging_mode' => $packagingId === $this->trayPacking->id ? 'tray' : 'pouch',
        ]);
    }

    // ------------------------------------------ selection drives the name ---

    public function test_the_entry_resource_names_the_frozen_packaging_by_id_not_only_by_mode(): void
    {
        // Two same-mode packings can coexist on one standard (Phase 5, D1),
        // so `packaging_mode` alone no longer says which one the run started
        // against; the completion drawer seeds its packing line from the id.
        $this->actingAsProduction();
        $entry = $this->inProgressEntry($this->trayPacking->id);

        $this->getJson('/api/v1/production/shift-production-entries/active')
            ->assertOk()
            ->assertJsonPath('data.0.id', $entry->id)
            ->assertJsonPath('data.0.production_standard_id', $this->standard->id)
            ->assertJsonPath('data.0.production_standard_packaging_id', $this->trayPacking->id)
            ->assertJsonPath('data.0.packaging_mode', 'tray');
    }

    public function test_completing_against_the_tray_packing_freezes_and_books_its_own_identity(): void
    {
        $this->actingAsProduction();
        $entry = $this->inProgressEntry($this->trayPacking->id);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '490',
            'nos_per_tray' => 98,
            'no_of_trays' => 5,
        ])
            ->assertOk()
            // The View modal's fact: which Tally item this batch posts as.
            ->assertJsonPath('data.finished_item.name', 'B.200 Ml Round Pet Bottle Amber 18gms');

        $entry->refresh();
        $this->assertSame($this->trayIdentity->id, (int) $entry->finished_item_id);

        // The FG receipt is booked under the RESOLVED identity, not the
        // product — ERP stock and the Tally voucher must not disagree.
        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $this->trayIdentity->id,
            'warehouse_id' => $this->fgStore->id,
            'reference' => "SPE #{$entry->id}",
        ]);
        $this->assertDatabaseMissing('stock_movements', [
            'item_id' => $this->bottle->id,
            'reference' => "SPE #{$entry->id}",
        ]);

        // The batch voucher names the identity on its produced line.
        $payload = app(TallySyncService::class)->buildBatchVoucherPayload($entry->fresh());
        $this->assertSame('B.200 Ml Round Pet Bottle Amber 18gms', $payload['produced'][0]['item']);
    }

    public function test_a_packing_without_its_own_identity_falls_back_to_the_product(): void
    {
        $this->actingAsProduction();
        $entry = $this->inProgressEntry($this->pouchPacking->id);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '520',
            'no_of_pouches' => 4,
            'nos_per_pouch' => 130,
        ])
            ->assertOk()
            // Null, not a copy of the product item: "no identity of its own"
            // is a recorded state, and the screen prints the fallback rule.
            ->assertJsonPath('data.finished_item', null);

        $entry->refresh();
        $this->assertNull($entry->finished_item_id);

        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $this->bottle->id,
            'reference' => "SPE #{$entry->id}",
        ]);

        $payload = app(TallySyncService::class)->buildBatchVoucherPayload($entry->fresh());
        $this->assertSame($this->bottle->name, $payload['produced'][0]['item']);
    }

    public function test_an_amendment_reverses_under_the_frozen_identity_then_refreezes(): void
    {
        $user = $this->actingAsProduction();
        $entry = $this->inProgressEntry($this->trayPacking->id);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '490', 'nos_per_tray' => 98, 'no_of_trays' => 5,
        ])->assertOk();

        // The identity is edited BETWEEN completion and amendment — the
        // reversal must still take the stock back off the item the wrong
        // completion actually booked (the frozen one), and the re-completion
        // freezes the NEW answer.
        $this->trayPacking->update(['item_id' => null]);

        app(ShiftProductionEntryService::class)->amendCompletion(
            $entry->fresh(),
            ['quantity_produced' => '392', 'nos_per_tray' => 98, 'no_of_trays' => 4],
            $user->id,
        );

        $entry->refresh();
        // Re-frozen against the packaging as it stands NOW: no identity.
        $this->assertNull($entry->finished_item_id);

        // Net stock: the 490 under the old identity was reversed off that
        // same identity; the corrected 392 sits under the product item.
        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $this->trayIdentity->id,
            'reference' => "SPE #{$entry->id} amended",
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $this->bottle->id,
            'reference' => "SPE #{$entry->id}",
        ]);
    }

    public function test_the_shift_voucher_aggregates_per_resolved_identity(): void
    {
        config(['tally-sync.voucher_granularity' => 'shift']);
        config(['tally-sync.release_idle_minutes' => 0]);

        $this->actingAsProduction();

        // Two completed batches of ONE product in one shift: tray-packed
        // (own identity) and pouch-packed (product identity). The shift
        // journal must carry TWO produced lines — their two Tally items —
        // not one merged line under the product.
        $tray = $this->inProgressEntry($this->trayPacking->id);
        $tray->update([
            'batch_status' => BatchStatus::Completed,
            'quantity_produced' => '490',
            'finished_item_id' => $this->trayIdentity->id,
            'status' => ShiftProductionEntryStatus::Pending,
        ]);
        $pouch = $this->inProgressEntry($this->pouchPacking->id);
        $pouch->update([
            'batch_status' => BatchStatus::Completed,
            'quantity_produced' => '520',
            'status' => ShiftProductionEntryStatus::Pending,
        ]);

        $service = app(ShiftProductionEntryService::class);
        $approver = User::factory()->create();
        foreach ([$tray, $pouch] as $entry) {
            $service->pmApprove($entry->fresh(), $approver->id);
            $service->accountantApprove($entry->fresh(), User::factory()->create()->id);
        }

        $voucher = TallySyncEntry::query()->sole();
        $produced = collect($voucher->payload['produced'])->keyBy('item');

        $this->assertCount(2, $produced);
        $this->assertSame('490.0000', $produced['B.200 Ml Round Pet Bottle Amber 18gms']['quantity']);
        $this->assertSame('520.0000', $produced[$this->bottle->name]['quantity']);
    }

    // ---------------------------------------------------- the edit surface ---

    public function test_a_packaging_variant_is_added_with_its_identity_and_provenance(): void
    {
        $user = $this->actingAsProduction();

        $this->postJson("/api/v1/production/standards/{$this->standard->id}/packagings", [
            'mode' => 'direct_box', 'nos_per_box' => 300,
            'item_id' => $this->trayIdentity->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.tally_item.name', 'B.200 Ml Round Pet Bottle Amber 18gms')
            ->assertJsonPath('data.uses_product_identity', false);

        $this->assertDatabaseHas('production_standard_packagings', [
            'production_standard_id' => $this->standard->id,
            'mode' => 'direct_box',
            'nos_per_box' => 300,
            'item_id' => $this->trayIdentity->id,
            'item_set_by' => $user->id,
        ]);
    }

    public function test_editing_the_identity_stamps_provenance_and_clearing_it_restores_the_fallback(): void
    {
        $user = $this->actingAsProduction();

        // Same identity value on a second packing is fine — "if the Tally
        // identity is the same for both then two entries are the same value".
        $this->putJson("/api/v1/production/standards/{$this->standard->id}/packagings/{$this->pouchPacking->id}", [
            'mode' => 'pouch', 'nos_per_pouch' => 130, 'pouches_per_box' => 4,
            'item_id' => $this->trayIdentity->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.tally_item.id', $this->trayIdentity->id);

        $this->assertDatabaseHas('production_standard_packagings', [
            'id' => $this->pouchPacking->id, 'item_id' => $this->trayIdentity->id, 'item_set_by' => $user->id,
        ]);

        // Clearing is a real answer too: back to the product's identity,
        // said in so many words.
        $this->putJson("/api/v1/production/standards/{$this->standard->id}/packagings/{$this->pouchPacking->id}", [
            'mode' => 'pouch', 'item_id' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.tally_item', null)
            ->assertJsonPath('data.uses_product_identity', true)
            ->assertJsonPath('data.resolved_item_name', $this->bottle->name);
    }

    public function test_half_stated_counts_twins_and_foreign_rows_are_refused(): void
    {
        $this->actingAsProduction();

        // One count of a mode on its own is not a packing option.
        $this->postJson("/api/v1/production/standards/{$this->standard->id}/packagings", [
            'mode' => 'tray', 'nos_per_tray' => 84,
        ])->assertStatus(422)->assertJsonValidationErrors('trays_per_box');

        // An exact twin of an existing option is corrected, not duplicated.
        $this->postJson("/api/v1/production/standards/{$this->standard->id}/packagings", [
            'mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5,
        ])->assertStatus(422)->assertJsonValidationErrors('mode');

        // A packaging can only be edited through ITS OWN standard.
        $other = ProductionStandard::create([
            'source_product_name' => 'OTHER', 'cavities' => 4, 'unit_weight_grams' => 10,
            'cycle_time' => 10, 'status' => 'approved',
        ]);
        $this->putJson("/api/v1/production/standards/{$other->id}/packagings/{$this->trayPacking->id}", [
            'mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5,
        ])->assertStatus(422);
    }

    public function test_repointing_the_product_identity_needs_an_explicit_confirmation(): void
    {
        $this->actingAsProduction();

        // Without the confirmation: refused, and the message says why.
        $this->postJson("/api/v1/production/standards/{$this->standard->id}/attach-item", [
            'item_id' => $this->trayIdentity->id,
        ])->assertStatus(422)->assertJsonValidationErrors('item_id');

        // With it: re-pointed, and the provenance note names the change.
        $this->postJson("/api/v1/production/standards/{$this->standard->id}/attach-item", [
            'item_id' => $this->trayIdentity->id,
            'confirm_reattach' => true,
        ])->assertOk();

        $this->standard->refresh();
        $this->assertSame($this->trayIdentity->id, (int) $this->standard->item_id);
        $this->assertStringContainsString('re-pointed from', (string) $this->standard->notes);
    }

    // ------------------------------------------------- the 490 data patch ---

    public function test_the_490_tray_migration_creates_the_variant_once_with_identity_unset(): void
    {
        // The 07-Aug paper's packing, missing from configuration. The
        // migration must create it exactly once, identity UNSET (Q33 —
        // the owner answers it in the edit UI, nobody hardcodes it).
        // forceDelete, not delete: `production_standard_packagings` now
        // soft-deletes (the Configuration Lifecycle Contract's Archive needed
        // somewhere to go), and an ARCHIVED variant still holds its slot — so
        // the data patch correctly declines to add a second copy behind it.
        // This case is "the row was never created at all", which is what the
        // migration is for.
        $this->trayPacking->forceDelete();

        $migration = require base_path('database/migrations/2026_08_10_191000_add_200ml_ra_490_tray_packaging.php');

        $migration->up();
        $migration->up(); // idempotent — a second deploy adds nothing

        $rows = ProductionStandardPackaging::query()
            ->where('production_standard_id', $this->standard->id)
            ->where('mode', 'tray')->where('nos_per_tray', 98)->where('trays_per_box', 5)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame(490, (int) $rows->first()->nos_per_box);
        $this->assertNull($rows->first()->item_id);
    }

    public function test_the_490_tray_migration_says_so_when_it_matches_nothing(): void
    {
        // The 11-Aug-2026 lesson: on live this migration matched no standard
        // and returned quietly, so a green deploy hid the fact that the
        // variant was never created. A no-op must NAME the key it looked for.
        DB::table('production_standards')
            ->where('id', $this->standard->id)
            ->update(['source_product_name' => 'SOMETHING ELSE']);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, '200ML RA')
                && str_contains($message, 'NO-OP')
                && str_contains($message, '490'));

        $migration = require base_path('database/migrations/2026_08_10_191000_add_200ml_ra_490_tray_packaging.php');
        $migration->up();
    }

    public function test_the_490_tray_migration_reports_what_it_created(): void
    {
        $this->trayPacking->delete();

        Log::shouldReceive('warning')->never();
        Log::shouldReceive('info')
            ->atLeast()->once()
            ->withArgs(fn (string $message) => str_contains($message, '200ml_ra_490_tray_packaging'));

        $migration = require base_path('database/migrations/2026_08_10_191000_add_200ml_ra_490_tray_packaging.php');
        $migration->up();
    }
}
