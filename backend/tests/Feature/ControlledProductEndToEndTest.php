<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ONE controlled product, all the way through: readiness → estimation →
 * start → complete → Plant Manager → voucher preview → Accounts approval →
 * queued voucher. The P0 objective, as an executable scenario.
 *
 * It deliberately STOPS at the queued voucher. Nothing here contacts Tally:
 * the last assertion is that a correct payload is sitting in the queue, which
 * is exactly where a real tracer batch should pause for Vincent's inspection
 * before the agent is allowed to deliver it.
 *
 * The product is mastered the way a real one must be for the chain to work —
 * this fixture doubles as the specification of "fully mapped".
 */
class ControlledProductEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Item $resin;

    private Item $masterbatch;

    private Warehouse $fgStore;

    private Warehouse $rmStore;

    private WorkCenter $machine;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'is_active' => true]);

        // Both godowns exist in Tally — the seeded RM-STORE/FG-STORE pair
        // that does not is what the readiness gate refuses.
        $this->fgStore = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg']);
        $this->rmStore = Warehouse::create(['code' => 'RM', 'name' => 'RM Store', 'is_active' => true, 'tally_guid' => 'gd-rm']);

        $this->resin = Item::create([
            'sku' => 'PET-IV08', 'name' => 'Billion Pet Resin IV-0.8', 'uom' => 'Kgs.',
            'is_active' => true, 'tally_stock_item_guid' => 'itm-resin',
        ]);
        $this->masterbatch = Item::create([
            'sku' => 'MB-AMBER', 'name' => 'Master Batch - Amber', 'uom' => 'Kgs.',
            'is_active' => true, 'tally_stock_item_guid' => 'itm-mb',
        ]);
        $cap = Item::create([
            'sku' => 'CAP-28', 'name' => '28mm Cap', 'uom' => 'Nos.',
            'is_active' => true, 'tally_stock_item_guid' => 'itm-cap',
        ]);

        $this->bottle = Item::create([
            'sku' => 'BTL-500-AMB', 'name' => '500 ml Round Amber', 'uom' => 'Nos.',
            'is_active' => true,
            'nominal_weight_grams' => '31.5000',
            'standard_cycle_time' => '12.00',
            'standard_cavities' => 5,
            'nos_per_tray' => 40,
            'trays_per_box' => 20,
            'nos_per_box' => 800,
            'colour' => 'Amber',
            'tally_stock_item_guid' => 'itm-bottle',
        ]);

        // The versioned consumption recipe: resin + masterbatch + the
        // Nos-unit consumable, so expected consumption covers all three.
        $bom = Bom::create(['item_id' => $this->bottle->id, 'name' => '500ml Amber', 'version' => '1', 'is_active' => true]);
        $bom->lines()->create(['component_item_id' => $this->resin->id, 'quantity_per' => '0.0315']);
        $bom->lines()->create(['component_item_id' => $this->masterbatch->id, 'quantity_per' => '0.0006']);
        $bom->lines()->create(['component_item_id' => $cap->id, 'quantity_per' => '1']);

        // Stock on hand, so the completion's material issues succeed —
        // completeBatch decrements real balances.
        $stock = app(StockMovementService::class);
        $stock->recordReceipt(itemId: $this->resin->id, warehouseId: $this->rmStore->id, quantity: '1000', unitCost: '0', reference: 'opening', createdBy: null);
        $stock->recordReceipt(itemId: $this->masterbatch->id, warehouseId: $this->rmStore->id, quantity: '50', unitCost: '0', reference: 'opening', createdBy: null);
    }

    private function actAs(string ...$roles): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['production.manage', 'production.view', 'tally.view', 'tally.manage', 'quality.manage', 'quality.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        // quality.* because the run now passes a quality check on its way to
        // the plant manager — the same person can hold both here; the four-eyes
        // rule only bars the checker from being the batch's own completer.
        $user->givePermissionTo(['production.manage', 'production.view', 'quality.manage', 'quality.view']);
        foreach ($roles as $role) {
            $user->assignRole(Role::findOrCreate($role, 'web'));
        }
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_one_controlled_product_runs_from_preview_to_a_queued_voucher(): void
    {
        // Batch mode is under test here explicitly; the packaged default is shift.
        config(['tally-sync.voucher_granularity' => 'batch']);

        $this->actAs();

        // ---- 1. Preview: ready, with the whole estimation card ----------
        $preview = $this->getJson('/api/v1/production/shift-production-entries/preview?'.http_build_query([
            'item_id' => $this->bottle->id,
            'work_center_id' => $this->machine->id,
            'warehouse_id' => $this->fgStore->id,
            'shift_id' => $this->shift->id,
        ]))->assertOk();

        $preview->assertJsonPath('data.readiness.ready', true);
        $this->assertSame([], $preview->json('data.readiness.blocking'));

        // 8 h shift, CT 12 s → floor(28800/12) = 2400 cycles × 5 cavities.
        $preview->assertJsonPath('data.estimation.planned_hours', '8.0000');
        $preview->assertJsonPath('data.estimation.expected_cycles', 2400);
        $preview->assertJsonPath('data.estimation.expected_pieces', 12000);
        // 12000 × 31.5 g = 378 kg.
        $preview->assertJsonPath('data.estimation.expected_kg', '378.0000');
        // 12000/800 = 15 boxes; 12000/40 = 300 trays.
        $preview->assertJsonPath('data.estimation.expected_boxes', 15);
        $preview->assertJsonPath('data.estimation.expected_trays', 300);
        // No pouch standard on this product — the field stays null rather
        // than inventing a pouch count.
        $preview->assertJsonPath('data.estimation.expected_pouches', null);

        // Every consumable estimated together, each in its own unit.
        $materials = collect($preview->json('data.estimation.expected_materials'))->keyBy('name');
        $this->assertSame('378.0000', $materials['Billion Pet Resin IV-0.8']['quantity']);
        $this->assertTrue($materials['Billion Pet Resin IV-0.8']['is_mass']);
        $this->assertSame('7.2000', $materials['Master Batch - Amber']['quantity']);
        $this->assertSame('12000.0000', $materials['28mm Cap']['quantity']);
        $this->assertFalse($materials['28mm Cap']['is_mass']);

        // ---- 2. Start Batch ---------------------------------------------
        $started = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fgStore->id,
            'production_date' => '2026-07-29',
        ])->assertOk();

        $entryId = $started->json('data.id');
        $this->assertSame('20260729-M01-001', $started->json('data.batch_number'));
        // Standards snapshotted onto the run.
        $this->assertSame(5, $started->json('data.active_cavities'));

        // ---- 3. Complete Batch with real counted facts -------------------
        // 13 boxes of 800 = 10,400 good pieces against an expected 15.
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => '10400',
            'no_of_box' => 13,
            'nos_per_box' => 800,
            'running_hours' => '8',
            'qc_rejection_kg' => '3.16',
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'warehouse_id' => $this->rmStore->id, 'quantity_issued_kg' => '335'],
                ['item_id' => $this->masterbatch->id, 'warehouse_id' => $this->rmStore->id, 'quantity_issued_kg' => '6.4'],
            ],
            'scraps' => [['type' => 'lumps', 'quantity_kg' => '0.5']],
        ])->assertOk();

        $metrics = $this->getJson('/api/v1/production/shift-production-entries?status=pending')
            ->assertOk()
            ->json('data.0.metrics');

        $this->assertSame(15, $metrics['expected_boxes']);
        $this->assertSame(13, $metrics['actual_boxes']);
        $this->assertSame(86.7, $metrics['efficiency_pct']);
        // Issued counts only the kg-family lines: 335 + 6.4.
        $this->assertSame('341.4000', $metrics['issued_kg']);

        // ---- 3b. Quality passes the whole batch --------------------------
        // Every completed batch goes through the quality queue now. This one
        // is passed clean, which is the case the owner described as
        // "otherwise same": the counts are recorded, the gate opens, and not
        // one figure below moves — the voucher this test follows to Tally
        // carries exactly what it carried before the stage existed.
        $this->actAs();
        $passed = $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/quality-check", [
            'reviewed_nos' => 10400,
            'ok_nos' => 10400,
            'rejected_nos' => 0,
        ])->assertOk();
        $this->assertSame(10400.0, (float) $passed->json('data.quantity_produced'));
        $this->assertNull($passed->json('data.gross_quantity_produced'));

        // ---- 4. Plant Manager approves — no voucher yet ------------------
        $this->actAs('Plant Manager');
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/pm-approve")->assertOk();
        $this->assertSame(0, TallySyncEntry::query()->count(), 'No voucher may exist before Accounts approval.');

        // ---- 5. Voucher preview BEFORE the approval that posts it --------
        $voucher = $this->getJson("/api/v1/production/shift-production-entries/{$entryId}/voucher-preview")
            ->assertOk();

        $voucher->assertJsonPath('data.postable', true);
        $this->assertSame([], $voucher->json('data.problems'));
        $voucher->assertJsonPath('data.voucher.voucher_number', 'SPE-'.$entryId);
        $voucher->assertJsonPath('data.voucher.voucher_type', 'Manufacturing Journal');
        $voucher->assertJsonPath('data.voucher.batch_number', '20260729-M01-001');
        $voucher->assertJsonPath('data.voucher.godown', 'FG Store');

        $lines = collect($voucher->json('data.lines'));
        $this->assertSame([], $lines->pluck('problems')->flatten()->all());

        // Consumption issues from RM Store; production receives into FG Store
        // — the godown split that PR #27 fixed, now visible before posting.
        $resinLine = $lines->firstWhere('item', 'Billion Pet Resin IV-0.8');
        $this->assertSame('consumption', $resinLine['side']);
        $this->assertSame('RM Store', $resinLine['godown']);
        $this->assertSame('Kgs.', $resinLine['uom']);

        $producedLine = $lines->firstWhere('side', 'production');
        $this->assertSame('500 ml Round Amber', $producedLine['item']);
        $this->assertSame('FG Store', $producedLine['godown']);
        $this->assertSame('Nos.', $producedLine['uom']);

        // ---- 6. Accounts approves → exactly one queued voucher -----------
        $this->actAs('Accounts');
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/accountant-approve")->assertOk();

        $this->assertSame(1, TallySyncEntry::query()->count());
        $queued = TallySyncEntry::query()->sole();
        $this->assertSame('pending', $queued->status->value);
        $this->assertSame('SPE-'.$entryId, $queued->payload['voucher_number']);

        // The queued payload IS what the preview showed — same builder.
        $this->assertSame(
            $voucher->json('data.voucher.consumed'),
            $queued->payload['consumed'],
        );

        $this->assertSame(
            ShiftProductionEntryStatus::Approved->value,
            $this->getJson('/api/v1/production/shift-production-entries?status=approved')->json('data.0.status'),
        );
    }

    public function test_the_voucher_preview_names_every_master_tally_would_reject(): void
    {
        // The same entry, but issued from a seeded godown and consuming a
        // seeded material — the two live blockers from the exception report.
        // The preview must name both BEFORE anyone approves.
        $this->actAs();

        $seededGodown = Warehouse::create(['code' => 'RM-STORE', 'name' => 'Raw Material Store', 'is_active' => true]);
        $seededResin = Item::create(['sku' => 'SEED-RESIN', 'name' => 'PET Resin (Virgin Grade)', 'uom' => 'kg', 'is_active' => true]);
        app(StockMovementService::class)->recordReceipt(
            itemId: $seededResin->id, warehouseId: $seededGodown->id,
            quantity: '1000', unitCost: '0', reference: 'opening', createdBy: null,
        );

        $entryId = $this->postJson('/api/v1/production/shift-production-entries', [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fgStore->id,
            'production_date' => '2026-07-29',
        ])->assertOk()->json('data.id');

        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => '10400',
            'running_hours' => '8',
            'material_consumptions' => [
                ['item_id' => $seededResin->id, 'warehouse_id' => $seededGodown->id, 'quantity_issued_kg' => '335'],
            ],
        ])->assertOk();

        $preview = $this->getJson("/api/v1/production/shift-production-entries/{$entryId}/voucher-preview")
            ->assertOk();

        $preview->assertJsonPath('data.postable', false);

        $problems = collect($preview->json('data.lines'))->pluck('problems')->flatten()->all();
        $this->assertContains(
            '"PET Resin (Virgin Grade)": no Tally identity is recorded here, so this line will be refused unless a stock '
            .'item of exactly this name exists there — this ERP cannot check.',
            $problems,
        );
        $this->assertContains(
            'Godown "Raw Material Store": no Tally identity is recorded here and it aliases to no Tally-known godown, so '
            .'this line will be refused unless a godown of exactly this name exists there — this ERP cannot check.',
            $problems,
        );
    }
}
