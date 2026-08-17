<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\ShiftProductionEntryPackingLine;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ShiftProductionEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 5: packing_lines are PERSISTED (§4.16 closed).
 *
 * CompleteBatchRequest validated the lines in full — one line per mode,
 * derived pieces recomputed, the carton total and the piece total forced
 * to add up — and then completeBatch() threw them away: no table, no read.
 * A supervisor who typed "2 pouch cartons + 3 loose pouches, 3 tray cartons
 * + 1 loose tray" left behind a single quantity_produced and no record of
 * how it was packed.
 *
 * Now what was validated is what is stored, in the completion's own
 * transaction, replaced on amendment, and read back on the entry resource.
 */
class PackingLinesPersistTest extends TestCase
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

    /** @param  array<int, array<string, mixed>>  $packagings */
    private function standardFor(Item $item, array $packagings): ProductionStandard
    {
        $standard = ProductionStandard::create([
            'item_id' => $item->id,
            'source_product_name' => $item->name,
            'cavities' => 5,
            'unit_weight_grams' => '12.9000',
            'cycle_time' => '12.30',
            'status' => 'approved',
            'source' => 'packing-lines-persist-test',
        ]);

        foreach ($packagings as $packaging) {
            $standard->packagings()->create($packaging);
        }

        return $standard->fresh('packagings');
    }

    private function inProgressEntry(Item $item, ?ProductionStandard $standard = null): ShiftProductionEntry
    {
        $shift = Shift::firstOrCreate(['name' => 'Morning'], ['start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::firstOrCreate(['code' => 'MC-01'], ['name' => 'Machine 1']);
        $warehouse = Warehouse::firstOrCreate(['code' => 'FG'], ['name' => 'FG Store']);

        return ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'production_standard_id' => $standard?->id,
            'production_date' => '2026-08-17',
            'batch_number' => '20260817-M01-'.str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT),
            'batch_status' => BatchStatus::InProgress,
            'quantity_produced' => null,
            'quantity_scrap' => '0',
            'standard_cycle_time' => '12.30',
            'standard_cavities' => 5,
            'active_cavities' => 5,
        ]);
    }

    /** @return array{0: ProductionStandard, 1: ProductionStandardPackaging, 2: ProductionStandardPackaging} */
    private function bothModes(Item $item): array
    {
        $standard = $this->standardFor($item, [
            ['mode' => ProductionStandardPackaging::MODE_POUCH,
                'nos_per_pouch' => 245, 'pouches_per_box' => 5, 'nos_per_box' => 1225],
            ['mode' => ProductionStandardPackaging::MODE_TRAY,
                'nos_per_tray' => 230, 'trays_per_box' => 5, 'nos_per_box' => 1150],
        ]);

        return [
            $standard,
            $standard->packagings->firstWhere('mode', 'pouch'),
            $standard->packagings->firstWhere('mode', 'tray'),
        ];
    }

    // ------------------------------------------ what was validated is stored ---

    public function test_the_validated_packing_lines_are_stored_line_for_line_and_read_back(): void
    {
        $this->actingAsProduction();
        $item = $this->item('BTL-BOTH');
        [$standard, $pouch, $tray] = $this->bothModes($item);
        $entry = $this->inProgressEntry($item, $standard);

        // 2 pouch cartons + 3 loose pouches = 2×1225 + 3×245 = 3185
        // 3 tray  cartons + 1 loose tray   = 3×1150 + 1×230 = 3680
        // plus 7 pieces loose in nothing at all              =    7
        //                                             total  = 6872
        $response = $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
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
        ])->assertOk();

        // Stored — every validated figure, in the order typed.
        $this->assertDatabaseHas('shift_production_entry_packing_lines', [
            'shift_production_entry_id' => $entry->id, 'position' => 0,
            'mode' => 'pouch', 'production_standard_packaging_id' => $pouch->id,
            'boxes' => 2, 'nos_per_box' => 1225, 'loose_inner' => 3, 'nos_per_inner' => 245,
            'derived_pieces' => 3185, 'actual_pieces' => 3185, 'override_reason' => null,
        ]);
        $this->assertDatabaseHas('shift_production_entry_packing_lines', [
            'shift_production_entry_id' => $entry->id, 'position' => 1,
            'mode' => 'tray', 'production_standard_packaging_id' => $tray->id,
            'boxes' => 3, 'nos_per_box' => 1150, 'loose_inner' => 1, 'nos_per_inner' => 230,
            'derived_pieces' => 3680, 'actual_pieces' => 3680,
        ]);
        $this->assertSame(2, ShiftProductionEntryPackingLine::query()->where('shift_production_entry_id', $entry->id)->count());

        // Read back on the completion response itself…
        $response->assertJsonCount(2, 'data.packing_lines')
            ->assertJsonPath('data.packing_lines.0.mode', 'pouch')
            ->assertJsonPath('data.packing_lines.0.production_standard_packaging_id', $pouch->id)
            ->assertJsonPath('data.packing_lines.0.boxes', 2)
            ->assertJsonPath('data.packing_lines.0.nos_per_box', 1225)
            ->assertJsonPath('data.packing_lines.0.loose_inner', 3)
            ->assertJsonPath('data.packing_lines.0.nos_per_inner', 245)
            ->assertJsonPath('data.packing_lines.0.derived_pieces', 3185)
            ->assertJsonPath('data.packing_lines.0.actual_pieces', 3185)
            ->assertJsonPath('data.packing_lines.0.override_reason', null)
            ->assertJsonPath('data.packing_lines.1.mode', 'tray')
            ->assertJsonPath('data.packing_lines.1.actual_pieces', 3680);

        // …and on the list every later screen reads.
        $this->getJson('/api/v1/production/shift-production-entries?status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.id', $entry->id)
            ->assertJsonCount(2, 'data.0.packing_lines')
            ->assertJsonPath('data.0.packing_lines.1.mode', 'tray');
    }

    public function test_an_override_reason_is_stored_with_its_line(): void
    {
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
                'derived_pieces' => 1320, 'actual_pieces' => 1300,
                'override_reason' => 'Short box — 20 pieces held back for QC',
            ]],
        ])
            ->assertOk()
            ->assertJsonPath('data.packing_lines.0.derived_pieces', 1320)
            ->assertJsonPath('data.packing_lines.0.actual_pieces', 1300)
            ->assertJsonPath('data.packing_lines.0.override_reason', 'Short box — 20 pieces held back for QC')
            // A line that cites no packaging option and has no inner
            // container stores nulls, not zeros: "not stated" is a fact.
            ->assertJsonPath('data.packing_lines.0.production_standard_packaging_id', null)
            ->assertJsonPath('data.packing_lines.0.loose_inner', null)
            ->assertJsonPath('data.packing_lines.0.nos_per_inner', null);
    }

    // ------------------------------------------------ nothing, when nothing ---

    public function test_a_completion_without_packing_lines_stores_none_and_reads_an_empty_list(): void
    {
        $this->actingAsProduction();
        $entry = $this->inProgressEntry($this->item('BTL-LEGACY'));

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '420',
            'nos_per_tray' => 84,
            'no_of_trays' => 5,
        ])
            ->assertOk()
            ->assertJsonPath('data.packing_lines', []);

        $this->assertSame(0, ShiftProductionEntryPackingLine::query()->count());
    }

    public function test_a_refused_completion_stores_no_lines(): void
    {
        // The lines are written INSIDE the completion transaction: a 422
        // (here, a total that is not the lines' sum) leaves no orphan rows.
        $this->actingAsProduction();
        $item = $this->item('BTL-BOTH');
        [$standard] = $this->bothModes($item);
        $entry = $this->inProgressEntry($item, $standard);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '3185',
            'no_of_box' => 5,
            'packing_lines' => [
                ['mode' => 'pouch', 'boxes' => 2, 'nos_per_box' => 1225, 'loose_inner' => 3,
                    'nos_per_inner' => 245, 'derived_pieces' => 3185, 'actual_pieces' => 3185],
                ['mode' => 'tray', 'boxes' => 3, 'nos_per_box' => 1150, 'loose_inner' => 1,
                    'nos_per_inner' => 230, 'derived_pieces' => 3680, 'actual_pieces' => 3680],
            ],
        ])->assertStatus(422);

        $this->assertSame(BatchStatus::InProgress, $entry->fresh()->batch_status);
        $this->assertSame(0, ShiftProductionEntryPackingLine::query()->count());
    }

    // ------------------------------------------------- replaced on amendment ---

    public function test_an_amendment_replaces_the_lines_rather_than_appending(): void
    {
        $user = $this->actingAsProduction();
        $item = $this->item('BTL-BOTH');
        [$standard, $pouch, $tray] = $this->bothModes($item);
        $entry = $this->inProgressEntry($item, $standard);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '6872',
            'no_of_box' => 5,
            'loose_pieces' => 7,
            'packing_lines' => [
                ['mode' => 'pouch', 'production_standard_packaging_id' => $pouch->id,
                    'boxes' => 2, 'nos_per_box' => 1225, 'loose_inner' => 3, 'nos_per_inner' => 245,
                    'derived_pieces' => 3185, 'actual_pieces' => 3185],
                ['mode' => 'tray', 'production_standard_packaging_id' => $tray->id,
                    'boxes' => 3, 'nos_per_box' => 1150, 'loose_inner' => 1, 'nos_per_inner' => 230,
                    'derived_pieces' => 3680, 'actual_pieces' => 3680],
            ],
        ])->assertOk();

        // The correction: it was all trays after all — 5 cartons + 1 loose
        // tray = 5×1150 + 230 = 5980, no pouch line, no loose pieces.
        $amended = app(ShiftProductionEntryService::class)->amendCompletion(
            $entry->fresh(),
            [
                'quantity_produced' => '5980',
                'no_of_box' => 5,
                'packing_lines' => [
                    ['mode' => 'tray', 'production_standard_packaging_id' => $tray->id,
                        'boxes' => 5, 'nos_per_box' => 1150, 'loose_inner' => 1, 'nos_per_inner' => 230,
                        'derived_pieces' => 5980, 'actual_pieces' => 5980],
                ],
            ],
            $user->id,
        );

        $lines = ShiftProductionEntryPackingLine::query()
            ->where('shift_production_entry_id', $entry->id)
            ->orderBy('position')
            ->get();

        $this->assertCount(1, $lines, 'the wrong completion\'s lines go with it — one standing set, never two');
        $this->assertSame('tray', $lines->first()->mode);
        $this->assertSame(5, $lines->first()->boxes);
        $this->assertSame(5980, $lines->first()->actual_pieces);
        $this->assertSame(0, $lines->first()->position);

        // The relation the resource reads is the standing set too.
        $this->assertCount(1, $amended->packingLines);

        // And an amendment that types NO lines leaves none — the correction
        // is a completion retyped, and a completion without lines stores none.
        app(ShiftProductionEntryService::class)->amendCompletion(
            $entry->fresh(),
            ['quantity_produced' => '5980', 'nos_per_tray' => 230, 'no_of_trays' => 26],
            $user->id,
        );

        $this->assertSame(0, ShiftProductionEntryPackingLine::query()->where('shift_production_entry_id', $entry->id)->count());
    }

    public function test_the_lines_belong_to_their_entry(): void
    {
        $this->actingAsProduction();
        $item = $this->item('BTL-TRAY');
        $standard = $this->standardFor($item, [
            ['mode' => ProductionStandardPackaging::MODE_TRAY,
                'nos_per_tray' => 120, 'trays_per_box' => 5, 'nos_per_box' => 600],
        ]);
        $entry = $this->inProgressEntry($item, $standard);
        $tray = $standard->packagings->first();

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '840',
            'no_of_box' => 1,
            'packing_lines' => [[
                'mode' => 'tray', 'production_standard_packaging_id' => $tray->id,
                'boxes' => 1, 'nos_per_box' => 600, 'loose_inner' => 2, 'nos_per_inner' => 120,
                'derived_pieces' => 840, 'actual_pieces' => 840,
            ]],
        ])->assertOk();

        $line = ShiftProductionEntryPackingLine::query()->sole();
        $this->assertSame($entry->id, (int) $line->entry->id);
        $this->assertSame($tray->id, (int) $line->packaging->id);
        $this->assertSame([$line->id], $entry->fresh()->packingLines->pluck('id')->all());
    }
}
