<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ShiftProductionEntryService;
use App\Modules\TallySync\Models\TallySyncEntry;
use Database\Seeders\CanonicalMachineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * "IT RAN OUT, SO I USED THE OTHER ONE" — recorded as what it is.
 *
 * THE OWNER'S RULE (01-Sep-2026): when a required packing or consumption
 * material is insufficient, an AUTHORISED user may add an actual-consumption
 * line from a controlled dropdown, and the system must NEVER silently
 * substitute one product or packing item for another. Asked how wide the
 * dropdown should be, the owner answered: any active stock item.
 *
 * "Never silently" is the load-bearing half, and it has three parts, each
 * pinned below:
 *
 *   NOT BY ANYONE   the flag needs material-substitution.manage, on the
 *                   same shape as production.override-fifo — a scoped
 *                   permission plus an explicit per-line flag, so the swap is
 *                   a recorded decision and not an accident.
 *   NOT UNSAID      the reason is required whenever the flag is set, and
 *                   travels with the row and onto the API resource, so a line
 *                   that reached the floor as a substitution never reads back
 *                   as an ordinary consumption.
 *   NOT MERGED      the added line is stored, and posted to Tally, as its OWN
 *                   line under its OWN stock item. Two materials genuinely
 *                   left the floor; one summed row would name one of them as
 *                   the other, which is the silent substitution itself.
 *
 * WHAT IS DELIBERATELY NOT TESTED HERE: that the engine refuses an item with
 * no stock. It does not, and must not — production.stock.allow_negative_on_
 * completion is true because material the ledger does not know about is a
 * fact about the shift, not a reason to refuse it (NegativeStockOnCompletion
 * Test). The dropdown is the control, and it is tested as one.
 */
class SubstitutedMaterialConsumptionTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Item $resin;

    /** The planned packing material — the one that runs short. */
    private Item $tray;

    /** What the floor actually reached for when the trays ran out. */
    private Item $spareTray;

    private Warehouse $fg;

    private Warehouse $dayBin;

    /** The Production/WIP internal location — where the floor's material stands. */
    private Warehouse $wip;

    private Shift $shift;

    private WorkCenter $machine;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.approvals.quality_stage_enabled' => false]);

        $this->seed(CanonicalMachineSeeder::class);
        $this->machine = WorkCenter::where('code', 'MC-01')->firstOrFail();
        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'is_active' => true, 'tally_guid' => 'gd-fg']);
        $this->dayBin = Warehouse::create(['code' => 'DAY-BIN', 'name' => 'Factory Day Bin', 'is_active' => true, 'tally_guid' => 'gd-bin']);
        // DEC-20260830-002: one physical Store; "issued to production but not
        // yet consumed" is carried by the Production/WIP INTERNAL LOCATION —
        // the warehouse row coded WIP. That row is where the dropdown looks,
        // because that is where the floor's own material actually stands.
        $this->wip = Warehouse::create(['code' => 'WIP', 'name' => 'Production / WIP', 'is_active' => true, 'tally_guid' => 'gd-wip']);

        $this->resin = Item::create(['sku' => 'PET-IV08', 'name' => 'Relpet', 'uom' => 'Kgs.', 'is_active' => true, 'tally_stock_item_guid' => 'g1']);
        $this->tray = Item::create(['sku' => 'TRAY-100', 'name' => '100 Ml Tray', 'uom' => 'Nos.', 'is_active' => true, 'tally_stock_item_guid' => 'g2']);
        $this->spareTray = Item::create(['sku' => 'TRAY-200B', 'name' => '200 Ml Brute Tray', 'uom' => 'Nos.', 'is_active' => true, 'tally_stock_item_guid' => 'g4']);

        $this->bottle = Item::create([
            'sku' => 'BTL-100-RND', 'name' => '100ML ROUND', 'uom' => 'Nos.', 'is_active' => true,
            'nominal_weight_grams' => '12.9000', 'standard_cycle_time' => '12.30', 'standard_cavities' => 5,
            'nos_per_tray' => 162, 'trays_per_box' => 5, 'nos_per_box' => 810,
            'colour' => 'Amber', 'tally_stock_item_guid' => 'g3',
        ]);

        $bom = Bom::create(['item_id' => $this->bottle->id, 'name' => 'recipe', 'version' => '1', 'is_active' => true]);
        $bom->lines()->create(['component_item_id' => $this->resin->id, 'quantity_per' => '0.0129']);

        $this->user = $this->actAsSupervisor();
    }

    private function actAsSupervisor(array $extra = []): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $base = ['production.view', 'production.manage', 'inventory.view', 'inventory.manage'];
        foreach ([...$base, ...$extra] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo([...$base, ...$extra]);
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
            'production_date' => '2026-09-01',
        ])->assertOk()->json('data.id');
    }

    /**
     * The run's real story: the planned trays ran out at 40 of the 50 needed,
     * and the last 10 went into brute trays that happened to be on the floor.
     *
     * @param  array<string, mixed>  $substitutionOverrides
     */
    private function completeWithSubstitution(int $entryId, array $substitutionOverrides = []): TestResponse
    {
        return $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => '8100',
            'running_hours' => '8',
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'warehouse_id' => $this->dayBin->id, 'quantity_issued_kg' => '104.49'],
                // The planned tray, at what was ACTUALLY used — short.
                ['item_id' => $this->tray->id, 'warehouse_id' => $this->dayBin->id, 'quantity_issued_kg' => '40'],
                // The off-plan line that covered the rest.
                array_merge([
                    'item_id' => $this->spareTray->id,
                    // From Production/WIP — where the dropdown read it, and
                    // the only place the floor's own material stands.
                    'warehouse_id' => $this->wip->id,
                    'quantity_issued_kg' => '10',
                    'is_substitution' => true,
                    'substitution_reason' => '100 Ml trays ran out at 14:20; finished the run on brute trays',
                ], $substitutionOverrides),
            ],
        ]);
    }

    // ---------------------------------------------------------------
    // Not by anyone.
    // ---------------------------------------------------------------

    public function test_a_supervisor_without_the_permission_cannot_record_a_substituted_material(): void
    {
        $entryId = $this->startBatch();

        $response = $this->completeWithSubstitution($entryId);

        $response->assertStatus(422);
        // The item is NAMED. A supervisor holding the wrong tub needs to know
        // which line was rejected, not merely that one was.
        $this->assertStringContainsString('200 Ml Brute Tray', $response->json('message'));
        $this->assertSame('substitution_not_permitted', $response->json('code'));
    }

    public function test_the_refusal_rolls_the_whole_completion_back(): void
    {
        $entryId = $this->startBatch();

        $this->completeWithSubstitution($entryId)->assertStatus(422);

        $entry = ShiftProductionEntry::findOrFail($entryId);
        // Not a single line landed — including the two ordinary ones that
        // were fine. A half-written completion is worse than a refused one.
        $this->assertSame(0, $entry->materialConsumptions()->count());
        $this->assertNull($entry->quantity_produced);
    }

    public function test_an_authorised_user_records_the_substituted_line(): void
    {
        $this->user = $this->actAsSupervisor(['material-substitution.manage']);
        $entryId = $this->startBatch();

        $this->completeWithSubstitution($entryId)->assertOk();

        $line = ShiftProductionEntry::findOrFail($entryId)
            ->materialConsumptions()
            ->where('item_id', $this->spareTray->id)
            ->firstOrFail();

        $this->assertTrue($line->is_substitution);
        $this->assertSame('100 Ml trays ran out at 14:20; finished the run on brute trays', $line->substitution_reason);
        // WHO is already answered by the column the table has always carried.
        $this->assertSame($this->user->id, $line->created_by);
    }

    // ---------------------------------------------------------------
    // Not unsaid.
    // ---------------------------------------------------------------

    public function test_a_substituted_line_without_a_reason_is_refused(): void
    {
        $this->actAsSupervisor(['material-substitution.manage']);
        $entryId = $this->startBatch();

        $this->completeWithSubstitution($entryId, ['substitution_reason' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('material_consumptions.2.substitution_reason');
    }

    public function test_the_flag_and_the_reason_reach_the_api_resource(): void
    {
        $this->actAsSupervisor(['material-substitution.manage']);
        $entryId = $this->startBatch();
        $this->completeWithSubstitution($entryId)->assertOk();

        $lines = collect(
            $this->getJson("/api/v1/production/shift-production-entries?shift_id={$this->shift->id}")
                ->assertOk()
                ->json('data.0.material_consumptions')
        );

        $substituted = $lines->firstWhere('is_substitution', true);
        $this->assertNotNull($substituted, 'the substituted line must be distinguishable on the resource');
        $this->assertSame('200 Ml Brute Tray', $substituted['item']['name']);
        $this->assertStringContainsString('ran out', $substituted['substitution_reason']);

        // And an ordinary line still reads as one.
        $planned = $lines->firstWhere(fn ($l) => $l['item']['name'] === 'Relpet');
        $this->assertFalse($planned['is_substitution']);
        $this->assertNull($planned['substitution_reason']);
    }

    public function test_an_unflagged_line_is_stored_exactly_as_before(): void
    {
        // The regression that matters most: every existing caller sends no
        // flag at all, and must be untouched by this change.
        $entryId = $this->startBatch();

        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'quantity_produced' => '8100',
            'running_hours' => '8',
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'warehouse_id' => $this->dayBin->id, 'quantity_issued_kg' => '104.49'],
            ],
        ])->assertOk();

        $line = ShiftProductionEntry::findOrFail($entryId)->materialConsumptions()->firstOrFail();
        $this->assertFalse($line->is_substitution);
        $this->assertNull($line->substitution_reason);
    }

    // ---------------------------------------------------------------
    // Not merged — on the entry, and all the way onto the voucher.
    // ---------------------------------------------------------------

    public function test_the_substituted_line_is_stored_beside_the_short_one_never_folded_into_it(): void
    {
        $this->actAsSupervisor(['material-substitution.manage']);
        $entryId = $this->startBatch();
        $this->completeWithSubstitution($entryId)->assertOk();

        $lines = ShiftProductionEntry::findOrFail($entryId)->materialConsumptions()->get();

        $this->assertCount(3, $lines);
        // The planned tray keeps its OWN, short figure — it is not topped up
        // to the planned 50 by the material that covered it.
        $this->assertSame(0, bccomp('40', (string) $lines->firstWhere('item_id', $this->tray->id)->quantity_issued_kg, 4));
        $this->assertSame(0, bccomp('10', (string) $lines->firstWhere('item_id', $this->spareTray->id)->quantity_issued_kg, 4));
    }

    public function test_the_tally_voucher_posts_the_substituted_material_as_its_own_stock_item(): void
    {
        config(['tally-sync.voucher_granularity' => 'batch']);

        $this->user = $this->actAsSupervisor(['material-substitution.manage']);
        $entryId = $this->startBatch();
        $this->completeWithSubstitution($entryId)->assertOk();

        $service = app(ShiftProductionEntryService::class);
        $accountant = User::factory()->create();
        $service->pmApprove(ShiftProductionEntry::findOrFail($entryId), $this->user->id);
        $service->accountantApprove(ShiftProductionEntry::findOrFail($entryId), $accountant->id);

        $voucher = TallySyncEntry::where('tally_voucher_type', 'Manufacturing Journal')->firstOrFail();
        $consumed = collect($voucher->payload['consumed']);

        // FC-04: everything consumed is on the OUT side, and the substituted
        // material is there under ITS OWN Tally stock item name — never under
        // the name of the material it stood in for. That renaming IS the
        // silent substitution the owner's rule forbids, and it would put a
        // quantity of one item against another item's ledger in Tally.
        $this->assertContains('200 Ml Brute Tray', $consumed->pluck('item')->all());
        $this->assertContains('100 Ml Tray', $consumed->pluck('item')->all());

        $substituted = $consumed->firstWhere('item', '200 Ml Brute Tray');
        $this->assertSame(0, bccomp('10', (string) $substituted['quantity'], 4));

        // And the short line is still short on the voucher.
        $this->assertSame(0, bccomp('40', (string) $consumed->firstWhere('item', '100 Ml Tray')['quantity'], 4));
    }

    // ---------------------------------------------------------------
    // The controlled dropdown.
    // ---------------------------------------------------------------

    public function test_the_dropdown_is_refused_to_a_user_who_may_not_substitute(): void
    {
        $this->getJson('/api/v1/production/shift-production-entries/substitute-materials')
            ->assertStatus(403);
    }

    public function test_the_dropdown_offers_only_material_actually_standing_in_production(): void
    {
        $this->actAsSupervisor(['material-substitution.manage']);

        // Only the brute tray is on the floor.
        StockBalance::create([
            'item_id' => $this->spareTray->id,
            'warehouse_id' => $this->wip->id,
            'quantity' => '250.0000',
        ]);
        $names = collect(
            $this->getJson('/api/v1/production/shift-production-entries/substitute-materials')
                ->assertOk()
                ->json('data')
        )->pluck('name');

        $this->assertContains('200 Ml Brute Tray', $names->all());

        // The option names the location its figure was read from, so the
        // completion consumes from where the material actually is.
        $offered = collect($this->getJson('/api/v1/production/shift-production-entries/substitute-materials')
            ->json('data'))->firstWhere('name', '200 Ml Brute Tray');
        $this->assertSame($this->wip->id, $offered['warehouse_id']);
        $this->assertSame(0, bccomp('250', $offered['usable_wip_quantity'], 4));

        // The planned tray has no usable WIP stock, so it is not offered —
        // the dropdown is deliberately stricter than the engine, which would
        // happily issue it negative (DEC-20260831-002's shape).
        $this->assertNotContains('100 Ml Tray', $names->all());
    }

    public function test_a_negative_wip_balance_is_a_discrepancy_and_is_never_offered(): void
    {
        $this->actAsSupervisor(['material-substitution.manage']);

        // DEC-20260831-005: a negative balance is not stock, it is a
        // discrepancy, and it nets as zero rather than as a quantity.
        StockBalance::create([
            'item_id' => $this->spareTray->id,
            'warehouse_id' => $this->wip->id,
            'quantity' => '-12.0000',
        ]);

        $names = collect(
            $this->getJson('/api/v1/production/shift-production-entries/substitute-materials')
                ->assertOk()
                ->json('data')
        )->pluck('name');

        $this->assertNotContains('200 Ml Brute Tray', $names->all());
    }
}
