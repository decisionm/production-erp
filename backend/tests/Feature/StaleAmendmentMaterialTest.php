<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Models\Enums\ShiftScrapType;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftMaterialConsumption;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE STALE CORRECTION — the bug the browser smoke test caught, and the
 * refusal that makes it unsubmittable.
 *
 * WHAT HAPPENED. The correction drawer opens with the stored material kg
 * already in its boxes and latches them (a loaded figure is a WEIGHED figure
 * and the estimator must not overwrite it). A supervisor then fixes the piece
 * count, watches every derived number on the panel move, and submits — and
 * the resin line that posts is the old one. The screen showed one arithmetic
 * and the batch got another.
 *
 * WHAT THE SERVER DOES ABOUT IT, and what it deliberately does NOT do. It
 * does not recompute: this module is advisory-by-construction — the submitted
 * figure is the figure, and a server that quietly replaced it would be
 * inventing consumption on a shift nobody could audit. It refuses, naming
 * both figures, and the supervisor decides. That is the choice they were
 * never given.
 *
 * THE FIXTURE: bottle = 10.0 g, so 1,000 pieces = 10 kg exactly.
 */
class StaleAmendmentMaterialTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Item $resin;

    private Item $masterbatch;

    private Warehouse $fg;

    private Warehouse $rm;

    private Shift $shift;

    private WorkCenter $machine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'is_active' => true]);

        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg']);
        $this->rm = Warehouse::create(['code' => 'RM', 'name' => 'RM Store', 'is_active' => true, 'tally_guid' => 'gd-rm']);

        $this->resin = Item::create([
            'sku' => 'PET-IV08', 'name' => 'Billion Pet Resin IV-0.8', 'uom' => 'Kgs.',
            'is_active' => true, 'tally_stock_item_guid' => 'itm-resin',
        ]);

        // Present on every payload below, and never identified by anything:
        // it sits on BOTH sides of the guard's comparison and cancels.
        $this->masterbatch = Item::create([
            'sku' => 'MB-AMBER', 'name' => 'Master Batch Amber', 'uom' => 'Kgs.',
            'is_active' => true, 'colour' => 'Amber', 'tally_stock_item_guid' => 'itm-mb',
        ]);

        $this->bottle = Item::create([
            'sku' => 'BTL-500-AMB', 'name' => '500 ml Round Amber', 'uom' => 'Nos.',
            'is_active' => true, 'nominal_weight_grams' => '10.0000',
            'standard_cycle_time' => '12.00', 'standard_cavities' => 5, 'nos_per_box' => 800,
            'colour' => 'Amber', 'tally_stock_item_guid' => 'itm-bottle',
        ]);

        foreach ([$this->resin, $this->masterbatch] as $material) {
            app(StockMovementService::class)->recordReceipt(
                itemId: $material->id, warehouseId: $this->rm->id,
                quantity: '2000', unitCost: '50', reference: 'opening', createdBy: null,
            );
        }
    }

    private function actAsSupervisor(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['production.view', 'production.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo(['production.view', 'production.manage']);
        Sanctum::actingAs($user);

        return $user;
    }

    private function startBatch(): int
    {
        return $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => '2026-07-31',
        ])->assertOk()->json('data.id');
    }

    /**
     * A completion payload. $resinKg is stated explicitly precisely because
     * the whole point here is what happens when it does NOT follow the
     * counts.
     *
     * @return array<string, mixed>
     */
    private function figures(int $pieces, string $resinKg, string $lumpsKg = '2'): array
    {
        return [
            'quantity_produced' => (string) $pieces,
            'quantity_scrap' => '0',
            'running_hours' => '8',
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'warehouse_id' => $this->rm->id, 'quantity_issued_kg' => $resinKg],
                ['item_id' => $this->masterbatch->id, 'warehouse_id' => $this->rm->id, 'quantity_issued_kg' => '2.5'],
            ],
            'scraps' => [['type' => ShiftScrapType::Lumps->value, 'quantity_kg' => $lumpsKg]],
        ];
    }

    private function amend(int $entryId, array $payload): TestResponse
    {
        return $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/amend", $payload);
    }

    private function storedResinKg(int $entryId): string
    {
        return (string) ShiftMaterialConsumption::query()
            ->where('shift_production_entry_id', $entryId)
            ->where('item_id', $this->resin->id)
            ->value('quantity_issued_kg');
    }

    // (a) the bug ---------------------------------------------------------------

    public function test_a_correction_that_moves_the_counts_and_keeps_the_old_kilograms_is_refused(): void
    {
        $this->actAsSupervisor();
        $entryId = $this->startBatch();

        // 12,000 pieces = 120 kg + 2 kg lumps = 122 kg of resin.
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $this->figures(12000, '122'))
            ->assertOk();

        // The correction: 10,000 pieces (20 kg less of bottles) — and the
        // resin box still holding the 122 kg the drawer loaded.
        $refused = $this->amend($entryId, [
            ...$this->figures(10000, '122'),
            'amendment_reason' => 'Counted the last pallet twice',
        ])->assertStatus(422);

        // BOTH figures, named — a supervisor is being asked to decide, and
        // cannot decide against a number they cannot see.
        //
        // Both are kg-family TOTALS (122 resin + 2.5 masterbatch = 124.5),
        // which is what makes them comparable: the masterbatch sits on both
        // sides and cancels, so nothing had to work out which line is the
        // resin. 2,000 fewer bottles is 20 kg less material, so the counts
        // now imply 104.5 against the 124.5 still on the form.
        $message = $refused->json('errors.material_consumptions.0');
        $this->assertStringContainsString('104.5000 kg', $message, 'What the corrected counts imply.');
        $this->assertStringContainsString('124.5000 kg', $message, 'What the form still carries.');
        $this->assertStringContainsString('the counts changed', strtolower($message));

        // REFUSED MEANS REFUSED: the amendment never began, so the original
        // completion stands untouched — no reversal, no half-corrected batch.
        $this->assertSame(0, bccomp('122', $this->storedResinKg($entryId), 4));
        // quantity_produced rides the resource uncast: '12000' on sqlite,
        // '12000.0000' on MySQL — compared as a figure, like the kg above.
        $this->assertSame(0, bccomp('12000', (string) $this->getJson(
            '/api/v1/production/shift-production-entries?status=pending'
        )->json('data.0.quantity_produced'), 4));
    }

    public function test_the_named_figure_counts_stored_rejected_pieces_on_the_before_side(): void
    {
        // The bug this pins: rowOpenForCorrection() reads an explicit column
        // list, and quantity_scrap was not on it — so a batch that carried
        // production rejects had its before-side read as produced + null,
        // and the refusal blamed the supervisor for rejected-pieces kg that
        // never moved. Found by a person reading the figure on screen, which
        // is exactly who this message exists for.
        $this->actAsSupervisor();
        $entryId = $this->startBatch();

        // 12,000 packed + 200 rejected = 122 kg of pieces + 2 kg lumps.
        $this->postJson(
            "/api/v1/production/shift-production-entries/{$entryId}/complete",
            [...$this->figures(12000, '124'), 'quantity_scrap' => '200'],
        )->assertOk();

        // Correction: 10,000 packed, the SAME 200 rejected, kilograms untouched.
        // Only the packed count moved: −2,000 pieces = −20 kg exactly.
        $refused = $this->amend($entryId, [
            ...$this->figures(10000, '124'),
            'quantity_scrap' => '200',
            'amendment_reason' => 'Counted the last pallet twice',
        ])->assertStatus(422);

        // Stored total is 124 resin + 2.5 masterbatch = 126.5; the counts now
        // imply 106.5. A before-side that dropped the 200 stored rejects
        // would print 104.5 here — 2 kg that were never the supervisor's.
        $message = $refused->json('errors.material_consumptions.0');
        $this->assertStringContainsString('106.5000 kg', $message, 'The before side must include stored rejected pieces.');
        $this->assertStringContainsString('126.5000 kg', $message, 'What the form still carries.');
    }

    // (b) every way it must NOT fire ----------------------------------------------

    public function test_a_correction_that_moves_the_kilograms_too_goes_straight_through(): void
    {
        $this->actAsSupervisor();
        $entryId = $this->startBatch();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $this->figures(12000, '122'))
            ->assertOk();

        // The ordinary correct correction — counts and kg both restated.
        $this->amend($entryId, [
            ...$this->figures(10000, '102'),
            'amendment_reason' => 'Counted the last pallet twice',
        ])->assertOk();

        $this->assertSame(0, bccomp('102', $this->storedResinKg($entryId), 4));
    }

    public function test_a_correction_that_leaves_the_counts_alone_is_never_questioned(): void
    {
        $this->actAsSupervisor();
        $entryId = $this->startBatch();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $this->figures(12000, '122'))
            ->assertOk();

        // Fixing the helper's name, or the running hours, is not a recount —
        // the material lines are supposed to stay exactly as they were.
        $this->amend($entryId, [
            ...$this->figures(12000, '122'),
            'running_hours' => '7.5',
            'amendment_reason' => 'Hours typed wrong',
        ])->assertOk();

        $this->assertSame(0, bccomp('122', $this->storedResinKg($entryId), 4));
    }

    public function test_a_small_count_fix_is_below_the_tolerance_and_passes(): void
    {
        $this->actAsSupervisor();
        $entryId = $this->startBatch();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $this->figures(12000, '122'))
            ->assertOk();

        // 10 pieces at 10 g is 0.1 kg — under the 0.5 kg tolerance. A slip of
        // the finger is not worth interrupting a supervisor over.
        $this->amend($entryId, [...$this->figures(11990, '122')])->assertOk();

        $this->assertSame(0, bccomp('122', $this->storedResinKg($entryId), 4));
    }

    public function test_a_first_completion_is_never_gated_by_this(): void
    {
        // A first completion has no previous figure to have gone stale
        // against, and gating one would change the path every shift runs
        // through. Deliberately amend-only.
        $this->actAsSupervisor();
        $entryId = $this->startBatch();

        $this->postJson(
            "/api/v1/production/shift-production-entries/{$entryId}/complete",
            // Wildly inconsistent with the counts — and accepted, because the
            // submitted figure is the figure.
            $this->figures(12000, '5'),
        )->assertOk();

        $this->assertSame(0, bccomp('5', $this->storedResinKg($entryId), 4));
    }

    // (c) the escape hatch ---------------------------------------------------------

    public function test_a_weighed_figure_the_supervisor_stands_behind_goes_through_when_confirmed(): void
    {
        $this->actAsSupervisor();
        $entryId = $this->startBatch();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $this->figures(12000, '122'))
            ->assertOk();

        // The store really did issue 122 kg; what was wrong was the piece
        // count. Confirmed, the figure stands — advisory-by-construction
        // survives the guard.
        $this->amend($entryId, [
            ...$this->figures(10000, '122'),
            'material_kg_confirmed' => true,
            'amendment_reason' => 'Bottles miscounted; the 122 kg is the weighbridge figure',
        ])->assertOk();

        $this->assertSame(0, bccomp('122', $this->storedResinKg($entryId), 4));
        $this->assertSame(0, bccomp('10000', (string) $this->getJson(
            '/api/v1/production/shift-production-entries?status=pending'
        )->json('data.0.quantity_produced'), 4));
    }

    public function test_the_confirmation_is_read_the_way_the_boolean_rule_accepts_it(): void
    {
        // The 'boolean' rule validates "1"/"true" without casting them, so a
        // strict === true would re-refuse an amendment the supervisor had
        // already confirmed — and tell them to confirm it.
        $this->actAsSupervisor();
        $entryId = $this->startBatch();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $this->figures(12000, '122'))
            ->assertOk();

        $this->amend($entryId, [
            ...$this->figures(10000, '122'),
            'material_kg_confirmed' => '1',
        ])->assertOk();
    }

    // (d) lumps count too -----------------------------------------------------------

    public function test_a_lumps_correction_alone_can_trip_it(): void
    {
        $this->actAsSupervisor();
        $entryId = $this->startBatch();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", $this->figures(12000, '122'))
            ->assertOk();

        // Same pieces, but the lumps were weighed again at 9 kg — 7 kg more
        // resin, and the resin box did not move.
        $refused = $this->amend($entryId, [...$this->figures(12000, '122', '9')])->assertStatus(422);

        // 124.5 kg-family total + 7 kg of extra lumps = 131.5.
        $this->assertStringContainsString('131.5000 kg', $refused->json('errors.material_consumptions.0'));
    }
}
