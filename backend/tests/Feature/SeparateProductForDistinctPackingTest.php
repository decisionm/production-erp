<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ProductionStandardImportService;
use App\Modules\Production\Services\ProductionStandardPackagingService;
use App\Modules\Production\Services\ProductVariantService;
use App\Modules\Production\Services\ShiftProductionEntryService;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Services\PayloadHash;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * DEC-20260821-001 — where Tally carries separate stock items for a
 * product's pouch and tray packings, the ERP holds TWO separate
 * finished-product masters, each mapped one-to-one to its own Tally stock
 * item; not two packaging identities under one product.
 *
 * This pins the FORWARD half of that rule, which is the half that can be
 * built without the 490 tray's live identity (still open — Q33). Two
 * things are refused from now on, and everything already recorded is
 * deliberately left alone:
 *
 *   REFUSED   starting a NEW batch under a packing whose own Tally identity
 *             is a different item from the product being run;
 *   REFUSED   a NEW distinct identity on either configuration writer — the
 *             identity PATCH and the full packaging save;
 *   KEPT      inheritance (null) and a packing restating its product's own
 *             item, on both paths — the majority of live rows;
 *   KEPT      every legacy row and legacy entry: still editable for its
 *             counts, still completable, still amendable, and its voucher
 *             still rebuilds byte for byte.
 *
 * The raising case's shape, with the names the factory uses: one product
 * ("…- 520 Nos") and a separate Tally item the tray packing was pointed at
 * under the superseded DEC-20260810-003. NOTHING here asserts which live
 * item the real 490 tray is — that is Q33 and is never guessed.
 */
class SeparateProductForDistinctPackingTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Item $separateProduct;

    private Item $thirdProduct;

    private Warehouse $fgStore;

    private Shift $shift;

    private WorkCenter $machine;

    private ProductionStandard $standard;

    /** Written directly, as a row created before this rule existed. */
    private ProductionStandardPackaging $legacyTrayPacking;

    private ProductionStandardPackaging $pouchPacking;

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.approvals.quality_stage_enabled' => false]);

        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $this->machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'is_active' => true]);
        $this->fgStore = Warehouse::create(['code' => 'WH-FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg']);

        // Real, Tally-pulled, active, non-fixture items on BOTH sides. The
        // refusals under test must be the separate-product rule and nothing
        // else: a GUID-less or fixture item would be refused by the older
        // identity rules, and a product missing its run figures would be
        // refused by the readiness gate before Start ever reached the guard.
        $this->bottle = Item::create([
            'sku' => 'BTL-200RA', 'name' => 'B.200 Ml Round Pet Bottle Amber 18gms - 520 Nos',
            'uom' => 'NOS', 'is_active' => true, 'tally_stock_item_guid' => 'itm-200ra-520',
            'nominal_weight_grams' => '18.0000', 'standard_cavities' => 8, 'standard_cycle_time' => '12.00',
            'colour' => 'Amber',
        ]);
        $this->separateProduct = Item::create([
            'sku' => 'BTL-200RA-T', 'name' => 'B.200 Ml Round Pet Bottle Amber 18gms - 490 Nos',
            'uom' => 'NOS', 'is_active' => true, 'tally_stock_item_guid' => 'itm-200ra-490',
            'nominal_weight_grams' => '18.0000', 'standard_cavities' => 8, 'standard_cycle_time' => '12.00',
            'colour' => 'Amber',
        ]);
        $this->thirdProduct = Item::create([
            'sku' => 'BTL-200RA-X', 'name' => 'B.200 Ml Round Pet Bottle Amber 18gms - 400 Nos',
            'uom' => 'NOS', 'is_active' => true, 'tally_stock_item_guid' => 'itm-200ra-400',
        ]);

        $this->standard = ProductionStandard::create([
            'source_product_name' => '200ML ROUND', 'item_id' => $this->bottle->id,
            'cavities' => 8, 'unit_weight_grams' => '18.0000', 'cycle_time' => '12.00', 'status' => 'approved',
        ]);

        // The legacy shape: a packing pointed at ANOTHER product's Tally
        // item under the superseded authority. Created through the model,
        // not the endpoint — the endpoint now refuses to create one, which
        // is the whole point, and a row like this may still exist on live.
        $this->legacyTrayPacking = $this->standard->packagings()->create([
            'mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5, 'nos_per_box' => 490,
            'item_id' => $this->separateProduct->id,
        ]);
        $this->pouchPacking = $this->standard->packagings()->create([
            'mode' => 'pouch', 'nos_per_pouch' => 130, 'pouches_per_box' => 4, 'nos_per_box' => 520,
            'item_id' => null, 'is_default' => true,
        ]);
    }

    private function actingAsProduction(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        Permission::findOrCreate('production.manage', 'web');
        $user->givePermissionTo(['production.view', 'production.manage']);
        $this->actingAs($user);

        return $user;
    }

    /** @param array<string, mixed> $extra */
    private function startBatchPayload(?int $packagingId, array $extra = []): array
    {
        return array_merge([
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fgStore->id,
            'production_date' => '2026-08-10',
            'production_standard_id' => $this->standard->id,
            'production_standard_packaging_id' => $packagingId,
            'colour' => 'Amber',
        ], $extra);
    }

    /** @return array{entries: int, movements: int, vouchers: int} */
    private function writeCounts(): array
    {
        return [
            'entries' => ShiftProductionEntry::query()->count(),
            'movements' => StockMovement::query()->count(),
            'vouchers' => TallySyncEntry::query()->count(),
        ];
    }

    // ------------------------------------------------- the shared judgment ---

    public function test_the_shared_judgment_treats_inheritance_and_sameness_as_compliant(): void
    {
        // Null = the packing posts as its product. The commonest live shape;
        // a guard that fired here would stop the floor.
        $this->assertFalse(ProductVariantService::identityConflictsWithProduct(null, 7));
        // One product, one Tally item, stated twice.
        $this->assertFalse(ProductVariantService::identityConflictsWithProduct(7, 7));
        // An UNATTACHED standard has no product to conflict with, and its
        // packing's identity is the only postable one it has.
        $this->assertFalse(ProductVariantService::identityConflictsWithProduct(7, null));
        $this->assertFalse(ProductVariantService::identityConflictsWithProduct(null, null));
        // The one relation the record withdraws.
        $this->assertTrue(ProductVariantService::identityConflictsWithProduct(7, 9));
    }

    // ------------------------------------------------------ the batch guard ---

    public function test_starting_a_batch_under_a_distinct_packing_identity_is_refused_and_writes_nothing(): void
    {
        $this->actingAsProduction();
        $before = $this->writeCounts();

        $response = $this->postJson(
            '/api/v1/production/shift-production-entries',
            $this->startBatchPayload($this->legacyTrayPacking->id),
        )->assertStatus(422);

        // Named — this refusal must not be mistaken for the readiness gate
        // or for any older identity rule.
        $response->assertJsonPath('code', 'packaging_belongs_to_separate_product');
        $message = (string) $response->json('message');
        $this->assertStringContainsString('tray', strtolower($message));
        $this->assertStringContainsString($this->separateProduct->name, $message);
        $this->assertStringContainsString($this->bottle->name, $message);
        $this->assertStringContainsString('separate finished product', $message);
        // The instruction starts at TALLY, not at the Add Item button: a
        // finished item created by hand here carries no Tally GUID and could
        // never post, so "create a product" alone would send the supervisor
        // to build a master that refuses at the voucher.
        $this->assertStringContainsString(ProductVariantService::SEPARATE_PRODUCT_INSTRUCTION, $message);
        $this->assertStringContainsString('Pull the Tally masters', $message);

        // The three parties, structured, so a client can offer the right
        // product without parsing the sentence.
        $response->assertJsonPath('packaging.id', $this->legacyTrayPacking->id);
        $response->assertJsonPath('product.id', $this->bottle->id);
        $response->assertJsonPath('packaging_item.id', $this->separateProduct->id);

        // NOTHING was written: no batch row, no stock movement, no voucher.
        $this->assertSame($before, $this->writeCounts());
    }

    public function test_a_silently_auto_resolved_default_is_refused_too(): void
    {
        // The shape that needs the guard most: nobody CHOSE this packing.
        // The standard's default is resolved server-side with no id in the
        // payload and, where a standard has one packing, with no question
        // asked on screen either — so without a check here the conflict
        // would only ever surface after the batch had started.
        $this->actingAsProduction();
        $this->pouchPacking->update(['is_default' => false]);
        $this->legacyTrayPacking->update(['is_default' => true]);
        $before = $this->writeCounts();

        $payload = $this->startBatchPayload(null);
        unset($payload['production_standard_packaging_id']);

        $this->postJson('/api/v1/production/shift-production-entries', $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'packaging_belongs_to_separate_product')
            ->assertJsonPath('packaging.id', $this->legacyTrayPacking->id);

        $this->assertSame($before, $this->writeCounts());
    }

    public function test_the_shift_page_ingest_is_refused_through_the_same_guard(): void
    {
        // ShiftPageEntryService composes startBatch(), so the paper path is
        // covered by the same site — asserted rather than assumed, because
        // it is the one floor path that does not go through Start Batch.
        $this->actingAsProduction();
        $before = $this->writeCounts();

        $result = $this->postJson('/api/v1/production/shift-production-entries/page', [
            'shift_id' => $this->shift->id,
            'production_date' => '2026-08-10',
            'rows' => [[
                'work_center_id' => $this->machine->id,
                'item_id' => $this->bottle->id,
                'production_standard_id' => $this->standard->id,
                'production_standard_packaging_id' => $this->legacyTrayPacking->id,
                'quantity_produced' => '490',
            ]],
        ])->assertOk()->json('data');

        $this->assertSame([], $result['recorded']);
        $this->assertCount(1, $result['failed']);
        $this->assertStringContainsString('separate finished product', $result['failed'][0]['reason']);
        $this->assertSame($before, $this->writeCounts());
    }

    public function test_an_inherited_identity_starts_normally(): void
    {
        $this->actingAsProduction();

        $entry = $this->postJson(
            '/api/v1/production/shift-production-entries',
            $this->startBatchPayload($this->pouchPacking->id),
        )->assertOk()->json('data');

        $this->assertSame($this->pouchPacking->id, $entry['production_standard_packaging_id']);
        $this->assertDatabaseHas('shift_production_entries', [
            'id' => $entry['id'],
            'item_id' => $this->bottle->id,
            'production_standard_packaging_id' => $this->pouchPacking->id,
        ]);
    }

    public function test_a_packing_restating_its_own_products_identity_starts_normally(): void
    {
        $this->actingAsProduction();
        $this->pouchPacking->update(['item_id' => $this->bottle->id]);

        $entry = $this->postJson(
            '/api/v1/production/shift-production-entries',
            $this->startBatchPayload($this->pouchPacking->id),
        )->assertOk()->json('data');

        $this->assertDatabaseHas('shift_production_entries', [
            'id' => $entry['id'],
            'production_standard_packaging_id' => $this->pouchPacking->id,
        ]);
    }

    // ------------------------------------- the two configuration writers ---

    public function test_the_identity_patch_refuses_a_new_distinct_item_and_still_accepts_the_compliant_answers(): void
    {
        $this->actingAsProduction();
        $base = "/api/v1/production/standards/{$this->standard->id}/packagings/{$this->pouchPacking->id}/identity";

        // A NEW distinct assignment — the authority DEC-20260821-001 withdrew.
        $this->patchJson($base, ['item_id' => $this->separateProduct->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('item_id');
        $this->assertNull($this->pouchPacking->fresh()->item_id);

        // The product's own item: one product, one Tally item.
        $this->patchJson($base, ['item_id' => $this->bottle->id])
            ->assertOk()
            ->assertJsonPath('data.tally_item.id', $this->bottle->id);

        // Clearing: the edit that migrates a row TOWARD the new rule.
        $this->patchJson($base, ['item_id' => null])
            ->assertOk()
            ->assertJsonPath('data.uses_product_identity', true);
        $this->assertNull($this->pouchPacking->fresh()->item_id);
    }

    public function test_the_identity_patch_lets_a_legacy_row_keep_what_it_has_but_not_move_to_another_product(): void
    {
        $this->actingAsProduction();
        $base = "/api/v1/production/standards/{$this->standard->id}/packagings/{$this->legacyTrayPacking->id}/identity";

        // Re-sending the value the row already carries changes nothing and
        // is not a new assignment — a legacy row stays maintainable.
        $this->patchJson($base, ['item_id' => $this->separateProduct->id])
            ->assertOk()
            ->assertJsonPath('data.tally_item.id', $this->separateProduct->id);

        // Moving it to ANOTHER distinct product IS a new assignment.
        $this->patchJson($base, ['item_id' => $this->thirdProduct->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('item_id');
        $this->assertSame($this->separateProduct->id, (int) $this->legacyTrayPacking->fresh()->item_id);

        // And it may still be cleared.
        $this->patchJson($base, ['item_id' => null])->assertOk();
        $this->assertNull($this->legacyTrayPacking->fresh()->item_id);
    }

    public function test_the_full_save_cannot_be_used_to_bypass_the_identity_patch(): void
    {
        $this->actingAsProduction();

        // Adding a variant with a distinct identity.
        $this->postJson("/api/v1/production/standards/{$this->standard->id}/packagings", [
            'mode' => 'direct_box', 'nos_per_box' => 300, 'item_id' => $this->separateProduct->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('item_id');
        $this->assertDatabaseMissing('production_standard_packagings', ['mode' => 'direct_box']);

        // Editing an existing compliant row onto one.
        $this->putJson("/api/v1/production/standards/{$this->standard->id}/packagings/{$this->pouchPacking->id}", [
            'mode' => 'pouch', 'nos_per_pouch' => 130, 'pouches_per_box' => 4,
            'item_id' => $this->separateProduct->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('item_id');
        $this->assertNull($this->pouchPacking->fresh()->item_id);
    }

    public function test_a_legacy_rows_counts_stay_editable_in_both_payload_shapes(): void
    {
        $this->actingAsProduction();
        $url = "/api/v1/production/standards/{$this->standard->id}/packagings/{$this->legacyTrayPacking->id}";

        // Shape 1 — the identity key omitted entirely. The service writes
        // item_id only when the key is present, so this is not an
        // assignment and must not be read as one.
        $this->putJson($url, ['mode' => 'tray', 'nos_per_tray' => 96, 'trays_per_box' => 5])
            ->assertOk()
            ->assertJsonPath('data.nos_per_tray', 96)
            ->assertJsonPath('data.tally_item.id', $this->separateProduct->id);

        // Shape 2 — the identity re-sent unchanged beside a count edit.
        $this->putJson($url, [
            'mode' => 'tray', 'nos_per_tray' => 94, 'trays_per_box' => 5,
            'item_id' => $this->separateProduct->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.nos_per_tray', 94)
            ->assertJsonPath('data.tally_item.id', $this->separateProduct->id);

        // Shape 3 — the same count edit, but moving the identity to another
        // distinct product. Refused, and the count edit does not land either.
        $this->putJson($url, [
            'mode' => 'tray', 'nos_per_tray' => 90, 'trays_per_box' => 5,
            'item_id' => $this->thirdProduct->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('item_id');

        $row = $this->legacyTrayPacking->fresh();
        $this->assertSame(94, (int) $row->nos_per_tray);
        $this->assertSame($this->separateProduct->id, (int) $row->item_id);
    }

    public function test_an_unattached_standards_packing_identity_is_not_blocked(): void
    {
        // production_standards.item_id is nullable — an import the matcher
        // could not place. Such a standard has no product to conflict with,
        // and its packing's own identity is the only postable identity it
        // has, so the refusal must not fire on it. (It can never reach the
        // batch guard: ProductionStandardResolver scopes every run's
        // standard to the entry's item_id.)
        $this->actingAsProduction();

        $orphan = ProductionStandard::create([
            'source_product_name' => 'UNMATCHED', 'item_id' => null,
            'cavities' => 8, 'unit_weight_grams' => '18.0000', 'cycle_time' => '12.00', 'status' => 'draft',
        ]);

        $this->postJson("/api/v1/production/standards/{$orphan->id}/packagings", [
            'mode' => 'direct_box', 'nos_per_box' => 300, 'item_id' => $this->separateProduct->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.tally_item.id', $this->separateProduct->id);
    }

    // ------------------------------------------------ legacy preservation ---

    /** A batch recorded before the guard, carrying the frozen distinct id. */
    private function legacyEntry(): ShiftProductionEntry
    {
        return ShiftProductionEntry::create([
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fgStore->id,
            'production_date' => '2026-08-10',
            'batch_number' => '20260810-M01-001',
            'batch_status' => BatchStatus::InProgress,
            'quantity_produced' => null,
            'quantity_scrap' => '0',
            'production_standard_id' => $this->standard->id,
            'production_standard_packaging_id' => $this->legacyTrayPacking->id,
            'packaging_mode' => 'tray',
        ]);
    }

    public function test_a_legacy_entry_still_completes_and_books_under_its_frozen_identity(): void
    {
        $this->actingAsProduction();
        $entry = $this->legacyEntry();

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '490', 'nos_per_tray' => 98, 'no_of_trays' => 5,
        ])
            ->assertOk()
            ->assertJsonPath('data.finished_item.name', $this->separateProduct->name);

        $entry->refresh();
        $this->assertSame($this->separateProduct->id, (int) $entry->finished_item_id);

        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $this->separateProduct->id,
            'warehouse_id' => $this->fgStore->id,
            'reference' => "SPE #{$entry->id}",
        ]);
    }

    public function test_a_legacy_entry_still_amends_and_reverses_under_its_frozen_identity(): void
    {
        $user = $this->actingAsProduction();
        $entry = $this->legacyEntry();

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '490', 'nos_per_tray' => 98, 'no_of_trays' => 5,
        ])->assertOk();

        app(ShiftProductionEntryService::class)->amendCompletion(
            $entry->fresh(),
            ['quantity_produced' => '392', 'nos_per_tray' => 98, 'no_of_trays' => 4],
            $user->id,
        );

        // The reversal takes the 490 back off the identity the completion
        // actually booked, and the re-completion re-freezes the same one —
        // no guard at completion or amendment, by design.
        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $this->separateProduct->id,
            'reference' => "SPE #{$entry->id} amended",
        ]);
        $this->assertSame($this->separateProduct->id, (int) $entry->fresh()->finished_item_id);
        $this->assertEqualsWithDelta(392, (float) $entry->fresh()->quantity_produced, 0.0001);
    }

    public function test_a_legacy_entrys_voucher_contribution_survives_a_forced_rebuild_byte_for_byte(): void
    {
        // The stored payload is NOT a write-once blob — it is rebuilt from
        // ALL members on every merge. So "the hash never changes" is not the
        // guarantee; "the rebuild is identical for the legacy entry" is.
        config(['tally-sync.voucher_granularity' => 'shift']);
        config(['tally-sync.release_idle_minutes' => 0]);

        $this->actingAsProduction();
        $service = app(ShiftProductionEntryService::class);

        $legacy = $this->legacyEntry();
        $legacy->update([
            'batch_status' => BatchStatus::Completed,
            'quantity_produced' => '490',
            'finished_item_id' => $this->separateProduct->id,
            'status' => ShiftProductionEntryStatus::Pending,
        ]);

        $service->pmApprove($legacy->fresh(), User::factory()->create()->id);
        $service->accountantApprove($legacy->fresh(), User::factory()->create()->id);

        $voucher = TallySyncEntry::query()->sole();
        $wholeBefore = PayloadHash::of($voucher->payload);
        $legacyLineBefore = collect($voucher->payload['produced'])
            ->firstWhere('item', $this->separateProduct->name);
        $this->assertNotNull($legacyLineBefore, 'the produced block must name the FROZEN identity');
        $legacyHashBefore = PayloadHash::of($legacyLineBefore);

        // Force the rebuild: a shift-mate of the SAME product, packed the
        // inherited way, merges into this voucher and the payload is rebuilt
        // from both members.
        $mate = ShiftProductionEntry::create([
            'shift_id' => $this->shift->id,
            'work_center_id' => WorkCenter::create(['code' => 'MC-02', 'name' => 'Machine 2', 'is_active' => true])->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fgStore->id,
            'production_date' => '2026-08-10',
            'batch_number' => '20260810-M02-001',
            'batch_status' => BatchStatus::Completed,
            'quantity_produced' => '520',
            'quantity_scrap' => '0',
            'production_standard_id' => $this->standard->id,
            'production_standard_packaging_id' => $this->pouchPacking->id,
            'packaging_mode' => 'pouch',
            'status' => ShiftProductionEntryStatus::Pending,
        ]);
        $service->pmApprove($mate->fresh(), User::factory()->create()->id);
        $service->accountantApprove($mate->fresh(), User::factory()->create()->id);

        $voucher->refresh();

        // A real rebuild happened (otherwise this test proves nothing)...
        $this->assertNotSame($wholeBefore, PayloadHash::of($voucher->payload));

        // ...and the two members landed as TWO produced lines, which is the
        // "{effectiveItemId}@{warehouse_id}" aggregation key doing its work:
        // same warehouse, different resolved identity, so no merge.
        $produced = collect($voucher->payload['produced'])->keyBy('item');
        $this->assertCount(2, $produced);
        $this->assertSame('490.0000', $produced[$this->separateProduct->name]['quantity']);
        $this->assertSame('520.0000', $produced[$this->bottle->name]['quantity']);

        // The legacy entry's own contribution is byte-identical — PayloadHash
        // is sha256 over canonical JSON in stored key order, so key ORDER is
        // part of what this pins.
        $this->assertSame(
            $legacyHashBefore,
            PayloadHash::of($produced[$this->separateProduct->name]),
        );
    }

    // ------------------------------------------ the product-side writers ---
    //
    // THE HOLE THESE CLOSE, stated once because the two halves read as a
    // contradiction otherwise. The configuration writers deliberately PERMIT
    // a distinct identity on a packing whose standard is UNATTACHED: there is
    // no product to conflict with yet. That permission is the precondition
    // for building the forbidden relation from the OTHER end — attach the
    // standard to a different product afterwards and the mismatch exists
    // without any packaging writer having been asked. Every writer of
    // `production_standards.item_id` therefore applies the same judgment.
    //
    // The composed rule: you may set a distinct identity while unattached,
    // and you may then only ever attach to THAT identity.

    /**
     * A standard with no product yet, whose tray packing already names its
     * own Tally item. Returns [standard, packaging].
     *
     * @return array{0: ProductionStandard, 1: ProductionStandardPackaging}
     */
    private function unattachedStandardPackedAs(Item $identity): array
    {
        $standard = ProductionStandard::create([
            'source_product_name' => '200ML RA UNMATCHED', 'item_id' => null,
            'cavities' => 8, 'unit_weight_grams' => '18.0000', 'cycle_time' => '12.00', 'status' => 'draft',
        ]);

        $packaging = $standard->packagings()->create([
            'mode' => 'tray', 'nos_per_tray' => 98, 'trays_per_box' => 5, 'nos_per_box' => 490,
        ]);

        // Through the ENDPOINT, not the model — this is the permission the
        // hole is built on, and it must be shown to be a real, accepted write
        // rather than something only a test can produce.
        $this->patchJson(
            "/api/v1/production/standards/{$standard->id}/packagings/{$packaging->id}/identity",
            ['item_id' => $identity->id],
        )->assertOk();

        return [$standard->fresh(), $packaging->fresh()];
    }

    public function test_attaching_an_unattached_standard_to_a_product_its_packing_contradicts_is_refused(): void
    {
        $this->actingAsProduction();
        [$standard, $packaging] = $this->unattachedStandardPackedAs($this->separateProduct);

        // Product B, while the packing already posts as product A.
        $response = $this->postJson(
            "/api/v1/production/standards/{$standard->id}/attach-item",
            ['item_id' => $this->bottle->id],
        )->assertStatus(422);

        $message = (string) $response->json('errors.item_id.0');
        $this->assertStringContainsString($this->separateProduct->name, $message);
        $this->assertStringContainsString($this->bottle->name, $message);
        $this->assertStringContainsString(ProductVariantService::SEPARATE_PRODUCT_INSTRUCTION, $message);

        // NOTHING MUTATED. Not the attachment, not the provenance columns,
        // not the note the successful path appends, and not the packing.
        $standard->refresh();
        $this->assertNull($standard->item_id);
        $this->assertNull($standard->item_attached_at);
        $this->assertNull($standard->item_attached_by);
        $this->assertNull($standard->notes);
        $this->assertSame($this->separateProduct->id, (int) $packaging->fresh()->item_id);
    }

    public function test_attaching_to_the_product_the_packing_already_names_succeeds(): void
    {
        $this->actingAsProduction();
        [$standard] = $this->unattachedStandardPackedAs($this->separateProduct);

        // Product A — one product, one Tally item, stated on both rows. This
        // is what the refusal above tells the person to do, so it has to work.
        $this->postJson(
            "/api/v1/production/standards/{$standard->id}/attach-item",
            ['item_id' => $this->separateProduct->id],
        )->assertOk();

        $this->assertSame($this->separateProduct->id, (int) $standard->fresh()->item_id);
    }

    public function test_reattaching_to_a_conflicting_product_is_refused_and_leaves_the_old_attachment(): void
    {
        $this->actingAsProduction();
        [$standard] = $this->unattachedStandardPackedAs($this->separateProduct);

        $this->postJson(
            "/api/v1/production/standards/{$standard->id}/attach-item",
            ['item_id' => $this->separateProduct->id],
        )->assertOk();

        $attached = $standard->fresh();
        $noteBefore = (string) $attached->notes;
        $attachedAtBefore = (string) $attached->item_attached_at;

        // Re-pointing at a third product, CONFIRMED — the flag that makes a
        // reattach legal under DEC-20260810-003 must not carry it past this
        // rule, or the confirmation becomes the way round the guard.
        $response = $this->postJson(
            "/api/v1/production/standards/{$standard->id}/attach-item",
            ['item_id' => $this->bottle->id, 'confirm_reattach' => true],
        )->assertStatus(422);

        $this->assertStringContainsString(
            ProductVariantService::SEPARATE_PRODUCT_INSTRUCTION,
            (string) $response->json('errors.item_id.0'),
        );

        $attached->refresh();
        $this->assertSame($this->separateProduct->id, (int) $attached->item_id);
        $this->assertSame($noteBefore, (string) $attached->notes);
        $this->assertSame($attachedAtBefore, (string) $attached->item_attached_at);
    }

    public function test_a_legacy_conflicting_standard_stays_readable_and_maintainable(): void
    {
        $this->actingAsProduction();

        // The live legacy shape from setUp: standard on the 520 bottle, tray
        // packing frozen on the separate 490 item. Nothing migrates it, so
        // every ordinary maintenance act on it must still work.
        $this->getJson("/api/v1/production/standards/{$this->standard->id}")->assertOk();

        // (a) A no-op re-attach to the item it ALREADY carries. The guard
        //     must exempt this: refusing a row its own identity would strand
        //     it, which is exactly what "do not make it unmaintainable"
        //     forbids.
        $this->postJson(
            "/api/v1/production/standards/{$this->standard->id}/attach-item",
            ['item_id' => $this->bottle->id, 'confirm_reattach' => true],
        )->assertOk();
        $this->assertSame($this->bottle->id, (int) $this->standard->fresh()->item_id);

        // (b) A counts-only edit of the conflicting packing itself, re-sending
        //     the identity it already holds — the shape a form sends when a
        //     supervisor corrects a tray count.
        $this->putJson(
            "/api/v1/production/standards/{$this->standard->id}/packagings/{$this->legacyTrayPacking->id}",
            [
                'mode' => 'tray', 'nos_per_tray' => 99, 'trays_per_box' => 5, 'nos_per_box' => 495,
                'item_id' => $this->separateProduct->id,
            ],
        )->assertOk();

        $tray = $this->legacyTrayPacking->fresh();
        $this->assertSame(99, (int) $tray->nos_per_tray);
        $this->assertSame($this->separateProduct->id, (int) $tray->item_id);

        // (c) ...but moving it to a THIRD item is a new distinct assignment
        //     and is still refused.
        $this->patchJson(
            "/api/v1/production/standards/{$this->standard->id}/packagings/{$this->legacyTrayPacking->id}/identity",
            ['item_id' => $this->thirdProduct->id],
        )->assertStatus(422);
        $this->assertSame($this->separateProduct->id, (int) $this->legacyTrayPacking->fresh()->item_id);

        // (d) ...and clearing back to the product's own item — the one edit
        //     that migrates a legacy row TOWARD the rule — still works.
        $this->patchJson(
            "/api/v1/production/standards/{$this->standard->id}/packagings/{$this->legacyTrayPacking->id}/identity",
            ['item_id' => null],
        )->assertOk();
        $this->assertNull($this->legacyTrayPacking->fresh()->item_id);
    }

    public function test_the_service_refuses_a_conflicting_identity_even_without_the_form_request(): void
    {
        // The FormRequest guard gives the person the good message; it is not
        // the durable half. A caller reaching the service directly — or one
        // whose request validated a moment before the standard was attached —
        // must still be refused, behind the same lock the writer takes.
        $service = app(ProductionStandardPackagingService::class);

        $this->expectException(ValidationException::class);
        $service->setIdentity($this->standard, $this->pouchPacking, $this->thirdProduct->id, null);
    }

    public function test_creating_a_standard_with_an_item_cannot_produce_a_conflict(): void
    {
        // PROVEN, not assumed. ProductionStandardService::create() writes
        // item_id, so it is a writer — but the standard is created in the same
        // transaction as its packagings, and those come from
        // ProductionStandardImportService::packagings(), which writes mode and
        // counts and never item_id. There is nothing for the new product to
        // contradict. If a later change lets create() carry packaging
        // identities, this fails and the guard has to be added there too.
        $this->actingAsProduction();

        $this->postJson('/api/v1/production/standards', [
            'source_product_name' => '200ML RA HAND ADDED',
            'item_id' => $this->bottle->id,
            'cavities' => 8, 'unit_weight_grams' => '18.0000', 'cycle_time' => '12.00',
            'nos_per_tray' => 98, 'tray_nos_per_box' => 490,
            'nos_per_pouch' => 130, 'pouch_nos_per_box' => 520,
        ])->assertCreated();

        $created = ProductionStandard::query()
            ->where('source_product_name', '200ML RA HAND ADDED')->firstOrFail();

        $this->assertSame($this->bottle->id, (int) $created->item_id);
        $this->assertTrue($created->packagings()->count() > 0);
        $this->assertSame(0, $created->packagings()->whereNotNull('item_id')->count());
        $this->assertNull(ProductVariantService::firstPackagingConflictingWithProduct($created, $this->bottle->id));
    }

    public function test_the_importer_refuses_to_adopt_an_orphan_whose_packing_is_a_separate_product(): void
    {
        $this->actingAsProduction();

        // The real sequence the adopt block exists for, played out in order.
        //
        // (1) The sheet imports a mould the catalogue cannot name yet, so the
        //     standard lands UNATTACHED — what production_standards.item_id
        //     is nullable for.
        $rows = [[
            'sl_no' => 900,
            'product' => '200ML RA TRAY',
            'cavities' => '8',
            'unit_weight_grams' => '18',
            'cycle_time' => '12',
            'nos_per_tray' => '98',
            'tray_nos_per_box' => '490',
            'trays_per_box' => '5',
        ]];

        app(ProductionStandardImportService::class)->import($rows, false, null);

        $orphan = ProductionStandard::query()->whereNull('item_id')
            ->where('source_reference', '900')->firstOrFail();
        $packaging = $orphan->packagings()->firstOrFail();

        // (2) A person gives that packing its own Tally identity. PERMITTED,
        //     through the endpoint, because the standard has no product to
        //     contradict — and that permission is what makes step (4)
        //     reachable at all.
        $this->patchJson(
            "/api/v1/production/standards/{$orphan->id}/packagings/{$packaging->id}/identity",
            ['item_id' => $this->separateProduct->id],
        )->assertOk();

        // (3) The catalogue later gains the name the importer was missing...
        Item::create([
            'sku' => 'BTL-200RA-TRAY', 'name' => '200ML RA TRAY', 'uom' => 'NOS',
            'is_active' => true, 'tally_stock_item_guid' => 'itm-200ra-tray',
        ]);

        // (4) ...so the next import matches, and would ADOPT the orphan into
        //     that product — building the forbidden mismatch from the product
        //     side, with no packaging writer involved.
        $result = app(ProductionStandardImportService::class)->import($rows, false, null);

        // REFUSED and NAMED — not silently skipped, and counted apart from
        // packaging_warnings, which reports a different problem entirely.
        $this->assertSame(1, $result['summary']['separate_product_refusals']);
        $refusal = $result['variants'][0]['separate_product_refusals'][0];
        $this->assertStringContainsString($this->separateProduct->name, $refusal);
        $this->assertStringContainsString('200ML RA TRAY', $refusal);
        $this->assertStringContainsString(ProductVariantService::SEPARATE_PRODUCT_INSTRUCTION, $refusal);

        // The orphan is untouched: still unattached, still carrying its
        // packing's identity, still maintainable. An import migrates nothing.
        $orphan->refresh();
        $this->assertNull($orphan->item_id);
        $this->assertSame($this->separateProduct->id, (int) $packaging->fresh()->item_id);

        // And whatever the import DID write is free of the forbidden shape —
        // including the fresh item-keyed row it created instead of adopting.
        // Scoped to this sheet's rows: the legacy fixture from setUp() is
        // deliberately in the forbidden shape and is deliberately left there.
        $writtenRows = ProductionStandard::query()
            ->where('source_reference', '900')->whereNotNull('item_id')->get();

        $this->assertNotEmpty($writtenRows, 'the import wrote no attached row at all');

        foreach ($writtenRows as $written) {
            $this->assertNull(
                ProductVariantService::firstPackagingConflictingWithProduct($written, (int) $written->item_id),
                "standard #{$written->id} was left in the state DEC-20260821-001 forbids",
            );
        }
    }

    /**
     * The importer's adopt block reads the orphan's packagings and then
     * writes its `item_id`. A packaging identity write reads the standard's
     * `item_id` and then writes a packing's. The transaction the import
     * already ran in does NOT serialise those two — each passes its own read
     * and the pair commits a state neither writer allows. One shared row
     * lock does, and this pins that the importer takes it, on the right row,
     * at the right moment.
     *
     * WHAT THIS PROVES AND WHAT IT DOES NOT. PHPUnit runs one connection
     * against SQLite, whose grammar silently discards `FOR UPDATE`, so no
     * test here executes two simultaneous transactions — the same limit
     * StartBatchConcurrencyTest states about the machine lock. Asserted
     * instead: the ORDER of the reads (a lock taken after the packagings
     * read protects nothing), that the locking read is scoped to the ONE
     * candidate row and still carries the orphan predicate, that it happens
     * inside a transaction that is still open when `item_id` is written,
     * and — in the test below — that the builder really carries the lock and
     * really compiles to `FOR UPDATE` under the production grammar. Genuine
     * parallel verification against MySQL is a deployment-time check.
     */
    public function test_the_importer_locks_the_orphan_row_before_reading_its_packagings_or_writing_the_item(): void
    {
        $rows = [[
            'sl_no' => 901,
            'product' => '200ML RA CLEAN ADOPT',
            'cavities' => '8',
            'unit_weight_grams' => '18',
            'cycle_time' => '12',
            'nos_per_tray' => '98',
            'tray_nos_per_box' => '490',
            'trays_per_box' => '5',
        ]];

        // An orphan with NO packaging identity, so the adoption is compliant
        // and actually proceeds to write item_id — the ordering has to hold
        // on the path that writes, not only on the path that refuses.
        app(ProductionStandardImportService::class)->import($rows, false, null);

        Item::create([
            'sku' => 'BTL-200RA-CLEAN', 'name' => '200ML RA CLEAN ADOPT', 'uom' => 'NOS',
            'is_active' => true, 'tally_stock_item_guid' => 'itm-200ra-clean',
        ]);

        // Transaction depth AT THE MOMENT each query ran. A lock taken in its
        // own committed transaction is released before the write it is meant
        // to protect, which is the failure this catches.
        $depths = [];
        DB::listen(function ($query) use (&$depths) {
            $depths[] = DB::transactionLevel();
        });

        DB::enableQueryLog();
        app(ProductionStandardImportService::class)->import($rows, false, null);
        // Identifiers normalised to one quoting: sqlite writes
        // "production_standards", MySQL `production_standards`.
        $log = collect(DB::getQueryLog())->pluck('query')->map(fn ($q) => strtolower(str_replace('`', '"', $q)));
        DB::disableQueryLog();

        $isStandard = fn (string $q) => str_starts_with($q, 'select') && str_contains($q, 'from "production_standards"');
        $orphanPredicate = fn (string $q) => str_contains($q, '"item_id" is null')
            && str_contains($q, '"source_product_name" = ?')
            && str_contains($q, '"cavities" = ?')
            && str_contains($q, '"unit_weight_grams" = ?')
            && str_contains($q, '"cycle_time" = ?');
        $byKey = fn (string $q) => str_contains($q, '"production_standards"."id" = ?');

        // The unlocked candidate lookup: the orphan predicate, no key.
        $candidate = $log->search(fn (string $q) => $isStandard($q) && $orphanPredicate($q) && ! $byKey($q));
        // The locking re-read: SAME predicate, narrowed to that one row.
        $locked = $log->search(fn (string $q) => $isStandard($q) && $orphanPredicate($q) && $byKey($q));
        // The product-side judgment reading the orphan's packagings.
        $packagings = $log->search(fn (string $q) => str_starts_with($q, 'select')
            && str_contains($q, 'from "production_standard_packagings"')
            && str_contains($q, '"item_id" is not null'));
        // And the write the whole thing exists to protect.
        $write = $log->search(fn (string $q) => str_starts_with($q, 'update "production_standards"')
            && str_contains($q, '"item_id" = ?'));

        $this->assertNotFalse($candidate, 'The orphan candidate lookup is gone.');
        $this->assertNotFalse($locked, 'The orphan row is never re-read by key — there is no single-row lock to serialise on.');
        $this->assertNotFalse($packagings, 'The product-side conflict check never read the packagings.');
        $this->assertNotFalse($write, 'The adoption never wrote item_id, so this asserts nothing about the write path.');

        $this->assertLessThan($locked, $candidate, 'The locking re-read must follow the candidate lookup it narrows.');
        $this->assertLessThan(
            $packagings,
            $locked,
            'The packagings are read BEFORE the row is locked — a lock taken afterwards protects nothing.',
        );
        $this->assertLessThan($write, $locked, 'item_id is written before the row is locked.');

        // Same open transaction for the lock and the write: a commit in
        // between would drop the lock and reopen the race.
        $this->assertGreaterThan(0, $depths[$locked] ?? 0, 'The lock was taken outside any transaction.');
        $this->assertGreaterThan(0, $depths[$write] ?? 0, 'item_id was written outside the transaction that holds the lock.');
    }

    public function test_the_adoption_lock_is_a_real_row_lock_under_the_production_grammar(): void
    {
        // The half SQLite cannot show. Without this, `lockForUpdate()` could
        // be deleted from the service and every assertion above would still
        // pass, because SQLite's grammar emits nothing for it either way.
        $variant = [
            'source_product_name' => '200ML RA TRAY',
            'cavities' => 8,
            'unit_weight_grams' => '18.0000',
            'cycle_time' => '12.00',
        ];

        $build = new \ReflectionMethod(ProductionStandardImportService::class, 'orphanQuery');
        $service = new ProductionStandardImportService;

        // The candidate lookup must NOT lock: its predicate is an IS NULL on
        // the leading column of the standards unique index, so locking it
        // would lock every unattached standard in the table.
        $unlocked = $build->invoke($service, $variant)->toBase();
        $this->assertNotTrue($unlocked->lock, 'The unkeyed candidate lookup locks — that is a table-wide lock, not a row lock.');

        // The keyed re-read must, and must still carry the orphan predicate:
        // a lock that re-fetched the row without re-testing `item_id IS NULL`
        // would adopt a standard another writer had already attached.
        $keyed = $build->invoke($service, $variant, 7)->toBase();
        $this->assertTrue($keyed->lock, 'lockForUpdate() is not registered on the keyed orphan re-read.');

        $connection = DB::connection();
        $keyed->grammar = new MySqlGrammar($connection);
        $sql = strtolower($keyed->toSql());

        $this->assertStringContainsString('for update', $sql);
        $this->assertStringContainsString('`item_id` is null', $sql);
        $this->assertStringContainsString('`production_standards`.`id` = ?', $sql);
    }

    public function test_the_product_side_judgment_reads_the_packagings_under_a_lock_too(): void
    {
        // The standard's row lock buys MUTUAL EXCLUSION; it does not buy this
        // read FRESHNESS. Live runs MySQL at the server default REPEATABLE
        // READ — nothing in config/database.php pins an isolation level — and
        // a plain SELECT there answers from the snapshot the transaction took
        // at its first read, which for the importer is long before the adopt
        // block (it has already indexed the catalogue and matched its
        // variants). A packing identity another transaction committed in
        // between would be invisible and the pair would commit despite the
        // lock. A locking read reads the latest committed version.
        //
        // SQLite shows none of this, so it is asserted on the builder and
        // under the production grammar, exactly as the adoption lock is.
        $build = new \ReflectionMethod(ProductVariantService::class, 'conflictingPackagingQuery');
        $query = $build->invoke(null, $this->standard, $this->bottle->id)->toBase();

        $this->assertTrue($query->lock, 'The product-side conflict check reads the packagings without a lock.');

        $connection = DB::connection();
        $query->grammar = new MySqlGrammar($connection);
        $sql = strtolower($query->toSql());

        $this->assertStringContainsString('for update', $sql);
        // Narrow: one standard's packagings, never a table scan.
        $this->assertStringContainsString('`production_standard_id` = ?', $sql);
    }
}
