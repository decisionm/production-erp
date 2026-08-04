<?php

namespace Tests\Feature\TallySync;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\TallySync\Models\TallyStockSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Turning Tally's closing stock into the ERP's opening balances.
 *
 * The property that matters most is that it cannot happen twice. An opening
 * balance posted a second time produces no error and no failed row — the stock
 * is simply bigger than it should be, and stays that way until someone counts
 * the floor.
 */
class TallyOpeningStockTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $godown;

    private Item $resin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->godown = Warehouse::create(['code' => 'SWA', 'name' => 'SWAASHPET POLYMERS PVT LTD']);
        $this->resin = Item::create(['sku' => 'Pet Resin', 'name' => 'Pet Resin', 'uom' => 'Kgs.']);

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('tally-sync.view', 'web');
        Permission::findOrCreate('tally-sync.manage', 'web');
        $user->givePermissionTo(['tally-sync.view', 'tally-sync.manage']);
        $this->actingAs($user);
    }

    /** @param array<int, array<string, mixed>>|null $lines */
    private function snapshot(?array $lines = null): TallyStockSnapshot
    {
        return TallyStockSnapshot::create([
            'company' => 'SWAASHPET POLYMERS PVT LTD 26-27',
            'as_of' => '2026-08-02',
            'lines' => $lines ?? [[
                'item_guid' => 'guid-1',
                'tally_item_name' => 'Pet Resin',
                'unit' => 'Kgs.',
                'godown' => 'SWAASHPET POLYMERS PVT LTD',
                'closing_quantity' => '9000.0000',
                'closing_rate' => '85.00',
                'closing_value' => '765000.00',
                'erp_item_id' => $this->resin->id,
                'erp_warehouse_id' => $this->godown->id,
                'importable' => true,
                'problems' => [],
            ]],
            'totals' => ['lines' => 1, 'importable' => 1],
            'status' => TallyStockSnapshot::STATUS_PENDING,
        ]);
    }

    private function balance(): ?string
    {
        return StockBalance::query()
            ->where('item_id', $this->resin->id)
            ->where('warehouse_id', $this->godown->id)
            ->value('quantity');
    }

    public function test_applying_a_snapshot_creates_the_opening_balance_at_tallys_own_rate(): void
    {
        $s = $this->snapshot();

        $this->postJson("/api/v1/tally-sync/stock-snapshots/{$s->id}/apply")
            ->assertOk()
            ->assertJsonPath('data.applied_lines', 1)
            ->assertJsonPath('data.movement_reference', 'Tally opening 2026-08-02');

        $this->assertSame('9000.0000', $this->balance());

        $movement = StockMovement::query()->sole();
        $this->assertSame('85.0000', $movement->unit_cost);
        // Dated the CUTOFF, not today: an opening balance belongs to the
        // position it describes, not to the moment somebody pressed the button.
        $this->assertSame('2026-08-02', $movement->movement_date->toDateString());

        $s->refresh();
        $this->assertSame(TallyStockSnapshot::STATUS_APPLIED, $s->status);
        $this->assertNotNull($s->applied_at);
    }

    public function test_an_opening_balance_lifts_a_negative_ledger_to_the_truth(): void
    {
        // The real situation: production ran before the ledger was ever opened,
        // so the godown reads below zero. The opening does not erase that
        // history — it supplies the arrival the history was always missing.
        StockBalance::create([
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->godown->id,
            'quantity' => '-177.1685',
            'average_cost' => '0',
        ]);

        $this->postJson("/api/v1/tally-sync/stock-snapshots/{$this->snapshot()->id}/apply")->assertOk();

        $this->assertSame('8822.8315', $this->balance());
    }

    public function test_a_second_apply_is_refused_by_the_status(): void
    {
        $s = $this->snapshot();

        $this->postJson("/api/v1/tally-sync/stock-snapshots/{$s->id}/apply")->assertOk();
        $this->postJson("/api/v1/tally-sync/stock-snapshots/{$s->id}/apply")->assertStatus(422);

        // Still one receipt, still the same balance.
        $this->assertSame(1, StockMovement::query()->count());
        $this->assertSame('9000.0000', $this->balance());
    }

    public function test_a_second_apply_is_refused_by_the_ledger_even_if_the_status_says_pending(): void
    {
        // The load-bearing guard. Status is a claim this table makes about
        // itself; the stock ledger is the fact. A row reset by hand, a restored
        // backup, a duplicated snapshot for the same date — all of them land
        // here, and all of them must be refused.
        $first = $this->snapshot();
        $this->postJson("/api/v1/tally-sync/stock-snapshots/{$first->id}/apply")->assertOk();

        $duplicate = $this->snapshot();

        $this->postJson("/api/v1/tally-sync/stock-snapshots/{$duplicate->id}/apply")
            ->assertStatus(422)
            ->assertSee('already carries movements referenced', false);

        $this->assertSame(1, StockMovement::query()->count());
        $this->assertSame('9000.0000', $this->balance());
    }

    public function test_an_unmatched_line_is_skipped_and_reported_never_guessed(): void
    {
        $s = $this->snapshot([[
            'item_guid' => 'guid-unknown',
            'tally_item_name' => 'Something Tally Has That We Do Not',
            'godown' => 'SWAASHPET POLYMERS PVT LTD',
            'closing_quantity' => '50.0000',
            'closing_rate' => '10.00',
            'erp_item_id' => null,
            'erp_warehouse_id' => $this->godown->id,
            'importable' => false,
            'problems' => ['No product in this system carries that exact Tally item.'],
        ]]);

        $this->postJson("/api/v1/tally-sync/stock-snapshots/{$s->id}/apply")
            ->assertOk()
            ->assertJsonPath('data.applied_lines', 0);

        $this->assertSame(0, StockMovement::query()->count());
        $this->assertNull($this->balance());
    }

    public function test_a_negative_closing_balance_in_tally_is_not_copied(): void
    {
        $s = $this->snapshot([[
            'item_guid' => 'guid-1',
            'tally_item_name' => 'Pet Resin',
            'godown' => 'SWAASHPET POLYMERS PVT LTD',
            'closing_quantity' => '-12.0000',
            'closing_rate' => '85.00',
            'erp_item_id' => $this->resin->id,
            'erp_warehouse_id' => $this->godown->id,
            'importable' => true,
            'problems' => [],
        ]]);

        $this->postJson("/api/v1/tally-sync/stock-snapshots/{$s->id}/apply")
            ->assertOk()
            ->assertJsonPath('data.applied_lines', 0);

        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_a_line_tally_prices_at_nothing_still_arrives_but_unpriced(): void
    {
        $s = $this->snapshot([[
            'item_guid' => 'guid-1',
            'tally_item_name' => 'Pet Resin',
            'godown' => 'SWAASHPET POLYMERS PVT LTD',
            'closing_quantity' => '100.0000',
            'closing_rate' => null,
            'erp_item_id' => $this->resin->id,
            'erp_warehouse_id' => $this->godown->id,
            'importable' => true,
            'problems' => [],
        ]]);

        $this->postJson("/api/v1/tally-sync/stock-snapshots/{$s->id}/apply")
            ->assertOk()
            ->assertJsonPath('data.applied_lines', 1);

        // Quantity is real, cost is honestly zero rather than invented.
        $this->assertSame('100.0000', $this->balance());
        $this->assertSame('0.0000', StockMovement::query()->sole()->unit_cost);
    }
}
