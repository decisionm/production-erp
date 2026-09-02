<?php

namespace Tests\Feature\Acceptance;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Enums\MeasurementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Production\Services\FactoryWarehouseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE FACTORY DOES NOT WORK IN KILOGRAMS.
 *
 * The 12-Aug Tally evidence carries three unit strings across 43 stock items —
 * `Kgs.`, `Nos.`, `Pcs.` — and **26 of those items are COUNTED, not weighed**:
 * trays, master boxes, caps, measuring cups, tape. Treating "material" and
 * "kilogram" as the same idea is wrong for most of the catalogue.
 *
 * So this walks the SAME chain twice, once per kind, each in its own unit:
 *
 *   PO -> GRN -> RM Store -> Material Request -> Store Issue -> Production/WIP
 *
 *   · WEIGHT — `Kgs.`, decimal, modelled on Relpet (27 PO lines in the evidence)
 *   · COUNT  — `Nos.`, whole, modelled on 60 Ml Tray (33 lines, the highest-
 *     volume counted master, and never once fractional)
 *
 * Deliberately NOT tape: FC-03 and DEC-20260807-005 keep tape display-only
 * until its posting is separately scoped, so using it would exercise a path the
 * constitution forbids.
 *
 * The units are spelled exactly as Tally writes them, trailing dot and all,
 * because `items.uom` carries Tally's BASEUNITS verbatim.
 */
class TwoUnitsChainTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $store;

    private Warehouse $wip;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.traceability_enabled' => true]);

        $user = User::factory()->create(['is_active' => true]);
        // quality.manage joined the list on 31-Aug-2026: a weighed arrival
        // now lands in incoming-QC hold and NOTHING may be issued from it
        // until an inspection releases it (owner decision, Q77 + the QA
        // clause). Receiving is no longer enough to make stock usable, so a
        // chain test has to walk the inspection too.
        foreach (['procurement.manage', 'inventory.manage', 'production.manage', 'quality.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        $this->store = Warehouse::create(['code' => 'TU-RM', 'name' => 'TU Raw Material Store', 'is_active' => true, 'tally_guid' => 'tu-gd']);
        $this->wip = Warehouse::create(['code' => 'TU-WIP', 'name' => 'TU Production WIP', 'is_active' => false]);

        app(ProductionWipLocationResolver::class)->setWarehouseId($this->wip->id);
        app(FactoryWarehouseResolver::class)->setRawMaterialWarehouseId($this->store->id);

        $this->vendor = Vendor::create(['code' => 'TU-V1', 'name' => 'TU Test Supplier', 'is_active' => true]);
    }

    public function test_the_classifier_reads_tallys_own_spellings(): void
    {
        // Verbatim, as Tally writes them into items.uom.
        $this->assertSame(MeasurementType::Weight, MeasurementType::forUom('Kgs.'));
        $this->assertSame(MeasurementType::Count, MeasurementType::forUom('Nos.'));
        $this->assertSame(MeasurementType::Count, MeasurementType::forUom('Pcs.'));

        // An unclassified unit stays UNKNOWN. It must never quietly become
        // weight — defaulting the unknown to kilograms is the whole assumption
        // this classifier exists to remove.
        $this->assertSame(MeasurementType::Unknown, MeasurementType::forUom('Roll'));
        $this->assertSame(MeasurementType::Unknown, MeasurementType::forUom(null));
        $this->assertTrue(MeasurementType::Unknown->permitsFractions(), 'refusing decimals on an unclassified unit would block real work on a guess');
    }

    /** A WEIGHT material, in kilograms, carrying decimals. */
    public function test_a_weight_material_walks_the_chain_in_kilograms(): void
    {
        $resin = $this->material('RM-RELPET', 'Relpet PET Resin', 'Kgs.');

        $this->receive($resin, '100', 'tu-kg');
        $this->assertSame(0, bccomp($this->balance($resin, $this->store), '100', 4));

        // A DECIMAL quantity — the evidence proves kg genuinely arrives and
        // moves in fractions (packing film at 14.700 kg).
        $this->requestAndIssue($resin, '37.5000');

        $this->assertSame(0, bccomp($this->balance($resin, $this->store), '62.5', 4));
        $this->assertSame(0, bccomp($this->balance($resin, $this->wip), '37.5', 4));

        $row = $this->floorRowFor($resin);
        $this->assertSame('Kgs.', $row['uom'], 'the floor panel reports the item OWN unit');
        $this->assertSame(0, bccomp((string) $row['quantity'], '37.5', 4));
    }

    /** A COUNTED material, in Nos., whole numbers only. */
    public function test_a_counted_material_walks_the_same_chain_in_nos(): void
    {
        $tray = $this->material('PKG-TRAY-60', '60 Ml Tray', 'Nos.');

        $this->receive($tray, '5000', 'tu-nos');
        $this->assertSame(0, bccomp($this->balance($tray, $this->store), '5000', 4));

        $this->requestAndIssue($tray, '1800');

        $this->assertSame(0, bccomp($this->balance($tray, $this->store), '3200', 4));
        $this->assertSame(0, bccomp($this->balance($tray, $this->wip), '1800', 4));

        $row = $this->floorRowFor($tray);
        $this->assertSame('Nos.', $row['uom'], 'a counted material is NOT reported in kilograms');
        $this->assertSame(0, bccomp((string) $row['quantity'], '1800', 4));
    }

    /** Half a tray is not a thing. */
    public function test_a_counted_material_refuses_a_fractional_request_and_issue(): void
    {
        $tray = $this->material('PKG-TRAY-60', '60 Ml Tray', 'Nos.');
        $this->receive($tray, '5000', 'tu-frac');

        $this->postJson('/api/v1/inventory/material-requests', [
            'lines' => [['item_id' => $tray->id, 'quantity' => '12.5']],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.quantity');

        $this->postJson('/api/v1/inventory/store-issues', [
            'lines' => [['item_id' => $tray->id, 'quantity' => '12.5']],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.quantity');
    }

    /** ...but a weight material keeps its decimals. */
    public function test_a_weight_material_still_accepts_a_fractional_quantity(): void
    {
        $resin = $this->material('RM-RELPET', 'Relpet PET Resin', 'Kgs.');
        $this->receive($resin, '100', 'tu-frac2');

        $this->postJson('/api/v1/inventory/material-requests', [
            'lines' => [['item_id' => $resin->id, 'quantity' => '14.7']],
        ])->assertCreated();
    }

    /** The caller does not get to rename the unit. */
    public function test_an_issue_may_not_name_a_unit_the_item_does_not_use(): void
    {
        $tray = $this->material('PKG-TRAY-60', '60 Ml Tray', 'Nos.');
        $this->receive($tray, '5000', 'tu-uom');

        $this->postJson('/api/v1/inventory/store-issues', [
            'lines' => [['item_id' => $tray->id, 'quantity' => '100', 'uom' => 'Kgs.']],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.uom');
    }

    /**
     * THE PATH THE STORE'S SCREEN ACTUALLY USES.
     *
     * The guards above were written, tested and shipped-ready while sitting
     * BELOW the accepted-ask branch — every arm of which ends in `continue`.
     * So they fired only on a fresh handover, the verbal-ask path, and were
     * unreachable on the one path the store issue queue posts on: a line that
     * names the request line it fulfils.
     *
     * 12.5 trays reached Production/WIP through it, 201 Created, and the
     * store's own InputNumber had no precision to stop it being typed.
     * A fully green suite missed it because every assertion in this file
     * posted WITHOUT a request line id.
     */
    public function test_the_unit_rules_survive_the_accepted_ask_path(): void
    {
        $tray = $this->material('PKG-TRAY-60', '60 Ml Tray', 'Nos.');
        $this->receive($tray, '5000', 'tu-ask');

        $ask = $this->submittedRequest($tray, '100');

        // Half a tray, filed against a real accepted line.
        $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $ask['id'],
            'lines' => [[
                'material_request_line_id' => $ask['line_id'],
                'item_id' => $tray->id,
                'quantity' => '12.5',
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.quantity');

        // A foreign unit, filed against a real accepted line. This one also
        // PERSISTED — store_issue_lines.uom held 'Kgs.' on a Nos. item and the
        // read path preferred it over the item's own.
        $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $ask['id'],
            'lines' => [[
                'material_request_line_id' => $ask['line_id'],
                'item_id' => $tray->id,
                'quantity' => '100',
                'uom' => 'Kgs.',
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.uom');

        $this->assertSame(0, bccomp($this->balance($tray, $this->wip), '0', 4), 'neither refusal moved stock');
    }

    /**
     * AND THE ACCEPTED ASK IS STILL FULFILLABLE.
     *
     * The guard belongs above the branch because a UNIT is a property of the
     * handover happening now. ELIGIBILITY is not — it was settled when the ask
     * was accepted, and stays settled. Tightening the issue side to match the
     * request side is a regression this codebase has already paid for once, so
     * this is the assertion that proves it was not repeated.
     */
    public function test_a_whole_number_against_an_accepted_ask_still_goes_through(): void
    {
        $tray = $this->material('PKG-TRAY-60', '60 Ml Tray', 'Nos.');
        $this->receive($tray, '5000', 'tu-ok');

        $ask = $this->submittedRequest($tray, '100');

        // The material is switched off AFTER the ask was accepted. History
        // stays issuable — that is the asymmetry the branch exists for.
        $tray->update(['is_production_input' => false]);

        $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $ask['id'],
            'lines' => [[
                'material_request_line_id' => $ask['line_id'],
                'item_id' => $tray->id,
                'quantity' => '100',
            ]],
        ])->assertCreated();

        $this->assertSame(0, bccomp($this->balance($tray, $this->wip), '100', 4));
    }

    /** A quantity is written the way a storekeeper writes one. */
    public function test_an_exotic_number_is_a_refusal_not_a_500(): void
    {
        $resin = $this->material('RM-RELPET', 'Relpet PET Resin', 'Kgs.');
        $this->receive($resin, '100', 'tu-exotic');

        // `numeric` accepts these; bcmath threw a ValueError on them, so the
        // quantity guard answered a malformed figure with a 500.
        foreach (['1e3', '0x1A', 'INF'] as $spelling) {
            $this->postJson('/api/v1/inventory/store-issues', [
                'lines' => [['item_id' => $resin->id, 'quantity' => $spelling]],
            ])->assertStatus(422);
        }
    }

    /** An issue may not be filed against a request that does not exist. */
    public function test_a_ghost_header_is_refused_rather_than_stored(): void
    {
        $resin = $this->material('RM-RELPET', 'Relpet PET Resin', 'Kgs.');
        $this->receive($resin, '100', 'tu-ghost');

        // Stock moved and `store_issues.material_request_id` was persisted as
        // 999999999 — a pointer nothing on either side could ever resolve.
        $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => 999999999,
            'lines' => [['item_id' => $resin->id, 'quantity' => '10']],
        ])->assertStatus(422)->assertJsonValidationErrors('material_request_id');

        $this->assertSame(0, bccomp($this->balance($resin, $this->wip), '0', 4));
    }

    /**
     * AN ITEM WITH NO RECORDED UNIT HAS NOTHING TO DISAGREE WITH.
     *
     * The unit-mismatch guard compared against a blank and refused, producing
     * "This material is kept in ." — an unusable sentence on a floor screen —
     * and meaning such an item could never be given a unit at handover.
     * Hoisting the block above the accepted-ask branch made that newly
     * reachable on the path the store uses, so the hoist widened exactly one
     * refusal. It is the only one, and this pins it closed.
     */
    public function test_an_item_with_no_unit_of_its_own_may_still_be_handed_over(): void
    {
        $odd = Item::create([
            'sku' => 'RM-NOUOM', 'name' => 'Master with no unit recorded', 'uom' => '',
            'is_active' => true, 'is_production_input' => true, 'category' => ItemCategory::RawMaterial,
        ]);
        $this->receive($odd, '100', 'tu-blank');

        $ask = $this->submittedRequest($odd, '10');

        $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $ask['id'],
            'lines' => [[
                'material_request_line_id' => $ask['line_id'],
                'item_id' => $odd->id,
                'quantity' => '2.5',
                'uom' => 'Kgs.',
            ]],
        ])->assertCreated();
    }

    /**
     * THE HEADER MUST NAME AN ASK THE STORE HAS ACTUALLY BEEN GIVEN.
     *
     * `exists` alone was not enough and the gap defeated a fix in the same
     * commit: an issue could be headed by production's UNSUBMITTED DRAFT. No
     * stock or document harm followed, but 201-vs-422 is an existence oracle
     * for draft ids — exactly what the 404 on show/cancel denies.
     */
    public function test_an_issue_may_not_be_headed_by_a_draft_or_a_cancelled_ask(): void
    {
        $resin = $this->material('RM-RELPET', 'Relpet PET Resin', 'Kgs.');
        $this->receive($resin, '100', 'tu-head');

        $draft = $this->postJson('/api/v1/inventory/material-requests', [
            'lines' => [['item_id' => $resin->id, 'quantity' => '10']],
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $draft['id'],
            'lines' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('material_request_id');

        $sent = $this->submittedRequest($resin, '10');
        $this->postJson("/api/v1/inventory/material-requests/{$sent['id']}/cancel", ['reason' => 'run pulled'])->assertOk();

        $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $sent['id'],
            'lines' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('material_request_id');
    }

    /** ...and the spellings bcmath DOES accept are still accepted. */
    public function test_the_quantity_rule_refuses_only_what_bcmath_refuses(): void
    {
        $resin = $this->material('RM-RELPET', 'Relpet PET Resin', 'Kgs.');
        $this->receive($resin, '100', 'tu-spell');

        // A first attempt at closing the 500 narrowed this to `-?\d+(\.\d+)?`
        // and started refusing three spellings the old code took happily.
        foreach (['.5', '1.', '+5'] as $spelling) {
            $this->postJson('/api/v1/inventory/store-issues', [
                'lines' => [['item_id' => $resin->id, 'quantity' => $spelling]],
            ])->assertCreated();
        }
    }

    /**
     * AND EVERY SPELLING OF A FRACTION IS STILL A FRACTION.
     *
     * This is the test that was missing, and its absence let the defect back
     * in. The one above asserts against a `Kgs.` item — which PERMITS
     * fractions — so it passed no matter what the counted-material guard did.
     *
     * Widening the validation rule to admit `.5`, `1.` and `+5` while the
     * private guard behind it kept the narrower spelling meant `+12.5` and
     * `.5` cleared the rule, failed to match the guard's own pattern, and
     * skipped the whole-number check completely: 26 fractional trays reached
     * Production/WIP with a 201, on BOTH paths, reopening exactly what the
     * previous commit had closed. Two copies of one predicate, again.
     */
    public function test_no_spelling_of_a_fraction_gets_past_a_counted_material(): void
    {
        $tray = $this->material('PKG-TRAY-60', '60 Ml Tray', 'Nos.');
        $this->receive($tray, '5000', 'tu-spell2');

        $ask = $this->submittedRequest($tray, '100');

        foreach (['12.5', '+12.5', '.5', '+.5', '-.5', '12.50', '12.5000'] as $spelling) {
            $this->postJson('/api/v1/inventory/store-issues', [
                'lines' => [['item_id' => $tray->id, 'quantity' => $spelling]],
            ])->assertStatus(422)->assertJsonValidationErrors('lines.0.quantity');

            $this->postJson('/api/v1/inventory/store-issues', [
                'material_request_id' => $ask['id'],
                'lines' => [[
                    'material_request_line_id' => $ask['line_id'],
                    'item_id' => $tray->id,
                    'quantity' => $spelling,
                ]],
            ])->assertStatus(422)->assertJsonValidationErrors('lines.0.quantity');
        }

        $this->assertSame(0, bccomp($this->balance($tray, $this->wip), '0', 4), 'not one fractional tray moved');

        // ...while the whole-number spellings of the same figure still work.
        $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $ask['id'],
            'lines' => [[
                'material_request_line_id' => $ask['line_id'],
                'item_id' => $tray->id,
                'quantity' => '+12',
            ]],
        ])->assertCreated();
    }

    /**
     * THE THIRD DOOR. Half a tray does not come back either.
     *
     * The request door and the issue door both refuse a fractional count. The
     * RETURN door did not, and it is the same stock — so returning 0.5 of a
     * counted material put fractional trays in BOTH locations at once, which
     * is not a state the factory can be in. Pre-existing rather than a
     * regression, closed here because a unit contract true of two doors out of
     * three is not a contract.
     */
    public function test_a_counted_material_cannot_come_back_in_halves(): void
    {
        $tray = $this->material('PKG-TRAY-60', '60 Ml Tray', 'Nos.');
        $this->receive($tray, '500', 'tu-ret');

        $issue = $this->postJson('/api/v1/inventory/store-issues', [
            'lines' => [['item_id' => $tray->id, 'quantity' => '40']],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/returns", [
            'lines' => [['store_issue_line_id' => $issue['lines'][0]['id'], 'quantity' => '0.5']],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.quantity');

        $this->assertSame(0, bccomp($this->balance($tray, $this->store), '460', 4), 'nothing came back');
        $this->assertSame(0, bccomp($this->balance($tray, $this->wip), '40', 4));

        // A whole number still comes back.
        $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/returns", [
            'lines' => [['store_issue_line_id' => $issue['lines'][0]['id'], 'quantity' => '10']],
        ])->assertOk();

        $this->assertSame(0, bccomp($this->balance($tray, $this->wip), '30', 4));
    }

    /**
     * ...and an exotic figure is a refusal on EVERY door, not a 500 on some.
     *
     * `is_numeric('1e3')` is true and bccomp() is not, so the material-request
     * side answered a malformed figure with a 500 — for counted items only,
     * because a weight item short-circuits before reaching it. One predicate
     * now guards all four doors.
     */
    public function test_an_exotic_number_is_a_refusal_on_every_door(): void
    {
        $tray = $this->material('PKG-TRAY-60', '60 Ml Tray', 'Nos.');
        $this->receive($tray, '500', 'tu-exotic2');

        foreach (['1e3', '0x1A', 'INF', 'NAN'] as $spelling) {
            $this->postJson('/api/v1/inventory/material-requests', [
                'lines' => [['item_id' => $tray->id, 'quantity' => $spelling]],
            ])->assertStatus(422);

            $this->postJson('/api/v1/inventory/store-issues', [
                'lines' => [['item_id' => $tray->id, 'quantity' => $spelling]],
            ])->assertStatus(422);
        }

        $issue = $this->postJson('/api/v1/inventory/store-issues', [
            'lines' => [['item_id' => $tray->id, 'quantity' => '40']],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/inventory/store-issues/{$issue['id']}/returns", [
            'lines' => [['store_issue_line_id' => $issue['lines'][0]['id'], 'quantity' => '1e3']],
        ])->assertStatus(422);
    }

    /**
     * EVERY FIELD ON THE DOOR, not just the one that was looked at.
     *
     * `quantity_requested` sat three lines above the converged rule in the same
     * array and kept the old spelling, so `1e400` reached the decimal cast as a
     * 500. There is no second definition of a number any more.
     */
    public function test_the_other_quantity_on_the_same_line_is_the_same_kind_of_number(): void
    {
        $resin = $this->material('RM-RELPET', 'Relpet PET Resin', 'Kgs.');
        $this->receive($resin, '100', 'tu-qr');

        foreach (['1e400', '1e3', '0x1A', 'INF'] as $spelling) {
            $this->postJson('/api/v1/inventory/store-issues', [
                'lines' => [['item_id' => $resin->id, 'quantity' => '5', 'quantity_requested' => $spelling]],
            ])->assertStatus(422);
        }

        $this->postJson('/api/v1/inventory/store-issues', [
            'lines' => [['item_id' => $resin->id, 'quantity' => '5', 'quantity_requested' => '60.0000']],
        ])->assertCreated();
    }

    /**
     * THE READ DOORS HAD NO FORM REQUEST AT ALL.
     *
     * `$request->string('status')` on raw input is a TypeError when the caller
     * sends an array, so `?status[]=issued` was a 500 rather than a 422, and
     * `per_page` was uncapped. No screen sends that shape — which is not a
     * validation rule.
     */
    public function test_the_store_issue_reads_answer_a_bad_query_with_a_refusal(): void
    {
        $resin = $this->material('RM-RELPET', 'Relpet PET Resin', 'Kgs.');

        foreach ([
            'store-issues?status[]=issued',
            'store-issues?per_page[]=5',
            'store-issues?per_page=999999',
            'store-issues?per_page=-5',
            'store-issues?status=banana',
            'store-issues?issued_from[]=x',
            'store-issues/trace?item_id[]=1',
            'store-issues/trace?item_id=1&as_of[]=x',
        ] as $query) {
            $this->getJson("/api/v1/inventory/{$query}")->assertStatus(422, $query);
        }

        // ...and what the screens actually send still works.
        $this->getJson('/api/v1/inventory/store-issues')->assertOk();
        $this->getJson('/api/v1/inventory/store-issues?status=issued&per_page=20')->assertOk();
        $this->getJson("/api/v1/inventory/store-issues/trace?item_id={$resin->id}")->assertOk();
    }

    /* ------------------------------ helpers ------------------------------ */

    private function material(string $sku, string $name, string $uom): Item
    {
        return Item::create([
            'sku' => $sku, 'name' => $name, 'uom' => $uom,
            'is_active' => true, 'is_production_input' => true, 'category' => ItemCategory::RawMaterial,
        ]);
    }

    /** PO -> send -> GRN. No lots: bag capture is weight-only by design. */
    private function receive(Item $item, string $quantity, string $key): void
    {
        $orderId = $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $this->vendor->id,
            'order_date' => '2026-08-10',
            'expected_date' => '2026-08-20',
            'lines' => [['item_id' => $item->id, 'quantity' => $quantity, 'unit_price' => '1.00']],
        ])->assertCreated()->json('data.id');

        $lineId = $this->getJson("/api/v1/procurement/purchase-orders/{$orderId}")->assertOk()->json('data.lines.0.id');
        $this->postJson("/api/v1/procurement/purchase-orders/{$orderId}/send")->assertOk();

        $this->postJson('/api/v1/procurement/goods-receipts', [
            'receipt_key' => $key,
            'purchase_order_id' => $orderId,
            'warehouse_id' => $this->store->id,
            'reference' => 'TU-DC',
            'received_date' => '2026-08-18',
            // A weighed arrival is counted at the gate now (Q77): one bag
            // holding the whole delivery, whose weight reconciles exactly to
            // the received quantity. A COUNTED material takes no lots block —
            // bag lots are kg-only — which is the difference this whole test
            // class exists to hold apart.
            'lines' => [array_filter([
                'purchase_order_line_id' => $lineId,
                'quantity' => $quantity,
                'lots' => Item::isKgUom($item->uom)
                    ? [['bag_count' => 1, 'bag_weight_kg' => $quantity]]
                    : null,
            ], static fn ($value) => $value !== null)],
        ])->assertCreated();

        // AND THEN QA RELEASES IT. A weighed arrival is born waiting for
        // incoming QC, and held bags may not leave a store by any door
        // (DEC-20260825-001) — so without this the material is received and
        // unusable, which is the point of the hold and would otherwise read
        // here as a broken chain.
        if (Item::isKgUom($item->uom)) {
            $grnLineId = GoodsReceiptNote::query()->latest('id')->firstOrFail()->lines()->value('id');

            $this->postJson('/api/v1/quality/incoming-inspections', [
                'goods_receipt_note_line_id' => $grnLineId,
                'inspected_quantity' => $quantity,
                'accepted_quantity' => $quantity,
                'rejected_quantity' => '0',
                'inspection_date' => '2026-08-18',
            ])->assertSuccessful();
        }
    }

    /** An accepted ask, submitted and waiting — not yet issued against. */
    private function submittedRequest(Item $item, string $quantity): array
    {
        $request = $this->postJson('/api/v1/inventory/material-requests', [
            'lines' => [['item_id' => $item->id, 'quantity' => $quantity]],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/inventory/material-requests/{$request['id']}/submit")->assertOk();

        return ['id' => $request['id'], 'line_id' => $request['lines'][0]['id']];
    }

    private function requestAndIssue(Item $item, string $quantity): void
    {
        $request = $this->postJson('/api/v1/inventory/material-requests', [
            'lines' => [['item_id' => $item->id, 'quantity' => $quantity]],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/inventory/material-requests/{$request['id']}/submit")->assertOk();

        $this->postJson('/api/v1/inventory/store-issues', [
            'material_request_id' => $request['id'],
            'lines' => [[
                'material_request_line_id' => $request['lines'][0]['id'],
                'item_id' => $item->id,
                'quantity' => $quantity,
            ]],
        ])->assertCreated();
    }

    private function floorRowFor(Item $item): array
    {
        $rows = $this->getJson('/api/v1/inventory/production-floor-stock')->assertOk()->json('data');

        return collect($rows)->firstWhere('item_id', $item->id) ?? [];
    }

    private function balance(Item $item, Warehouse $warehouse): string
    {
        return bcadd((string) (StockBalance::query()
            ->where('item_id', $item->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('quantity') ?? '0'), '0', 4);
    }
}
