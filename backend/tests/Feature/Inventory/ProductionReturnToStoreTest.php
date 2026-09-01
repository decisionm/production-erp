<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
use App\Modules\Inventory\Models\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StoreIssue;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use App\Modules\Inventory\Services\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE DAILY RETURN FROM THE PRODUCTION AREA — including for material no
 * store issue ever put there.
 *
 * The factory's rule (stated 30-Aug-2026): the store issues material to the
 * production area, production makes finished goods from it, and the balance
 * is returned to the store daily. The only return that existed was bounded
 * by a store issue LINE, and on the live instance seven of the nine
 * materials standing in production have no store issue behind them at all.
 * They had no way home; `returned = 0` on every row of a five-and-a-half
 * week ledger is what that looks like from outside.
 *
 * What these tests pin:
 *
 *  1. Residue — material standing in production with no handover behind it —
 *     goes home, and lands in the store.
 *  2. A DEACTIVATED material goes home too. Six of the seven live orphans
 *     are deactivated, so an is_active filter on this door would refuse the
 *     exact stock it exists to move.
 *  3. An unattributed return may NEVER take what an open store issue is
 *     still standing on, and the refusal names real figures.
 *  4. Attributed and unattributed lines travel in ONE call, in ONE
 *     transaction, and an attributed line still updates the handover's own
 *     arithmetic.
 *  5. A negative production balance returns nothing.
 *  6. Production itself is refused as a destination, in words a storekeeper
 *     can act on — not the transfer's internal same-warehouse message.
 *  7. `inventory:check-ledger` stays green: a new writer of
 *     return_from_production must leave the invariant alone.
 */
class ProductionReturnToStoreTest extends TestCase
{
    use RefreshDatabase;

    private Item $resin;

    private Item $retiredCap;

    /** Counted, active and issuable — the handover half of the fractional rule. */
    private Item $preform;

    private Warehouse $store;

    private Warehouse $wip;

    private User $storeKeeper;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Warehouse::create(['code' => 'RM-STORE', 'name' => 'Raw Material Store', 'is_active' => true]);
        $this->wip = Warehouse::create(['code' => 'WIP', 'name' => 'Production', 'is_active' => false]);

        $this->resin = Item::create([
            'sku' => 'PET-RESIN', 'name' => 'PET Resin', 'uom' => 'Kgs.', 'is_production_input' => true,
        ]);

        // A deactivated counted material — the live shape of the orphans.
        $this->retiredCap = Item::create([
            'sku' => 'CAP-28MM', 'name' => '28mm Tamper-Evident Cap', 'uom' => 'Nos', 'is_active' => false,
        ]);

        $this->preform = Item::create([
            'sku' => 'PREFORM-28G', 'name' => 'PET Preform 28g', 'uom' => 'Nos',
            'is_production_input' => true,
        ]);

        app(ProductionWipLocationResolver::class)->setWarehouseId($this->wip->id);

        $this->storeKeeper = $this->userWith(['inventory.manage', 'production.manage']);
        $this->supervisor = $this->userWith(['production.manage']);

        Sanctum::actingAs($this->storeKeeper);
    }

    // ---- (1) and (2): residue goes home, deactivated or not ----------------

    public function test_material_standing_in_production_with_no_store_issue_behind_it_can_go_home(): void
    {
        // Exactly the live shape: a WIP balance nobody issued. Booked as an
        // opening receipt straight onto the production row, which is how the
        // orphans got there — no handover, no document, no issuer.
        $this->openingStockInProduction($this->resin, '860');

        $this->assertSame('860.0000', $this->balance($this->wip, $this->resin));
        $this->assertSame('0.0000', $this->balance($this->store, $this->resin));

        $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'notes' => 'End of day',
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '860']],
        ])->assertCreated();

        $this->assertSame('0.0000', $this->balance($this->wip, $this->resin));
        $this->assertSame('860.0000', $this->balance($this->store, $this->resin));

        // It is a RETURN, and it says so — the same purpose the store-issue
        // return writes, so one reading of the ledger covers both doors.
        $this->assertTrue(
            StockMovement::query()
                ->where('purpose', StockMovementPurpose::ReturnFromProduction->value)
                ->where('warehouse_id', $this->store->id)
                ->where('type', StockMovementType::TransferIn->value)
                ->exists(),
        );

        // And it carries who did it. §E-6 of the 30-Aug audit: created_by is
        // recorded by the writers even though nothing surfaces it yet.
        $this->assertSame(
            $this->storeKeeper->id,
            StockMovement::query()
                ->where('purpose', StockMovementPurpose::ReturnFromProduction->value)
                ->orderBy('id')
                ->value('created_by'),
        );

        $this->assertLedgerMatchesBalances('after an unattributed return');
    }

    public function test_a_deactivated_material_still_has_a_way_home(): void
    {
        $this->openingStockInProduction($this->retiredCap, '4000');

        $this->assertFalse($this->retiredCap->fresh()->is_active);

        $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [['item_id' => $this->retiredCap->id, 'quantity' => '4000']],
        ])->assertCreated();

        $this->assertSame('0.0000', $this->balance($this->wip, $this->retiredCap));
        $this->assertSame('4000.0000', $this->balance($this->store, $this->retiredCap));

        // Deactivation closed the front door, not the way back: the material
        // is home and it is still deactivated.
        $this->assertFalse($this->retiredCap->fresh()->is_active);
        $this->assertLedgerMatchesBalances('after a deactivated material came home');
    }

    // ---- (3) the bound: a handover's material is not residue ---------------

    /**
     * SETTLED BY THE OWNER, 31-Aug-2026 (DEC-20260831-005, DEC-20260831-012).
     * This refusal used to be a placeholder for Q69 — the build refused
     * because nobody had ruled, and refusing was the direction that could be
     * undone. The ruling went the same way: material that came out on a store
     * issue returns against that issue, and the unattributed door is for
     * material with no store issue behind it. So the refusal stays, and the
     * words it uses are now a rule rather than an apology.
     */
    public function test_a_material_a_handover_is_standing_on_refuses_an_unattributed_return(): void
    {
        // 200 kg of residue with a 100 kg handover standing on the same
        // material. The handover's kilograms belong to its document.
        $this->openingStockInProduction($this->resin, '200');
        $this->stockInStore($this->resin, '500');
        $issue = $this->issueResin('100');

        $this->assertSame('300.0000', $this->balance($this->wip, $this->resin));

        $refusal = $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '200']],
        ])->assertStatus(422);

        $message = (string) $refusal->json('errors')['lines.0.quantity'][0];
        $this->assertStringContainsString('came out of the store on store issue', $message);
        $this->assertStringContainsString($issue['issue_number'], $message, 'the refusal names the issue to open');
        $this->assertStringNotContainsString(
            'open question',
            $message,
            'the rule is settled — the refusal must not still describe itself as undecided',
        );

        // Nothing moved.
        $this->assertSame('300.0000', $this->balance($this->wip, $this->resin));

        // THE NAMED DOOR ALWAYS WORKS, which is why refusing the other one
        // strands nothing that has a handover behind it.
        $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [['store_issue_line_id' => $issue['lines'][0]['id'], 'quantity' => '100']],
        ])->assertCreated();

        $this->assertSame('200.0000', $this->balance($this->wip, $this->resin));

        // And once no handover stands on it, the residue comes home too — the
        // refusal is about the DOCUMENT, not about the material.
        $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '200']],
        ])->assertCreated();

        $this->assertSame('0.0000', $this->balance($this->wip, $this->resin));
        $this->assertLedgerMatchesBalances('after the handover closed and the residue followed');
    }

    /**
     * COMPLETED IS NOT FINISHED — the half of DEC-20260831-012 that is easiest
     * to lose. Completing a store issue moves no stock, so an issue marked
     * complete with quantity still outstanding is STILL holding material on
     * the floor, and that material still belongs to that document.
     *
     * This is the case a tidier merge would have broken. `StoreIssueStatus::
     * isOpen()` is Issued|PartiallyReturned only, so anything keyed on it
     * treats a completed issue as finished — and if the unattributed door had
     * been opened for this material on that basis, the kilograms would have
     * gone home against no document while the handover went on claiming them.
     */
    public function test_a_completed_handover_with_material_still_out_keeps_its_claim(): void
    {
        $this->openingStockInProduction($this->resin, '200');
        $this->stockInStore($this->resin, '500');
        $issue = $this->issueResin('100');

        $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/complete")->assertOk();

        // Completing moved nothing: 200 residue + 100 issued are still there.
        $this->assertSame('300.0000', $this->balance($this->wip, $this->resin));

        $refusal = $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '200']],
        ])->assertStatus(422);

        $this->assertStringContainsString(
            $issue['issue_number'],
            (string) $refusal->json('errors')['lines.0.quantity'][0],
            'a COMPLETED issue with material outstanding still owns that material',
        );
        $this->assertSame('300.0000', $this->balance($this->wip, $this->resin));

        // And its own door is still open, which is what stops this stranding
        // the stock: the attributed return works on a completed issue.
        $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [['store_issue_line_id' => $issue['lines'][0]['id'], 'quantity' => '100']],
        ])->assertCreated();

        $this->assertSame('200.0000', $this->balance($this->wip, $this->resin));
        $this->assertLedgerMatchesBalances('after a completed handover was returned against');
    }

    public function test_two_unattributed_lines_of_one_material_share_one_budget(): void
    {
        // The whole residue is 200. Two lines of 150 must not each be told
        // the whole 200 is theirs.
        $this->openingStockInProduction($this->resin, '200');

        $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [
                ['item_id' => $this->resin->id, 'quantity' => '150'],
                ['item_id' => $this->resin->id, 'quantity' => '150'],
            ],
        ])->assertStatus(422);

        // One transaction: the first line did not stay behind.
        $this->assertSame('200.0000', $this->balance($this->wip, $this->resin));
        $this->assertSame('0.0000', $this->balance($this->store, $this->resin));
    }

    // ---- (4) one call, both kinds of line ---------------------------------

    public function test_attributed_and_unattributed_lines_travel_in_one_call(): void
    {
        // Two DIFFERENT materials, which is the shape an evening actually has:
        // resin that came through a handover, and a retired cap standing in
        // production with nothing behind it.
        $this->stockInStore($this->resin, '500');
        $this->openingStockInProduction($this->retiredCap, '4000');
        $issue = $this->issueResin('100');
        $lineId = $issue['lines'][0]['id'];

        $this->assertSame('100.0000', $this->balance($this->wip, $this->resin));

        $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'notes' => 'End of day',
            'lines' => [
                ['store_issue_line_id' => $lineId, 'quantity' => '40'],
                ['item_id' => $this->retiredCap->id, 'quantity' => '4000'],
            ],
        ])->assertCreated();

        $this->assertSame('60.0000', $this->balance($this->wip, $this->resin));
        $this->assertSame('0.0000', $this->balance($this->wip, $this->retiredCap));
        $this->assertSame('4000.0000', $this->balance($this->store, $this->retiredCap));

        // The ATTRIBUTED half closed the handover's own arithmetic — which is
        // the whole reason it must stay a separate kind of line.
        $line = StoreIssue::query()->sole()->lines()->sole();
        $this->assertSame('40.0000', (string) $line->quantity_returned);
        $this->assertSame('60.0000', $line->quantityOutstanding());

        $this->assertLedgerMatchesBalances('after a mixed return');
    }

    public function test_a_refusal_on_the_second_line_rolls_back_the_first(): void
    {
        $this->stockInStore($this->resin, '500');
        $this->openingStockInProduction($this->retiredCap, '4000');
        $issue = $this->issueResin('100');
        $lineId = $issue['lines'][0]['id'];

        // The unattributed line is fine; the attributed one asks for more
        // than the handover ever handed over.
        $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [
                ['item_id' => $this->retiredCap->id, 'quantity' => '4000'],
                ['store_issue_line_id' => $lineId, 'quantity' => '500'],
            ],
        ])->assertStatus(422);

        // Nothing at all was recorded: a storekeeper who typed one wrong
        // figure has half a return to reason about otherwise.
        $this->assertSame('4000.0000', $this->balance($this->wip, $this->retiredCap));
        $this->assertSame('0.0000', $this->balance($this->store, $this->retiredCap));
        $this->assertSame('100.0000', $this->balance($this->wip, $this->resin));
        $this->assertSame('0.0000', (string) StoreIssue::query()->sole()->lines()->sole()->quantity_returned);
    }

    public function test_two_lines_naming_one_handover_line_are_added_together_not_replaced(): void
    {
        // Keyed by line id and ASSIGNED, the second would silently replace the
        // first: a caller believing it returned 10 + 20 while 20 moved, and a
        // 201 saying it worked.
        $this->stockInStore($this->resin, '500');
        $issue = $this->issueResin('100');
        $lineId = $issue['lines'][0]['id'];

        $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [
                ['store_issue_line_id' => $lineId, 'quantity' => '10'],
                ['store_issue_line_id' => $lineId, 'quantity' => '20'],
            ],
        ])->assertCreated();

        $this->assertSame('30.0000', (string) StoreIssue::query()->sole()->lines()->sole()->quantity_returned);
        $this->assertSame('70.0000', $this->balance($this->wip, $this->resin));
        $this->assertLedgerMatchesBalances('after duplicate line ids were summed');
    }

    public function test_a_handover_that_never_went_to_production_is_refused(): void
    {
        // Reachable only through the API — the screen lists the production
        // floor. returnUnused() moves from the LINE's own to_warehouse, so
        // without this check a line handed over somewhere else would move that
        // warehouse's stock under the name "production return".
        $elsewhere = Warehouse::create(['code' => 'FG-STORE', 'name' => 'Finished Goods', 'is_active' => true]);
        $this->stockInStore($this->resin, '500');

        $issue = StoreIssue::query()->create([
            'issue_number' => 'SI-OTHER',
            'issued_by' => $this->storeKeeper->id,
            'received_by' => $this->supervisor->id,
            'issued_at' => now(),
            'status' => 'issued',
        ]);
        $line = $issue->lines()->create([
            'item_id' => $this->resin->id,
            'from_warehouse_id' => $this->store->id,
            'to_warehouse_id' => $elsewhere->id,
            'quantity_issued' => '50',
            'quantity_returned' => '0',
            'uom' => 'Kgs.',
        ]);

        $refusal = $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [['store_issue_line_id' => $line->id, 'quantity' => '10']],
        ])->assertStatus(422);

        $this->assertStringContainsString(
            'did not hand material over to production',
            (string) $refusal->json('errors')['lines.0.store_issue_line_id'][0],
        );
        $this->assertSame('0.0000', (string) $line->fresh()->quantity_returned);
    }

    // ---- (5) and (6) the two refusals a storekeeper will actually meet ----

    public function test_nothing_comes_back_from_a_negative_production_balance(): void
    {
        // A real state: a batch may consume more than was ever issued to it.
        StockBalance::query()->create([
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->wip->id,
            'quantity' => '-112.3250',
            'average_cost' => '0',
        ]);

        $refusal = $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '10']],
        ])->assertStatus(422);

        $this->assertStringContainsString(
            'a discrepancy to investigate, not stock to send back',
            (string) $refusal->json('errors')['lines.0.quantity'][0],
        );
    }

    public function test_production_itself_is_refused_as_a_destination_in_plain_words(): void
    {
        $this->openingStockInProduction($this->resin, '100');

        $refusal = $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->wip->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '10']],
        ])->assertStatus(422);

        $message = (string) $refusal->json('errors.to_warehouse_id.0');
        $this->assertStringContainsString('coming FROM', $message);

        // NOT the transfer's internal message, which is written for a caller
        // with a bug and not for a storekeeper who picked the wrong row.
        $this->assertStringNotContainsString('must move stock between two different warehouses', $message);
    }

    public function test_a_retired_store_is_refused_as_a_destination(): void
    {
        $this->openingStockInProduction($this->resin, '100');
        $retired = Warehouse::create(['code' => 'FG-STORE-2', 'name' => 'Old Store', 'is_active' => false]);

        $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $retired->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '10']],
        ])->assertStatus(422)->assertJsonPath(
            'errors.to_warehouse_id.0',
            'Material can only be returned to a store that is still in use.',
        );
    }

    // ---- the read side: the split the storekeeper chooses from -------------

    public function test_the_returnable_view_splits_the_floor_into_attributed_and_unattributed(): void
    {
        $this->openingStockInProduction($this->resin, '200');
        $this->stockInStore($this->resin, '500');
        $issue = $this->issueResin('100');

        $rows = $this->getJson('/api/v1/inventory/production-returns/returnable')
            ->assertOk()
            ->json('data');

        $resin = collect($rows)->firstWhere('item_id', $this->resin->id);

        $this->assertSame('300.0000', $resin['on_floor']);
        $this->assertSame('100.0000', $resin['attributed']);
        $this->assertSame('200.0000', $resin['unattributed']);
        $this->assertSame($issue['issue_number'], $resin['store_issue_lines'][0]['issue_number']);
        $this->assertSame('100.0000', $resin['store_issue_lines'][0]['outstanding']);
    }

    public function test_a_negative_row_is_shown_and_offers_nothing(): void
    {
        StockBalance::query()->create([
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->wip->id,
            'quantity' => '-49.0000',
            'average_cost' => '0',
        ]);

        $rows = $this->getJson('/api/v1/inventory/production-returns/returnable')->assertOk()->json('data');
        $resin = collect($rows)->firstWhere('item_id', $this->resin->id);

        // Shown — the floor is standing next to it and must not see it vanish.
        $this->assertSame('-49.0000', $resin['on_floor']);
        // And offering nothing: you cannot send back less than nothing.
        $this->assertSame('0.0000', $resin['unattributed']);
    }

    public function test_the_view_reports_nothing_when_no_production_location_is_configured(): void
    {
        app(ProductionWipLocationResolver::class)->setWarehouseId(null);
        Warehouse::query()->whereKey($this->wip->id)->update(['code' => 'OLD-WIP']);

        $this->getJson('/api/v1/inventory/production-returns/returnable')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '10']],
        ])->assertStatus(422)->assertJsonPath(
            'errors.lines.0',
            'No production location is configured, so nothing can be returned from one. '
            .'Set the Production/WIP warehouse before recording returns.',
        );
    }

    // ---- validation at the door -------------------------------------------

    public function test_a_line_has_to_say_what_is_coming_back(): void
    {
        $refusal = $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [['quantity' => '10']],
        ])->assertStatus(422);

        $this->assertSame(
            'Name either the material coming back or the store issue line it was handed over on.',
            (string) $refusal->json('errors')['lines.0.item_id'][0],
        );
    }

    public function test_a_named_material_that_contradicts_the_named_handover_is_refused(): void
    {
        $this->stockInStore($this->resin, '500');
        $issue = $this->issueResin('100');

        $refusal = $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [[
                'store_issue_line_id' => $issue['lines'][0]['id'],
                'item_id' => $this->retiredCap->id,
                'quantity' => '10',
            ]],
        ])->assertStatus(422);

        $this->assertSame(
            'That store issue line handed over a different material from the one named here.',
            (string) $refusal->json('errors')['lines.0.item_id'][0],
        );
    }

    public function test_half_a_cap_does_not_come_back(): void
    {
        $this->openingStockInProduction($this->retiredCap, '4000');

        $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [['item_id' => $this->retiredCap->id, 'quantity' => '10.5']],
        ])->assertStatus(422);

        $this->assertSame('4000.0000', $this->balance($this->wip, $this->retiredCap));
    }

    public function test_a_fraction_already_standing_may_always_come_home_whole(): void
    {
        // Issued before the counted rule existed, or reclassified since:
        // refusing it would strand it in production for ever.
        $this->openingStockInProduction($this->retiredCap, '2.5000');

        $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [['item_id' => $this->retiredCap->id, 'quantity' => '2.5']],
        ])->assertCreated();

        $this->assertSame('0.0000', $this->balance($this->wip, $this->retiredCap));
        $this->assertSame('2.5000', $this->balance($this->store, $this->retiredCap));
    }

    public function test_half_a_cap_does_not_come_back_against_a_handover_either(): void
    {
        // The service calls returnUnused() directly, so the store-issue
        // return's own FormRequest never runs on this path. Before the rule
        // was applied here, half a counted cap came back with a 201.
        $this->stockInStore($this->preform, '4000');

        $issue = $this->postJson('/api/v1/inventory/store-issues', [
            'received_by' => $this->supervisor->id,
            'lines' => [['item_id' => $this->preform->id, 'quantity' => '100']],
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [['store_issue_line_id' => $issue['lines'][0]['id'], 'quantity' => '10.5']],
        ])->assertStatus(422);

        $this->assertSame('0.0000', (string) StoreIssue::query()->sole()->lines()->sole()->quantity_returned);
        $this->assertSame('100.0000', $this->balance($this->wip, $this->preform));

        // A whole number still comes home through the same door.
        $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [['store_issue_line_id' => $issue['lines'][0]['id'], 'quantity' => '10']],
        ])->assertCreated();

        $this->assertSame('10.0000', (string) StoreIssue::query()->sole()->lines()->sole()->quantity_returned);
    }

    public function test_a_fifth_decimal_place_is_refused_not_quietly_dropped(): void
    {
        // bcadd(..., 4) would truncate 1.23459 to 1.2345 and answer 201: the
        // storekeeper told a figure came home that is not the one that moved.
        $this->openingStockInProduction($this->resin, '860');

        $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '1.23459']],
        ])->assertStatus(422)->assertJsonFragment([
            'Quantities are kept to four decimal places. Round this to four before recording it.',
        ]);

        $this->assertSame('860.0000', $this->balance($this->wip, $this->resin));

        // Four places still work, and trailing zeros are not "precision".
        $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '1.234500']],
        ])->assertCreated();
    }

    public function test_the_door_needs_inventory_manage(): void
    {
        Sanctum::actingAs($this->supervisor);

        $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '10']],
        ])->assertForbidden();
    }

    // ---- helpers -----------------------------------------------------------

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    /** Material standing in production with no handover behind it — the live orphan shape. */
    private function openingStockInProduction(Item $item, string $quantity): void
    {
        app(StockMovementService::class)->recordReceipt(
            itemId: $item->id,
            warehouseId: $this->wip->id,
            quantity: $quantity,
            unitCost: '0',
            reference: 'Pre-existing residue',
            purpose: StockMovementPurpose::Opening,
        );
    }

    private function stockInStore(Item $item, string $quantity): void
    {
        app(StockMovementService::class)->recordReceipt(
            itemId: $item->id,
            warehouseId: $this->store->id,
            quantity: $quantity,
            unitCost: '0',
            reference: 'Opening',
            purpose: StockMovementPurpose::Opening,
        );
    }

    /** @return array<string, mixed> */
    private function issueResin(string $quantity): array
    {
        return $this->postJson('/api/v1/inventory/store-issues', [
            'received_by' => $this->supervisor->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => $quantity]],
        ])->assertCreated()->json('data');
    }

    private function balance(Warehouse $warehouse, Item $item): string
    {
        return (string) (StockBalance::query()
            ->where('item_id', $item->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('quantity') ?? '0.0000');
    }

    /** The invariant `inventory:check-ledger` enforces: movements sign to balances. */
    private function assertLedgerMatchesBalances(string $step): void
    {
        $sums = [];
        foreach (StockMovement::query()->orderBy('id')->get() as $movement) {
            $sign = match ($movement->type) {
                StockMovementType::Receipt, StockMovementType::TransferIn => '1',
                StockMovementType::Issue, StockMovementType::TransferOut => '-1',
            };
            $key = "{$movement->item_id}@{$movement->warehouse_id}";
            $sums[$key] = bcadd($sums[$key] ?? '0.0000', bcmul((string) $movement->quantity, $sign, 4), 4);
        }

        foreach (StockBalance::query()->get() as $balance) {
            $key = "{$balance->item_id}@{$balance->warehouse_id}";
            $this->assertSame(
                bcadd($sums[$key] ?? '0.0000', '0', 4),
                bcadd((string) $balance->quantity, '0', 4),
                "The ledger stopped matching the balances {$step} ({$key}).",
            );
        }
    }
}
