<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\ReturnedQualityState;
use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
use App\Modules\Inventory\Models\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StoreIssue;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use App\Modules\Inventory\Services\QualityHoldLocationResolver;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Services\FactoryWarehouseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE WHOLE LAP, IN ONE TEST CLASS — production asks, the store issues,
 * production consumes from the floor and not from the store, and what is
 * left comes home saying what condition it is in.
 *
 * Every leg of this already has its own test, and this class deliberately
 * does not repeat any of them. What no existing test covers is the JOIN: the
 * legs are written in four services (MaterialRequestService, StoreIssueService,
 * FactoryWarehouseResolver, ProductionReturnService) and each one is green
 * against its own fixtures. A lap proves the figures survive the handoffs —
 * that the quantity the store issued is the quantity production draws on, and
 * that the balance left standing is the balance the next request nets against.
 *
 * WHAT IT PINS, in the order the day runs:
 *
 *  1. THE REQUEST NETS AGAINST THE FLOOR (DEC-20260831-005). With material
 *     already standing in Production/WIP, a request for the same item and the
 *     same unit asks the store only for the balance — total required, already
 *     available, and balance to request, floored at zero.
 *  2. THE STORE'S ISSUE MOVES STORE -> PRODUCTION and nothing else. The
 *     kilograms leave the store's balance and arrive on the floor's; they are
 *     not consumed by moving (the ISSUE_IS_NOT_CONSUMPTION rule the workspace
 *     states in words is arithmetic here).
 *  3. CONSUMPTION IS DRAWN FROM PRODUCTION/WIP, NOT FROM THE STORE
 *     (DEC-20260831-009). This is the one the brief names, and it is asserted
 *     against the resolver the completion path actually calls rather than a
 *     re-implementation of it.
 *  4. THE RETURN CARRIES ALL FOUR FACTS — quantity, unit, quality state and
 *     the exact store issue it came out on — and a PARTIAL return is as valid
 *     as a full one, which is the half of DEC-20260831-005 most easily lost.
 *  5. WHAT IS NOT RETURNED STAYS ON THE FLOOR. It is not consumed, not
 *     written off, and not moved by the day ending — and the NEXT request
 *     nets against it, which closes the lap back onto (1). Reaffirmed by the
 *     owner as DEC-20260901-001: a partial return and a full one are BOTH
 *     allowed, a full return is NOT mandatory, and the ERP must neither
 *     require Production/WIP to be empty at the end of a shift nor flag a
 *     floor for being non-empty. The partial return below is what that
 *     permission looks like in arithmetic — 50 of the 80 stay out on the
 *     floor and nothing refuses them.
 *
 * NOTHING HERE CREATES A BATCH OR POSTS A VOUCHER. The consumption leg asks
 * the resolver WHERE a batch would draw from; it does not run one. That is
 * the hard safety line, and a test is not an exemption from it.
 */
class StoreToProductionAndBackTest extends TestCase
{
    use RefreshDatabase;

    private Item $resin;

    private Warehouse $store;

    private Warehouse $wip;

    private Warehouse $qualityHold;

    private User $storeKeeper;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        // ONE PHYSICAL GODOWN, TWO INTERNAL LOCATIONS (DEC-20260830-002).
        // The WIP row is a stock STATE, not a second building, and it is
        // deactivated exactly as the live one is — the resolver must still
        // find it, which is the whole reason it does not filter is_active.
        $this->store = Warehouse::create(['code' => 'RM-STORE', 'name' => 'Store', 'is_active' => true]);
        $this->wip = Warehouse::create(['code' => 'WIP', 'name' => 'Production', 'is_active' => false]);

        $this->resin = Item::create([
            'sku' => 'PET-RESIN', 'name' => 'PET Resin', 'uom' => 'Kgs.', 'is_production_input' => true,
        ]);

        // A THIRD INTERNAL LOCATION of the same godown (DEC-20260901-003):
        // where a damaged return waits for Quality. Configured here because
        // the flow REFUSES without it — which is its own test, below.
        $this->qualityHold = Warehouse::create(['code' => 'QC-HOLD', 'name' => 'Quality Hold', 'is_active' => true]);

        app(ProductionWipLocationResolver::class)->setWarehouseId($this->wip->id);
        app(QualityHoldLocationResolver::class)->setWarehouseId($this->qualityHold->id);
        app(FactoryWarehouseResolver::class)->setRawMaterialWarehouseId($this->store->id);

        $this->storeKeeper = $this->userWith(['inventory.manage', 'production.manage']);
        $this->supervisor = $this->userWith(['production.manage']);

        Sanctum::actingAs($this->storeKeeper);
    }

    /**
     * (1) → (5): one full lap, asserted at every handoff.
     *
     * Written as ONE test rather than five because the thing under test is
     * the continuity — split into five, each would need to fake the state the
     * previous leg produced, and faking it is exactly what would hide a
     * handoff that drops a figure.
     */
    public function test_the_store_issues_what_the_floor_is_short_of_and_takes_back_what_was_not_used(): void
    {
        // 500 kg in the store; 120 kg already standing on the floor from
        // yesterday, with no handover behind it — the live orphan shape, and
        // the material DEC-20260831-005 says is the next day's opening.
        $this->stockIn($this->store, '500');
        $this->stockIn($this->wip, '120');

        // ---- (1) the request nets against the floor -----------------------

        $request = $this->postJson('/api/v1/inventory/material-requests', [
            // BOTH fields, as the API has always taken them: `quantity` is
            // what the store is asked for and predates the netting, and
            // `required_quantity` is the netting input the server subtracts
            // the floor from. Sending the same figure for each is what a
            // screen with nothing yet netted posts; the server replaces
            // `quantity` with the balance it works out.
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '200', 'required_quantity' => '200']],
        ])->assertCreated()->json('data');

        $line = $request['lines'][0];

        // The three figures the screen must show, from the server. 200 wanted,
        // 120 already on the floor, 80 to ask the store for — `quantity` IS
        // the balance to request, which is why it is not the same number as
        // `required_quantity`.
        $this->assertSame('200.0000', (string) $line['required_quantity']);
        $this->assertSame('120.0000', (string) $line['available_in_production']);
        $this->assertSame('80.0000', (string) $line['quantity']);

        // ---- (2) the issue moves store -> production ----------------------

        $issue = $this->postJson('/api/v1/inventory/store-issues', [
            'received_by' => $this->supervisor->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '80']],
        ])->assertCreated()->json('data');

        $this->assertSame('420.0000', $this->balance($this->store));
        $this->assertSame('200.0000', $this->balance($this->wip));

        // ISSUING IS NOT CONSUMING. Nothing in this factory has consumed a
        // kilogram yet: the ledger holds a transfer pair and no consumption.
        $this->assertSame(
            0,
            StockMovement::query()->where('purpose', StockMovementPurpose::Consumption->value)->count(),
        );

        // ---- (3) production draws from the floor, not the store -----------

        // The question the completion path asks, asked through the same
        // resolver rather than a copy of its rule.
        $source = app(FactoryWarehouseResolver::class)->consumptionSource($this->resin->id);

        $this->assertNotNull($source);
        $this->assertSame(
            $this->wip->id,
            $source->id,
            'A batch must consume from Production/WIP while material is standing there — '
            .'drawing from the Store would take stock the store had already handed over.',
        );
        $this->assertTrue(app(FactoryWarehouseResolver::class)->isProductionWip($source->id));

        // ---- (4) the return: partial, attributed, and stating its condition -

        $issueLineId = $issue['lines'][0]['id'];

        // 30 of the 80 come back clean against the handover they went out on,
        // and 20 of yesterday's residue comes back damaged with no handover to
        // name — both in ONE call, which is the shape the returns screen posts.
        $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'notes' => 'End of shift',
            'lines' => [
                [
                    'store_issue_line_id' => $issueLineId,
                    'quantity' => '30',
                    'quality_state' => ReturnedQualityState::Good->value,
                ],
            ],
        ])->assertCreated();

        // A PARTIAL RETURN CLOSES ONLY ITS OWN QUANTITY. The handover is
        // still standing on the other 50, which is what keeps the material on
        // the floor attributable rather than becoming anonymous residue.
        $storeIssue = StoreIssue::query()->whereKey($issue['id'])->firstOrFail();
        $issueLine = $storeIssue->lines()->whereKey($issueLineId)->firstOrFail();

        $this->assertSame('30.0000', bcadd((string) $issueLine->quantity_returned, '0', 4));
        $this->assertSame('50.0000', bcadd($issueLine->quantityOutstanding(), '0', 4));

        // ALL FOUR FACTS ARE ON THE LEDGER ROW: how much, of what (whose unit
        // the item master carries), in what condition, and — through the
        // issue's own number in the reference — which handover it came back on.
        $returned = StockMovement::query()
            ->where('purpose', StockMovementPurpose::ReturnFromProduction->value)
            ->where('type', StockMovementType::TransferIn->value)
            ->orderBy('id')
            ->firstOrFail();

        $this->assertSame('30.0000', bcadd((string) $returned->quantity, '0', 4));
        $this->assertSame($this->store->id, $returned->warehouse_id);
        $this->assertSame(ReturnedQualityState::Good, $returned->quality_state);
        $this->assertStringContainsString((string) $storeIssue->issue_number, (string) $returned->reference);
        $this->assertSame('Kgs.', $this->resin->fresh()->uom);

        // ---- (5) what was not returned is still standing on the floor -----

        $this->assertSame('450.0000', $this->balance($this->store));
        $this->assertSame('170.0000', $this->balance($this->wip));

        // AND THE NEXT REQUEST NETS AGAINST IT — the lap closes. 200 wanted
        // again, 170 already there, 30 to ask for.
        $next = $this->postJson('/api/v1/inventory/material-requests', [
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '200', 'required_quantity' => '200']],
        ])->assertCreated()->json('data.lines.0');

        $this->assertSame('170.0000', (string) $next['available_in_production']);
        $this->assertSame('30.0000', (string) $next['quantity']);

        $this->assertLedgerMatchesBalances('after a full store -> production -> store lap');
    }

    /**
     * ONE LINE, ONE CONDITION. Two asks against the same handover line are
     * ADDED UP — right for a quantity, wrong for a condition: 30 kg good and
     * 20 kg damaged is not 50 kg of anything, and either answer puts a state
     * on the ledger nobody wrote. Refused, because the storekeeper is the one
     * who knows. The screen cannot produce this; the API door can.
     */
    public function test_one_handover_line_returned_twice_in_two_conditions_is_refused(): void
    {
        $this->stockIn($this->store, '100');

        $issue = $this->postJson('/api/v1/inventory/store-issues', [
            'received_by' => $this->supervisor->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '100']],
        ])->assertCreated()->json('data');

        $lineId = $issue['lines'][0]['id'];

        $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [
                ['store_issue_line_id' => $lineId, 'quantity' => '30', 'quality_state' => 'good'],
                ['store_issue_line_id' => $lineId, 'quantity' => '20', 'quality_state' => 'damaged'],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines.1.quality_state');

        // And it refused before anything moved: all 100 are still on the floor.
        $this->assertSame('100.0000', $this->balance($this->wip));

        // The same line returned twice in the SAME condition still adds up, as
        // it always has — the refusal is about the condition, not the merge.
        $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [
                ['store_issue_line_id' => $lineId, 'quantity' => '30', 'quality_state' => 'damaged'],
                ['store_issue_line_id' => $lineId, 'quantity' => '20', 'quality_state' => 'damaged'],
            ],
        ])->assertCreated();

        $this->assertSame('50.0000', $this->balance($this->wip));
        // The merged 50 went to quality hold, not to the store — one
        // destination for one merged line, decided by the one condition.
        $this->assertSame('50.0000', $this->balance($this->qualityHold));
        $this->assertSame('0.0000', $this->balance($this->store));
    }

    /**
     * A DAMAGED RETURN GOES TO QUALITY, NEVER TO USABLE STOCK
     * (DEC-20260901-003).
     *
     * This test REPLACES one that asserted the opposite — that a damaged
     * return moved exactly what a good one moved — which was the correct
     * boundary while the disposition was an open question and is wrong now
     * that the owner has answered it. The old assertion is not weakened here,
     * it is reversed, and the store's balance is asserted at ZERO to say so.
     */
    public function test_a_damaged_return_lands_in_quality_hold_and_never_in_the_store(): void
    {
        $this->stockIn($this->wip, '100');

        $this->postJson('/api/v1/inventory/production-returns', [
            // The storekeeper picks the store, exactly as for a good line.
            'to_warehouse_id' => $this->store->id,
            'lines' => [[
                'item_id' => $this->resin->id,
                'quantity' => '40',
                'quality_state' => ReturnedQualityState::Damaged->value,
            ]],
        ])->assertCreated();

        // THE PICKED STORE IS NOT WHERE IT WENT. A person's choice must not
        // be able to put damaged material into issuable stock, so the
        // destination is decided by the condition and the dropdown is
        // ignored for this line.
        $this->assertSame('0.0000', $this->balance($this->store));
        $this->assertSame('40.0000', $this->balance($this->qualityHold));
        $this->assertSame('60.0000', $this->balance($this->wip));

        $movement = StockMovement::query()
            ->where('purpose', StockMovementPurpose::ReturnFromProduction->value)
            ->where('type', StockMovementType::TransferIn->value)
            ->firstOrFail();

        $this->assertSame(ReturnedQualityState::Damaged, $movement->quality_state);
        $this->assertSame($this->qualityHold->id, $movement->warehouse_id);

        $this->assertLedgerMatchesBalances('after a damaged return');
    }

    /**
     * AND SO DOES A DAMAGED RETURN AGAINST A STORE ISSUE — the second door,
     * which reaches the ledger by a completely different route.
     *
     * An attributed return does not use the destination on the request at
     * all: StoreIssueService::returnUnused sends material back to the store
     * the handover ISSUED from, a fact about the original handover. That is
     * the correct default and it is exactly the wrong destination for a
     * damaged line, so the override is a SECOND edit in a second place — and
     * a test that only checked `quality_state` would pass while the material
     * sat in the store.
     */
    public function test_a_damaged_return_against_a_store_issue_also_lands_in_quality_hold(): void
    {
        $this->stockIn($this->store, '100');

        $issue = $this->postJson('/api/v1/inventory/store-issues', [
            'received_by' => $this->supervisor->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '100']],
        ])->assertCreated()->json('data');

        $lineId = $issue['lines'][0]['id'];

        $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [[
                'store_issue_line_id' => $lineId,
                'quantity' => '40',
                'quality_state' => ReturnedQualityState::Damaged->value,
            ]],
        ])->assertCreated();

        $this->assertSame('0.0000', $this->balance($this->store));
        $this->assertSame('40.0000', $this->balance($this->qualityHold));

        $movement = StockMovement::query()
            ->where('purpose', StockMovementPurpose::ReturnFromProduction->value)
            ->where('type', StockMovementType::TransferIn->value)
            ->firstOrFail();

        $this->assertSame($this->qualityHold->id, $movement->warehouse_id);
        $this->assertSame(ReturnedQualityState::Damaged, $movement->quality_state);

        // THE HANDOVER STILL CLOSES ITS OWN QUANTITY. The material did leave
        // production, so the floor no longer holds it; whether it is USABLE
        // is a separate fact and is carried by the location it sits in.
        // Withholding this would leave the issue open against material
        // nobody can return again.
        $storeIssue = StoreIssue::query()->whereKey($issue['id'])->firstOrFail();
        $issueLine = $storeIssue->lines()->whereKey($lineId)->firstOrFail();

        $this->assertSame('40.0000', bcadd((string) $issueLine->quantity_returned, '0', 4));
        $this->assertSame('60.0000', bcadd($issueLine->quantityOutstanding(), '0', 4));

        $this->assertLedgerMatchesBalances('after a damaged attributed return');
    }

    /**
     * NO QUALITY HOLD CONFIGURED MEANS THE DAMAGED LINE IS REFUSED — it does
     * NOT fall back to the store.
     *
     * Every other location resolver in this system has a fallback. This one
     * must not, because a fallback here produces the single outcome the rule
     * forbids, silently, on the happy path, in exactly the situation where
     * nobody has configured anything. Refusing a return is recoverable in a
     * minute; material quietly back on the issuable shelf is not.
     *
     * This is also the LIVE state today: no warehouse carries the code
     * QC-HOLD, so damaged returns refuse until a person names the row.
     */
    public function test_a_damaged_return_is_refused_when_no_quality_hold_is_configured(): void
    {
        app(QualityHoldLocationResolver::class)->setWarehouseId(null);
        $this->qualityHold->update(['code' => 'NOT-THE-HOLD']);

        $this->stockIn($this->wip, '100');

        $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [[
                'item_id' => $this->resin->id,
                'quantity' => '40',
                'quality_state' => ReturnedQualityState::Damaged->value,
            ]],
        ])->assertUnprocessable();

        // NOTHING MOVED — not to the store, not anywhere.
        $this->assertSame('100.0000', $this->balance($this->wip));
        $this->assertSame('0.0000', $this->balance($this->store));

        // And a GOOD return still works: the refusal is about the damaged
        // line, not about the door.
        $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '40']],
        ])->assertCreated();

        $this->assertSame('40.0000', $this->balance($this->store));
    }

    /**
     * A RETURN THAT SAYS NOTHING MEANS `good`, and every return written
     * before the column existed is read the same way. The alternative —
     * refusing a payload with no quality_state — would have closed the return
     * door on every existing caller over a field the factory has only just
     * been asked for.
     */
    public function test_a_return_that_names_no_condition_is_recorded_as_good(): void
    {
        $this->stockIn($this->wip, '100');

        $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '25']],
        ])->assertCreated();

        $this->assertSame(
            ReturnedQualityState::Good,
            StockMovement::query()
                ->where('purpose', StockMovementPurpose::ReturnFromProduction->value)
                ->where('type', StockMovementType::TransferIn->value)
                ->firstOrFail()
                ->quality_state,
        );
    }

    /** A condition nobody named is refused rather than guessed at. */
    public function test_an_unknown_condition_is_refused(): void
    {
        $this->stockIn($this->wip, '100');

        $this->postJson('/api/v1/inventory/production-returns', [
            'to_warehouse_id' => $this->store->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '25', 'quality_state' => 'soggy']],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.quality_state');

        // And it refused before it moved anything.
        $this->assertSame('100.0000', $this->balance($this->wip));
    }

    /**
     * NO OTHER MOVEMENT IS STAMPED WITH A CONDITION. A receipt is not being
     * asked what state its material is in, and defaulting it to `good` would
     * make "show me the damaged returns" a filter over a column that claims
     * an answer for every row in the ledger.
     */
    public function test_a_receipt_carries_no_quality_state_at_all(): void
    {
        $this->stockIn($this->store, '500');

        $this->assertNull(
            StockMovement::query()
                ->where('purpose', StockMovementPurpose::Opening->value)
                ->firstOrFail()
                ->quality_state,
        );
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * THE LIVE DEAD END, 04-Sep-2026. Pet Resin stood 360 Kgs. in
     * DISPATCH-BAY — a retired location no picker offers, whose stock
     * PreviewWarehouseStockRecovery withholds because moving it would credit
     * the Store with material the factory never received. soleStoreHolding()
     * counted it as a second store, so an unambiguous handover was refused
     * with "name the one it is coming out of" on a screen that has no field
     * to name one. The storekeeper could not issue resin at all.
     *
     * A retired location is not a store you can pick, so it is not an
     * ambiguity either.
     */
    public function test_stock_stranded_in_a_retired_location_is_not_a_second_store(): void
    {
        $retired = Warehouse::create(['code' => 'DISPATCH-BAY', 'name' => 'Dispatch Bay', 'is_active' => false]);

        $this->stockIn($this->store, '500');
        $this->stockIn($retired, '360');

        $this->postJson('/api/v1/inventory/store-issues', [
            'received_by' => $this->supervisor->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '100']],
        ])->assertCreated();

        // Out of the live store, and the stranded row is untouched.
        $this->assertSame('400.0000', $this->balance($this->store));
        $this->assertSame('100.0000', $this->balance($this->wip));
        $this->assertSame('360.0000', $this->balance($retired));
    }

    /** A soft-deleted store is no more pickable than a deactivated one. */
    public function test_stock_in_a_deleted_store_is_not_a_second_store(): void
    {
        $deleted = Warehouse::create(['code' => 'OLD-RM', 'name' => 'Old Store', 'is_active' => true]);
        $this->stockIn($this->store, '500');
        $this->stockIn($deleted, '75');
        $deleted->delete();

        $this->postJson('/api/v1/inventory/store-issues', [
            'received_by' => $this->supervisor->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '100']],
        ])->assertCreated();

        $this->assertSame('400.0000', $this->balance($this->store));
    }

    /** Two REAL stores is a real question — and the refusal names them. */
    public function test_two_live_stores_are_refused_by_name(): void
    {
        $second = Warehouse::create(['code' => 'RM-STORE-2', 'name' => 'Second Store', 'is_active' => true]);
        $this->stockIn($this->store, '500');
        $this->stockIn($second, '200');

        $response = $this->postJson('/api/v1/inventory/store-issues', [
            'received_by' => $this->supervisor->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '100']],
        ])->assertStatus(422);

        $message = json_encode($response->json('errors'));
        $this->assertStringContainsString('more than one store', $message);
        // Named, not counted — the whole point of the message.
        $this->assertStringContainsString('Store', $message);
        $this->assertStringContainsString('Second Store', $message);

        // Refused means nothing moved.
        $this->assertSame('500.0000', $this->balance($this->store));
        $this->assertSame('0.0000', $this->balance($this->wip));
    }

    /**
     * Held ONLY in a retired location gets its own answer. Saying "no store
     * holds this" would send the storekeeper hunting for material the balance
     * says is there, and saying "already in Production/WIP" — which the old
     * branch would now have said — is simply untrue.
     */
    public function test_material_only_in_a_retired_location_says_where_it_is_standing(): void
    {
        $retired = Warehouse::create(['code' => 'DISPATCH-BAY', 'name' => 'Dispatch Bay', 'is_active' => false]);
        $this->stockIn($retired, '360');

        $response = $this->postJson('/api/v1/inventory/store-issues', [
            'received_by' => $this->supervisor->id,
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '100']],
        ])->assertStatus(422);

        $message = json_encode($response->json('errors'));
        $this->assertStringContainsString('Dispatch Bay', $message);
        $this->assertStringContainsString('no longer offers stock for issue', $message);
        $this->assertStringNotContainsString('Production/WIP location', $message);
    }

    /** Naming the store explicitly still overrides all of it. */
    public function test_naming_the_store_is_still_honoured(): void
    {
        $second = Warehouse::create(['code' => 'RM-STORE-2', 'name' => 'Second Store', 'is_active' => true]);
        $this->stockIn($this->store, '500');
        $this->stockIn($second, '200');

        $this->postJson('/api/v1/inventory/store-issues', [
            'received_by' => $this->supervisor->id,
            'lines' => [[
                'item_id' => $this->resin->id,
                'quantity' => '100',
                'from_warehouse_id' => $second->id,
            ]],
        ])->assertCreated();

        $this->assertSame('500.0000', $this->balance($this->store));
        $this->assertSame('100.0000', $this->balance($second));
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function stockIn(Warehouse $warehouse, string $quantity): void
    {
        app(StockMovementService::class)->recordReceipt(
            itemId: $this->resin->id,
            warehouseId: $warehouse->id,
            quantity: $quantity,
            unitCost: '0',
            reference: 'Opening',
            purpose: StockMovementPurpose::Opening,
        );
    }

    private function balance(Warehouse $warehouse): string
    {
        return (string) (StockBalance::query()
            ->where('item_id', $this->resin->id)
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
