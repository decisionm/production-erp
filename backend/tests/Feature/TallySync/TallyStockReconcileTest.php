<?php

namespace Tests\Feature\TallySync;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\TallySync\Models\TallyStockSnapshot;
use App\Modules\TallySync\Services\TallyOpeningStockService;
use App\Modules\TallySync\Services\TallyStockReconcileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Matching the ERP's stock to Tally's — "sync the live stock from the tally and
 * start consume from here" (owner, 06-Aug).
 *
 * The distinction these tests exist to hold is between MATCHING and ADDING.
 * TallyOpeningStockService receipts Tally's closing balance, so running it twice
 * doubles the stock — silently, with no error, until somebody counts the floor.
 * This service reads what the ERP holds and moves only the difference, which is
 * the only version of "sync the stock" that can be done more than once.
 */
class TallyStockReconcileTest extends TestCase
{
    use RefreshDatabase;

    private Item $resin;

    private Warehouse $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resin = Item::create([
            'sku' => 'RELPET', 'name' => 'Relpet G5801M', 'uom' => 'Kgs.', 'is_active' => true,
        ]);
        $this->store = Warehouse::create([
            'code' => 'RM-STORE', 'name' => 'SWAASHPET POLYMERS PVT LTD', 'is_active' => true,
        ]);
    }

    private function snapshot(string $closing, ?string $rate = '95.0000', string $asOf = '2026-08-06'): TallyStockSnapshot
    {
        return TallyStockSnapshot::create([
            'company' => 'SWAASHPET POLYMERS PVT LTD',
            'as_of' => $asOf,
            'lines' => [[
                'tally_item_name' => 'Relpet G5801M',
                'godown' => 'SWAASHPET POLYMERS PVT LTD',
                'closing_quantity' => $closing,
                'closing_rate' => $rate,
                'erp_item_id' => $this->resin->id,
                'erp_item_name' => $this->resin->name,
                'erp_warehouse_id' => $this->store->id,
                'erp_warehouse_name' => $this->store->name,
                'importable' => true,
                'problems' => [],
            ]],
            'totals' => ['lines' => 1, 'importable' => 1],
            'status' => TallyStockSnapshot::STATUS_PENDING,
        ]);
    }

    private function balance(): string
    {
        return (string) StockBalance::query()
            ->where('item_id', $this->resin->id)
            ->where('warehouse_id', $this->store->id)
            ->value('quantity');
    }

    /** @return array<string, mixed> */
    private function reconcile(TallyStockSnapshot $snapshot, bool $write = true): array
    {
        return app(TallyStockReconcileService::class)->apply($snapshot, null, $write);
    }

    public function test_a_negative_balance_is_brought_up_to_what_tally_holds(): void
    {
        // THE LIVE CASE. The approval desk reported 357.9208 kg of resin issued
        // beyond the recorded balance, because the ERP's ledger never knew the
        // resin arrived. Tally knows: it has the purchase.
        app(StockMovementService::class)->recordIssue(
            itemId: $this->resin->id,
            warehouseId: $this->store->id,
            quantity: '357.9208',
            allowNegative: true,
        );

        $this->assertSame(0, bccomp($this->balance(), '-357.9208', 4));

        $result = $this->reconcile($this->snapshot('400.0000'));

        // Not 400 added to −357.92. Matched TO 400.
        $this->assertSame(0, bccomp($this->balance(), '400.0000', 4));
        $this->assertSame(1, $result['received']);
        $this->assertSame(0, $result['issued']);
    }

    public function test_it_moves_only_the_difference(): void
    {
        app(StockMovementService::class)->recordReceipt(
            itemId: $this->resin->id, warehouseId: $this->store->id,
            quantity: '300.0000', unitCost: '90.0000',
        );

        $this->reconcile($this->snapshot('400.0000'));

        // One movement of 100, not one of 400.
        $movement = StockMovement::query()->where('reference', 'like', 'TALLY-RECONCILE-%')->sole();
        $this->assertSame(0, bccomp((string) $movement->quantity, '100.0000', 4));
        $this->assertSame(0, bccomp($this->balance(), '400.0000', 4));
    }

    public function test_it_issues_when_tally_holds_less(): void
    {
        app(StockMovementService::class)->recordReceipt(
            itemId: $this->resin->id, warehouseId: $this->store->id,
            quantity: '500.0000', unitCost: '90.0000',
        );

        $result = $this->reconcile($this->snapshot('400.0000'));

        $this->assertSame(0, bccomp($this->balance(), '400.0000', 4));
        $this->assertSame(1, $result['issued']);

        // The quantity is a magnitude — the movement TYPE carries the direction,
        // and a negative quantity on an issue would be a double negative nobody
        // reading the ledger should have to unpick.
        $movement = StockMovement::query()->where('reference', 'like', 'TALLY-RECONCILE-%')->sole();
        $this->assertSame(0, bccomp((string) $movement->quantity, '100.0000', 4));
        $this->assertStringNotContainsString('-', (string) $movement->quantity);
    }

    public function test_running_it_twice_on_one_snapshot_is_refused(): void
    {
        $snapshot = $this->snapshot('400.0000');
        $this->reconcile($snapshot);

        // Not because the arithmetic would double anything — a second pass would
        // find the difference already zero. Because a snapshot is a photograph of
        // a moment, and re-matching to yesterday's photograph would undo a day of
        // production that happened after it.
        $this->expectException(ValidationException::class);
        $this->reconcile($snapshot->fresh());
    }

    public function test_a_second_snapshot_matches_again_rather_than_doubling(): void
    {
        // The difference from the opening-stock service, stated as a test. This
        // is what makes "sync the live stock" something the factory can do every
        // month instead of exactly once.
        $this->reconcile($this->snapshot('400.0000'));

        // A shift consumes 150 the ordinary way.
        app(StockMovementService::class)->recordIssue(
            itemId: $this->resin->id, warehouseId: $this->store->id, quantity: '150.0000',
        );
        $this->assertSame(0, bccomp($this->balance(), '250.0000', 4));

        // Tally, meanwhile, records a fresh purchase and now holds 600.
        $this->reconcile($this->snapshot('600.0000'));

        $this->assertSame(0, bccomp($this->balance(), '600.0000', 4));
    }

    public function test_the_opening_stock_service_would_have_doubled_a_later_snapshot(): void
    {
        // Kept as the REASON THIS CLASS EXISTS, not as a criticism of that one.
        //
        // TallyOpeningStockService already refuses a repeat of the same date —
        // its reference is "Tally opening <as_of>", so re-applying August 6th
        // twice is caught. What it cannot catch is a LATER snapshot, and a later
        // snapshot is exactly what "sync the live stock" means when it is done
        // monthly: September's closing balance receipted on top of August's is
        // twice the stock, with no error anywhere.
        app(TallyOpeningStockService::class)->apply($this->snapshot('400.0000'), null);
        $this->assertSame(0, bccomp($this->balance(), '400.0000', 4));

        app(TallyOpeningStockService::class)->apply($this->snapshot('400.0000', asOf: '2026-09-06'), null);
        $this->assertSame(0, bccomp($this->balance(), '800.0000', 4), 'Adding a later snapshot is the hazard being avoided.');

        // Matching the same September figure instead brings it back to the truth.
        $this->reconcile($this->snapshot('400.0000', asOf: '2026-09-06'));
        $this->assertSame(0, bccomp($this->balance(), '400.0000', 4));
    }

    public function test_a_dry_run_moves_nothing_but_reports_everything(): void
    {
        $result = $this->reconcile($this->snapshot('400.0000'), write: false);

        $this->assertSame(0, StockMovement::query()->count());
        $this->assertNull(StockBalance::query()->first());
        $this->assertSame(1, $result['received']);
        $this->assertSame('Relpet G5801M', $result['changes'][0]['item']);
        $this->assertSame(0, bccomp($result['changes'][0]['difference'], '400.0000', 4));

        // And the snapshot is left PENDING, so the real run is not refused by
        // its own rehearsal.
        $this->assertSame(
            TallyStockSnapshot::STATUS_PENDING,
            TallyStockSnapshot::query()->latest('id')->value('status'),
        );
    }

    public function test_an_equal_balance_writes_no_movement(): void
    {
        app(StockMovementService::class)->recordReceipt(
            itemId: $this->resin->id, warehouseId: $this->store->id,
            quantity: '400.0000', unitCost: '90.0000',
        );

        $result = $this->reconcile($this->snapshot('400.0000'));

        // A zero-quantity movement is noise in a ledger a person reads.
        $this->assertSame(0, StockMovement::query()->where('reference', 'like', 'TALLY-RECONCILE-%')->count());
        $this->assertSame(1, $result['already_equal']);
    }

    public function test_a_negative_in_tally_is_reported_not_copied(): void
    {
        // Tally showing a negative is somebody's unanswered question. Copying it
        // over makes it our balance too.
        $result = $this->reconcile($this->snapshot('-12.0000'));

        $this->assertSame(0, StockMovement::query()->count());
        $this->assertCount(1, $result['skipped']);
        $this->assertStringContainsString('negative balance', $result['skipped'][0]);
    }

    public function test_an_unmatched_line_is_skipped_rather_than_guessed_at(): void
    {
        $snapshot = TallyStockSnapshot::create([
            'company' => 'SWAASHPET POLYMERS PVT LTD',
            'as_of' => '2026-08-06',
            'lines' => [[
                'tally_item_name' => 'Some Item We Do Not Hold',
                'godown' => 'Main',
                'closing_quantity' => '10.0000',
                'importable' => false,
                'problems' => ['No item in this ERP matches that Tally name.'],
            ]],
            'totals' => ['lines' => 1, 'importable' => 0],
            'status' => TallyStockSnapshot::STATUS_PENDING,
        ]);

        $result = $this->reconcile($snapshot);

        $this->assertSame(0, StockMovement::query()->count());
        $this->assertStringContainsString('No item in this ERP matches', $result['skipped'][0]);
    }

    public function test_the_command_defaults_to_a_dry_run(): void
    {
        $this->snapshot('400.0000');

        $this->artisan('tally:reconcile-stock')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_the_command_says_so_when_there_is_no_snapshot(): void
    {
        $this->artisan('tally:reconcile-stock')
            ->expectsOutputToContain('No Tally stock snapshot found')
            ->assertFailed();
    }
}
