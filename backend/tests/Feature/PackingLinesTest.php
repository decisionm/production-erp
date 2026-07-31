<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Multi-mode packing at Complete Batch.
 *
 * A product standard exposes ONLY the packaging modes its imported row
 * carries — a tray-packed product must never be asked about pouches, and
 * vice versa. A product whose standard carries both may use both in one
 * run (part of the shift trayed, part pouched), which makes packing a LIST
 * of lines rather than one set of tray/pouch columns, and makes "total
 * pieces = sum of the lines" the contract that has to hold.
 *
 * The dangerous failure this guards is double counting: the carton is the
 * outer package in every mode, so the same physical cartons entered under
 * two modes would post twice the production that was actually made.
 *
 * Pack sizes come from the imported nos_per_box / nos_per_tray /
 * nos_per_pouch throughout — every figure below is derived from the
 * standard, never from an assumed 5-per-box.
 */
class PackingLinesTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsProduction(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        Permission::findOrCreate('production.manage', 'web');
        $user->givePermissionTo(['production.view', 'production.manage']);
        Sanctum::actingAs($user);

        return $user;
    }

    private function item(string $sku = 'BTL-1'): Item
    {
        return Item::create([
            'sku' => $sku, 'name' => 'Bottle '.$sku, 'uom' => 'Nos.',
            'nominal_weight_grams' => '12.9000',
            'standard_cycle_time' => '12.30', 'standard_cavities' => 5,
        ]);
    }

    /**
     * A standard whose packaging rows are exactly the modes given.
     *
     * @param  array<int, array<string, mixed>>  $packagings
     */
    private function standardFor(Item $item, array $packagings): ProductionStandard
    {
        $standard = ProductionStandard::create([
            'item_id' => $item->id,
            'source_product_name' => $item->name,
            'cavities' => 5,
            'unit_weight_grams' => '12.9000',
            'cycle_time' => '12.30',
            'status' => 'approved',
            'source' => 'packing-lines-test',
        ]);

        foreach ($packagings as $packaging) {
            $standard->packagings()->create($packaging);
        }

        return $standard->fresh('packagings');
    }

    private function inProgressEntry(Item $item, ?ProductionStandard $standard = null): ShiftProductionEntry
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
        $warehouse = Warehouse::create(['code' => 'FG', 'name' => 'FG Store']);

        return ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'production_standard_id' => $standard?->id,
            'production_date' => '2026-07-28',
            'batch_number' => '20260728-M01-001',
            'batch_status' => BatchStatus::InProgress,
            'quantity_produced' => null,
            'quantity_scrap' => '0',
            'standard_cycle_time' => '12.30',
            'standard_cavities' => 5,
            'active_cavities' => 5,
        ]);
    }

    // -----------------------------------------------------------------
    // Which modes a product offers at all
    // -----------------------------------------------------------------

    public function test_tray_only_standard_offers_exactly_one_mode(): void
    {
        $this->actingAsProduction();
        $item = $this->item('BTL-TRAY');
        $this->standardFor($item, [
            ['mode' => ProductionStandardPackaging::MODE_TRAY,
                'nos_per_tray' => 132, 'trays_per_box' => 10, 'nos_per_box' => 1320, 'is_default' => true],
        ]);

        $response = $this->getJson('/api/v1/production/shift-production-entries/preview?item_id='.$item->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.variants')
            // One packaging row => the SPA auto-selects it and asks nothing.
            ->assertJsonCount(1, 'data.variants.0.packagings')
            ->assertJsonPath('data.variants.0.packagings.0.mode', 'tray')
            ->assertJsonPath('data.variants.0.packagings.0.nos_per_tray', 132)
            ->assertJsonPath('data.variants.0.packagings.0.nos_per_box', 1320);

        // Pouch is not merely empty — it is absent from the offer entirely.
        $modes = array_column($response->json('data.variants.0.packagings'), 'mode');
        $this->assertSame(['tray'], $modes);
    }

    public function test_pouch_only_standard_offers_exactly_one_mode(): void
    {
        $this->actingAsProduction();
        $item = $this->item('BTL-POUCH');
        $this->standardFor($item, [
            ['mode' => ProductionStandardPackaging::MODE_POUCH,
                'nos_per_pouch' => 245, 'pouches_per_box' => 5, 'nos_per_box' => 1225, 'is_default' => true],
        ]);

        $response = $this->getJson('/api/v1/production/shift-production-entries/preview?item_id='.$item->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.variants.0.packagings')
            ->assertJsonPath('data.variants.0.packagings.0.mode', 'pouch')
            ->assertJsonPath('data.variants.0.packagings.0.nos_per_pouch', 245);

        $modes = array_column($response->json('data.variants.0.packagings'), 'mode');
        $this->assertSame(['pouch'], $modes);
    }

    public function test_both_modes_standard_offers_both_for_the_supervisor_to_choose(): void
    {
        $this->actingAsProduction();
        $item = $this->item('BTL-BOTH');
        $this->standardFor($item, [
            ['mode' => ProductionStandardPackaging::MODE_POUCH,
                'nos_per_pouch' => 245, 'pouches_per_box' => 5, 'nos_per_box' => 1225],
            ['mode' => ProductionStandardPackaging::MODE_TRAY,
                'nos_per_tray' => 230, 'trays_per_box' => 5, 'nos_per_box' => 1150],
        ]);

        $response = $this->getJson('/api/v1/production/shift-production-entries/preview?item_id='.$item->id)
            ->assertOk()
            ->assertJsonCount(2, 'data.variants.0.packagings');

        $modes = array_column($response->json('data.variants.0.packagings'), 'mode');
        sort($modes);
        $this->assertSame(['pouch', 'tray'], $modes);
    }

    // -----------------------------------------------------------------
    // Totals across lines
    // -----------------------------------------------------------------

    public function test_both_modes_in_one_completion_sum_to_the_total_pieces(): void
    {
        $this->actingAsProduction();
        $item = $this->item('BTL-BOTH');
        $standard = $this->standardFor($item, [
            ['mode' => ProductionStandardPackaging::MODE_POUCH,
                'nos_per_pouch' => 245, 'pouches_per_box' => 5, 'nos_per_box' => 1225],
            ['mode' => ProductionStandardPackaging::MODE_TRAY,
                'nos_per_tray' => 230, 'trays_per_box' => 5, 'nos_per_box' => 1150],
        ]);
        $entry = $this->inProgressEntry($item, $standard);
        $pouch = $standard->packagings->firstWhere('mode', 'pouch');
        $tray = $standard->packagings->firstWhere('mode', 'tray');

        // 2 pouch cartons + 3 loose pouches = 2×1225 + 3×245 = 3185
        // 3 tray  cartons + 1 loose tray   = 3×1150 + 1×230 = 3680
        // plus 7 pieces loose in nothing at all              =    7
        //                                             total  = 6872
        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '6872',
            'no_of_box' => 5,
            'loose_pieces' => 7,
            'packing_lines' => [
                [
                    'mode' => 'pouch', 'production_standard_packaging_id' => $pouch->id,
                    'boxes' => 2, 'nos_per_box' => 1225, 'loose_inner' => 3, 'nos_per_inner' => 245,
                    'derived_pieces' => 3185, 'actual_pieces' => 3185,
                ],
                [
                    'mode' => 'tray', 'production_standard_packaging_id' => $tray->id,
                    'boxes' => 3, 'nos_per_box' => 1150, 'loose_inner' => 1, 'nos_per_inner' => 230,
                    'derived_pieces' => 3680, 'actual_pieces' => 3680,
                ],
            ],
        ])
            ->assertOk()
            // Numeric compare, not string: the decimal column comes back
            // "6872.0000" on MySQL and 6872 on the local sqlite.
            ->assertJsonPath('data.quantity_produced', fn ($v) => (float) $v === 6872.0)
            ->assertJsonPath('data.no_of_box', 5);

        $this->assertSame(6872.0, (float) $entry->fresh()->quantity_produced);
    }

    public function test_a_total_that_does_not_match_the_lines_is_refused(): void
    {
        // The negative half of the sum contract: without this, "the total
        // equals the sum" would be true only because the client said so.
        $this->actingAsProduction();
        $item = $this->item('BTL-BOTH');
        $standard = $this->standardFor($item, [
            ['mode' => ProductionStandardPackaging::MODE_POUCH,
                'nos_per_pouch' => 245, 'pouches_per_box' => 5, 'nos_per_box' => 1225],
            ['mode' => ProductionStandardPackaging::MODE_TRAY,
                'nos_per_tray' => 230, 'trays_per_box' => 5, 'nos_per_box' => 1150],
        ]);
        $entry = $this->inProgressEntry($item, $standard);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            // Only the pouch line's pieces — the tray line was forgotten.
            'quantity_produced' => '3185',
            'no_of_box' => 5,
            'packing_lines' => [
                ['mode' => 'pouch', 'boxes' => 2, 'nos_per_box' => 1225, 'loose_inner' => 3,
                    'nos_per_inner' => 245, 'derived_pieces' => 3185, 'actual_pieces' => 3185],
                ['mode' => 'tray', 'boxes' => 3, 'nos_per_box' => 1150, 'loose_inner' => 1,
                    'nos_per_inner' => 230, 'derived_pieces' => 3680, 'actual_pieces' => 3680],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['quantity_produced']);

        // Nothing was written: the batch is still running and re-submittable.
        $this->assertSame(BatchStatus::InProgress, $entry->fresh()->batch_status);
    }

    // -----------------------------------------------------------------
    // Double counting
    // -----------------------------------------------------------------

    public function test_counting_the_same_cartons_under_both_modes_is_refused(): void
    {
        $this->actingAsProduction();
        $item = $this->item('BTL-BOTH');
        $standard = $this->standardFor($item, [
            ['mode' => ProductionStandardPackaging::MODE_POUCH,
                'nos_per_pouch' => 245, 'pouches_per_box' => 5, 'nos_per_box' => 1225],
            ['mode' => ProductionStandardPackaging::MODE_TRAY,
                'nos_per_tray' => 230, 'trays_per_box' => 5, 'nos_per_box' => 1150],
        ]);
        $entry = $this->inProgressEntry($item, $standard);

        // 5 cartons were made. Both lines claim all 5 — the same physical
        // cartons entered twice, which would post 11875 pieces off 5 boxes.
        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '11875',
            'no_of_box' => 5,
            'packing_lines' => [
                ['mode' => 'pouch', 'boxes' => 5, 'nos_per_box' => 1225, 'loose_inner' => 0,
                    'nos_per_inner' => 245, 'derived_pieces' => 6125, 'actual_pieces' => 6125],
                ['mode' => 'tray', 'boxes' => 5, 'nos_per_box' => 1150, 'loose_inner' => 0,
                    'nos_per_inner' => 230, 'derived_pieces' => 5750, 'actual_pieces' => 5750],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['no_of_box']);

        $this->assertSame(BatchStatus::InProgress, $entry->fresh()->batch_status);
        $this->assertNull($entry->fresh()->quantity_produced);
    }

    public function test_two_lines_of_the_same_mode_are_refused(): void
    {
        $this->actingAsProduction();
        $item = $this->item('BTL-TRAY');
        $standard = $this->standardFor($item, [
            ['mode' => ProductionStandardPackaging::MODE_TRAY,
                'nos_per_tray' => 132, 'trays_per_box' => 10, 'nos_per_box' => 1320, 'is_default' => true],
        ]);
        $entry = $this->inProgressEntry($item, $standard);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '2640',
            'no_of_box' => 2,
            'packing_lines' => [
                ['mode' => 'tray', 'boxes' => 1, 'nos_per_box' => 1320, 'derived_pieces' => 1320, 'actual_pieces' => 1320],
                ['mode' => 'tray', 'boxes' => 1, 'nos_per_box' => 1320, 'derived_pieces' => 1320, 'actual_pieces' => 1320],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['packing_lines.1.mode']);
    }

    public function test_a_packing_option_from_another_product_standard_is_refused(): void
    {
        $this->actingAsProduction();
        $mine = $this->item('BTL-MINE');
        $theirs = $this->item('BTL-THEIRS');
        $myStandard = $this->standardFor($mine, [
            ['mode' => ProductionStandardPackaging::MODE_TRAY,
                'nos_per_tray' => 132, 'trays_per_box' => 10, 'nos_per_box' => 1320],
        ]);
        $otherStandard = $this->standardFor($theirs, [
            ['mode' => ProductionStandardPackaging::MODE_POUCH,
                'nos_per_pouch' => 245, 'pouches_per_box' => 5, 'nos_per_box' => 1225],
        ]);
        $entry = $this->inProgressEntry($mine, $myStandard);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '1225',
            'no_of_box' => 1,
            'packing_lines' => [[
                'mode' => 'pouch',
                'production_standard_packaging_id' => $otherStandard->packagings->first()->id,
                'boxes' => 1, 'nos_per_box' => 1225, 'derived_pieces' => 1225, 'actual_pieces' => 1225,
            ]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['packing_lines.0.production_standard_packaging_id']);
    }

    // -----------------------------------------------------------------
    // Overrides
    // -----------------------------------------------------------------

    public function test_an_actual_count_below_the_derived_one_needs_a_reason(): void
    {
        $this->actingAsProduction();
        $item = $this->item('BTL-TRAY');
        $standard = $this->standardFor($item, [
            ['mode' => ProductionStandardPackaging::MODE_TRAY,
                'nos_per_tray' => 132, 'trays_per_box' => 10, 'nos_per_box' => 1320],
        ]);
        $entry = $this->inProgressEntry($item, $standard);

        $payload = [
            'quantity_produced' => '1300',
            'no_of_box' => 1,
            'packing_lines' => [[
                'mode' => 'tray', 'boxes' => 1, 'nos_per_box' => 1320,
                'derived_pieces' => 1320, 'actual_pieces' => 1300,
            ]],
        ];

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['packing_lines.0.override_reason']);

        // Same numbers, now explained — accepted, and the total follows the
        // counted figure rather than the pack-size arithmetic.
        $payload['packing_lines'][0]['override_reason'] = 'Short box — 20 pieces held back for QC';

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", $payload)
            ->assertOk()
            ->assertJsonPath('data.quantity_produced', fn ($v) => (float) $v === 1300.0);
    }

    public function test_a_wrong_derived_figure_cannot_disguise_an_override(): void
    {
        // derived_pieces is recomputed server-side, so sending
        // derived == actual does not buy a reason-free override.
        $this->actingAsProduction();
        $item = $this->item('BTL-TRAY');
        $standard = $this->standardFor($item, [
            ['mode' => ProductionStandardPackaging::MODE_TRAY,
                'nos_per_tray' => 132, 'trays_per_box' => 10, 'nos_per_box' => 1320],
        ]);
        $entry = $this->inProgressEntry($item, $standard);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '1300',
            'no_of_box' => 1,
            'packing_lines' => [[
                'mode' => 'tray', 'boxes' => 1, 'nos_per_box' => 1320,
                'derived_pieces' => 1300, 'actual_pieces' => 1300,
            ]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['packing_lines.0.derived_pieces']);
    }

    // -----------------------------------------------------------------
    // Tray-first counting
    //
    // The floor counts TRAYS, not cartons: "5 tray = 1 carton boxes, then
    // 600 units based on 120 PER TRAY, SO FIVE TRAY SO 600". The SPA turns
    // a tray count into the pair this API already speaks — whole cartons in
    // `boxes`, the trays over in `loose_inner` — so these rules need no
    // change at all. The three tests below are what says so out loud:
    // a full carton, a part carton, and a count that does not add up.
    // -----------------------------------------------------------------

    public function test_five_trays_of_120_is_one_carton_of_600_pieces(): void
    {
        // The owner's own example, exactly: 5 trays × 120 = 600 pieces and
        // 1 carton, with the carton DERIVED from the tray count.
        $this->actingAsProduction();
        $item = $this->item('BTL-170');
        $standard = $this->standardFor($item, [
            ['mode' => ProductionStandardPackaging::MODE_TRAY,
                'nos_per_tray' => 120, 'trays_per_box' => 5, 'nos_per_box' => 600],
        ]);
        $entry = $this->inProgressEntry($item, $standard);
        $tray = $standard->packagings->firstWhere('mode', 'tray');

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '600',
            'no_of_box' => 1,
            'packing_lines' => [[
                'mode' => 'tray', 'production_standard_packaging_id' => $tray->id,
                'boxes' => 1, 'nos_per_box' => 600, 'loose_inner' => 0, 'nos_per_inner' => 120,
                'derived_pieces' => 600, 'actual_pieces' => 600,
            ]],
        ])
            ->assertOk()
            ->assertJsonPath('data.quantity_produced', fn ($v) => (float) $v === 600.0)
            ->assertJsonPath('data.no_of_box', 1);
    }

    public function test_three_trays_with_no_whole_carton_is_accepted(): void
    {
        // The case that would have forced a rules change if the API could
        // only speak in whole cartons: a shift that ends on 3 trays. They
        // ride in loose_inner, the carton total is a legitimate 0, and the
        // pieces still come to trays × 120.
        $this->actingAsProduction();
        $item = $this->item('BTL-170');
        $standard = $this->standardFor($item, [
            ['mode' => ProductionStandardPackaging::MODE_TRAY,
                'nos_per_tray' => 120, 'trays_per_box' => 5, 'nos_per_box' => 600],
        ]);
        $entry = $this->inProgressEntry($item, $standard);
        $tray = $standard->packagings->firstWhere('mode', 'tray');

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '360',
            'no_of_box' => 0,
            'packing_lines' => [[
                'mode' => 'tray', 'production_standard_packaging_id' => $tray->id,
                'boxes' => 0, 'nos_per_box' => 600, 'loose_inner' => 3, 'nos_per_inner' => 120,
                'derived_pieces' => 360, 'actual_pieces' => 360,
            ]],
        ])
            ->assertOk()
            ->assertJsonPath('data.quantity_produced', fn ($v) => (float) $v === 360.0)
            ->assertJsonPath('data.no_of_box', 0);
    }

    public function test_seven_trays_is_one_carton_plus_two_trays_over(): void
    {
        // 1 × 600 + 2 × 120 = 840 — the "+ 2 trays over" the drawer shows.
        $this->actingAsProduction();
        $item = $this->item('BTL-170');
        $standard = $this->standardFor($item, [
            ['mode' => ProductionStandardPackaging::MODE_TRAY,
                'nos_per_tray' => 120, 'trays_per_box' => 5, 'nos_per_box' => 600],
        ]);
        $entry = $this->inProgressEntry($item, $standard);
        $tray = $standard->packagings->firstWhere('mode', 'tray');

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '840',
            'no_of_box' => 1,
            'packing_lines' => [[
                'mode' => 'tray', 'production_standard_packaging_id' => $tray->id,
                'boxes' => 1, 'nos_per_box' => 600, 'loose_inner' => 2, 'nos_per_inner' => 120,
                'derived_pieces' => 840, 'actual_pieces' => 840,
            ]],
        ])
            ->assertOk()
            ->assertJsonPath('data.quantity_produced', fn ($v) => (float) $v === 840.0)
            ->assertJsonPath('data.no_of_box', 1);
    }

    public function test_a_tray_count_whose_pieces_do_not_add_up_is_still_refused(): void
    {
        // The other half: counting in trays buys no relaxation. A batch
        // total that is not the lines' pieces is still a 422, and so is a
        // carton total that is not the lines' cartons.
        $this->actingAsProduction();
        $item = $this->item('BTL-170');
        $standard = $this->standardFor($item, [
            ['mode' => ProductionStandardPackaging::MODE_TRAY,
                'nos_per_tray' => 120, 'trays_per_box' => 5, 'nos_per_box' => 600],
        ]);
        $entry = $this->inProgressEntry($item, $standard);

        // 5 trays is 600 pieces — 720 is a tray count someone re-typed.
        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '720',
            'no_of_box' => 1,
            'packing_lines' => [[
                'mode' => 'tray', 'boxes' => 1, 'nos_per_box' => 600, 'loose_inner' => 0,
                'nos_per_inner' => 120, 'derived_pieces' => 600, 'actual_pieces' => 600,
            ]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['quantity_produced']);

        // 7 trays = 1 carton, not 2 — cartons still have to be stated once
        // and add up.
        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '840',
            'no_of_box' => 2,
            'packing_lines' => [[
                'mode' => 'tray', 'boxes' => 1, 'nos_per_box' => 600, 'loose_inner' => 2,
                'nos_per_inner' => 120, 'derived_pieces' => 840, 'actual_pieces' => 840,
            ]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['no_of_box']);

        $this->assertSame(BatchStatus::InProgress, $entry->fresh()->batch_status);
    }

    // -----------------------------------------------------------------
    // Backwards compatibility + the approval-side preview
    // -----------------------------------------------------------------

    public function test_a_completion_without_packing_lines_is_unchanged(): void
    {
        // Products with no imported standard still complete exactly as they
        // did before packing lines existed — none of the new rules fire.
        $this->actingAsProduction();
        $entry = $this->inProgressEntry($this->item('BTL-LEGACY'));

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '420',
            'nos_per_tray' => 84,
            'no_of_trays' => 5,
        ])
            ->assertOk()
            ->assertJsonPath('data.quantity_produced', fn ($v) => (float) $v === 420.0)
            ->assertJsonPath('data.no_of_trays', 5);
    }

    public function test_the_voucher_preview_creates_no_tally_sync_rows(): void
    {
        // The approval screen shows the voucher Tally WILL receive. Looking
        // at it must never queue or post anything — the accountant's
        // approval is the only thing that may.
        $this->actingAsProduction();
        $item = $this->item('BTL-TRAY');
        $standard = $this->standardFor($item, [
            ['mode' => ProductionStandardPackaging::MODE_TRAY,
                'nos_per_tray' => 132, 'trays_per_box' => 10, 'nos_per_box' => 1320],
        ]);
        $entry = $this->inProgressEntry($item, $standard);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '1320',
            'no_of_box' => 1,
            'packing_lines' => [[
                'mode' => 'tray', 'boxes' => 1, 'nos_per_box' => 1320,
                'derived_pieces' => 1320, 'actual_pieces' => 1320,
            ]],
        ])->assertOk();

        $this->assertSame(0, TallySyncEntry::query()->count());

        $this->getJson("/api/v1/production/shift-production-entries/{$entry->id}/voucher-preview")
            ->assertOk()
            ->assertJsonStructure(['data' => ['voucher', 'lines', 'problems', 'postable']]);

        $this->assertSame(0, TallySyncEntry::query()->count());
    }
}
