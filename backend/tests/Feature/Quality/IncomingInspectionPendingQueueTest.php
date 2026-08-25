<?php

namespace Tests\Feature\Quality;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Quality\Models\IncomingInspection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE INCOMING-QC DESK'S QUEUE, AND WHAT IT IS ALLOWED TO SAY.
 *
 * Three separate defects are pinned here, all of them found by running the
 * code rather than reading it:
 *
 *  1. THE QUEUE DID NOT EXIST. The page fed its GRN-line picker from
 *     `listGoodsReceipts()` — page one of twenty receipts — under
 *     `module:procurement`. A quality-only login got a 403 and an empty
 *     picker; a login that did hold procurement.view got the twenty most
 *     recent receipts and no indication that anything older was missing.
 *     This is the defect class `pickerFullList.test.ts` exists for.
 *
 *  2. `1e3` WAS A 500. `numeric` accepts scientific notation, bcmath does
 *     not, so a malformed figure reached `bccomp` and raised a ValueError
 *     out of IncomingInspectionService::create(). Reproduced on the parent
 *     commit as `500 bccomp(): Argument #1 ($num1) is not well-formed`.
 *
 *  3. THE REPORTED FALSE REJECTION AT EQUALITY DOES NOT REPRODUCE AT THE
 *     FIGURES REPORTED, and this file says so in executable form rather than
 *     in prose: received 123450 / inspected 123450 and received 1000000 /
 *     inspected 1000000 both post cleanly, on the parent commit and on this
 *     one. What IS measurable is narrower: PHP's float->string loses
 *     digits past precision 14, so a JSON *number* reaches bcmath altered.
 *     Whether that becomes a false refusal depends on the database storing
 *     the received side exactly — SQLite (this suite) does not, so it cannot
 *     be shown here, and the test below measures the former and says so
 *     about the latter rather than asserting a contrast it never runs.
 *
 * The route is /api/v1/quality/incoming-inspections/pending
 * (IncomingInspectionController::pending → IncomingInspectionService::
 * pendingLines) — the real path, asserted against real rows.
 */
class IncomingInspectionPendingQueueTest extends TestCase
{
    use RefreshDatabase;

    private const PENDING = '/api/v1/quality/incoming-inspections/pending';

    private const STORE = '/api/v1/quality/incoming-inspections';

    private Warehouse $warehouse;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::create(['code' => 'IQ-RM', 'name' => 'IQ Raw Material Store', 'is_active' => true]);
        $this->vendor = Vendor::create(['code' => 'IQ-V1', 'name' => 'IQ Confidential Supplier Pvt Ltd', 'is_active' => true]);
    }

    // ---- fixtures ---------------------------------------------------------

    private function actingAsQuality(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions === [] ? ['quality.manage'] : $permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * One arrival line, built straight through the models — the queue reads
     * GRN lines, and the receipt WRITER (stock posting, bags, lots) is
     * deliberately not exercised here: this branch changes none of it.
     */
    private function arrivalLine(string $quantity, string $sku = 'IQ-RESIN', string $uom = 'KGS'): GoodsReceiptNoteLine
    {
        $item = Item::query()->firstOrCreate(
            ['sku' => $sku],
            ['name' => "{$sku} material", 'uom' => $uom, 'is_active' => true],
        );

        $order = PurchaseOrder::create([
            'vendor_id' => $this->vendor->id,
            'order_date' => '2026-08-01',
            'status' => 'draft',
        ]);
        $orderLine = PurchaseOrderLine::create([
            'purchase_order_id' => $order->id,
            'item_id' => $item->id,
            'quantity' => $quantity,
            'unit_price' => '73.5000',
        ]);
        $receipt = GoodsReceiptNote::create([
            'purchase_order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'received_date' => '2026-08-02',
            'reference' => 'IQ-DC-9911',
        ]);

        return GoodsReceiptNoteLine::create([
            'goods_receipt_note_id' => $receipt->id,
            'purchase_order_line_id' => $orderLine->id,
            'item_id' => $item->id,
            'quantity' => $quantity,
            // The purchase rate FC-06 keeps off a quality screen.
            'unit_cost' => '73.5000',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function inspect(GoodsReceiptNoteLine $line, array $overrides = [])
    {
        return $this->postJson(self::STORE, [
            'goods_receipt_note_line_id' => $line->id,
            'inspected_quantity' => $line->quantity,
            'accepted_quantity' => $line->quantity,
            'rejected_quantity' => '0',
            'inspection_date' => '2026-08-02',
            ...$overrides,
        ]);
    }

    // =====================================================================
    // A. The queue is the WHOLE queue
    // =====================================================================

    public function test_more_than_one_page_of_pending_lines_all_come_back(): void
    {
        $this->actingAsQuality();

        // 25 > the 20 that every paginated list in this app defaults to.
        $expected = [];
        for ($n = 1; $n <= 25; $n++) {
            $expected[] = $this->arrivalLine('100', "IQ-M{$n}")->id;
        }

        $response = $this->getJson(self::PENDING)->assertOk();

        $this->assertSame($expected, $response->json('data.*.id'));
        $this->assertCount(25, $response->json('data'));

        // And it is NOT a paginator that happens to have a big page: a
        // `meta.per_page` here would be a truncation waiting to happen the
        // day a 21st line arrives.
        $this->assertNull($response->json('meta'));
        $this->assertNull($response->json('links'));
    }

    public function test_a_line_that_has_been_inspected_is_no_longer_pending(): void
    {
        $this->actingAsQuality();

        $done = $this->arrivalLine('100', 'IQ-DONE');
        $waiting = $this->arrivalLine('250.5000', 'IQ-WAITING');

        $this->assertSame([$done->id, $waiting->id], $this->getJson(self::PENDING)->json('data.*.id'));

        $this->inspect($done)->assertCreated();

        // Exclusion is the complement of the one-disposition-per-line refusal
        // the service already enforces — not a new lifecycle state.
        $this->assertSame([$waiting->id], $this->getJson(self::PENDING)->json('data.*.id'));
        $this->inspect($done)->assertStatus(422);
    }

    public function test_a_successful_inspection_removes_exactly_its_own_row(): void
    {
        $this->actingAsQuality();

        $a = $this->arrivalLine('10', 'IQ-A');
        $b = $this->arrivalLine('20', 'IQ-B');
        $c = $this->arrivalLine('30', 'IQ-C');

        $this->inspect($b)->assertCreated();

        $this->assertSame([$a->id, $c->id], $this->getJson(self::PENDING)->json('data.*.id'));
        $this->assertSame(1, IncomingInspection::query()->count());
    }

    // =====================================================================
    // B. FC-06 — what a quality login is served, exactly
    // =====================================================================

    public function test_the_pending_payload_carries_no_vendor_rate_or_accounting_field(): void
    {
        $this->actingAsQuality();
        $line = $this->arrivalLine('123450', 'IQ-FC06');

        $row = $this->getJson(self::PENDING)->assertOk()->json('data.0');

        // THE EXACT KEY SET, not "some forbidden key is absent" — a missing-
        // key assertion passes vacuously the moment a field is renamed or
        // nested one level deeper.
        $this->assertSame(
            ['id', 'grn_reference', 'item', 'received_quantity', 'uom'],
            array_keys($row),
        );
        $this->assertSame(['id', 'sku', 'name'], array_keys($row['item']));

        $this->assertSame($line->id, $row['id']);
        $this->assertSame('GRN-'.$line->goods_receipt_note_id, $row['grn_reference']);
        $this->assertSame('IQ-FC06', $row['item']['sku']);
        $this->assertSame('KGS', $row['uom']);

        // Belt and braces: the whole serialised body, searched for the rate,
        // the supplier and the delivery challan that DO exist on these rows.
        $body = $this->getJson(self::PENDING)->getContent();
        foreach (['73.5', 'unit_cost', 'unit_price', 'vendor', 'IQ-Confidential', 'IQ-DC-9911', 'invoice', 'material_lots'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "the queue leaked `{$forbidden}`");
        }
    }

    public function test_the_received_quantity_is_the_exact_decimal_string_the_column_holds(): void
    {
        $this->actingAsQuality();
        $this->arrivalLine('123450.5000', 'IQ-EXACT');

        // Not 123450.5, not 123450, not a float — the figure the inspection
        // is measured against, character for character.
        $this->assertSame('123450.5000', $this->getJson(self::PENDING)->json('data.0.received_quantity'));
    }

    // =====================================================================
    // C. Permissions — both sides of the gate
    // =====================================================================

    public function test_quality_view_alone_reads_the_queue_but_cannot_record_an_inspection(): void
    {
        $this->actingAsQuality('quality.view');
        $line = $this->arrivalLine('100', 'IQ-RO');

        $this->getJson(self::PENDING)->assertOk();
        // POST needs quality.manage — reading the queue is not recording a
        // disposition (EnsureModulePermission).
        $this->inspect($line)->assertStatus(403);
    }

    public function test_a_procurement_login_without_quality_cannot_read_the_queue(): void
    {
        $this->actingAsQuality('procurement.manage', 'finance.manage');
        $this->arrivalLine('100', 'IQ-PROC');

        $this->getJson(self::PENDING)->assertStatus(403);
    }

    public function test_the_queue_refuses_a_guest(): void
    {
        $this->arrivalLine('100', 'IQ-GUEST');

        $this->getJson(self::PENDING)->assertStatus(401);
    }

    // =====================================================================
    // D. Quantities — equality, the smallest overage, and the column's edges
    // =====================================================================

    /**
     * THE REPORTED CASE. Received 123450, inspected 123450 — this is the
     * figure the bug report named, and it posts cleanly. Kept as a
     * regression guard and as the evidence for the PR's narrow-blocker note.
     */
    public function test_inspecting_exactly_what_was_received_succeeds_at_123450(): void
    {
        $this->actingAsQuality();
        $line = $this->arrivalLine('123450', 'IQ-EQ1');

        $response = $this->inspect($line)->assertCreated();

        $this->assertSame('123450.0000', $response->json('data.inspected_quantity'));
        $this->assertSame('123450.0000', $response->json('data.accepted_quantity'));
        $this->assertSame('0.0000', $response->json('data.rejected_quantity'));
        $this->assertSame('pass', $response->json('data.result'));
    }

    public function test_inspecting_exactly_what_was_received_succeeds_at_1000000(): void
    {
        $this->actingAsQuality();
        $line = $this->arrivalLine('1000000', 'IQ-EQ2');

        $this->inspect($line)->assertCreated()
            ->assertJsonPath('data.inspected_quantity', '1000000.0000');
    }

    public function test_the_smallest_representable_overage_is_refused(): void
    {
        $this->actingAsQuality();
        $line = $this->arrivalLine('123450', 'IQ-OVER');

        // One ten-thousandth over — the last figure decimal(15,4) can tell
        // apart from equality. Equality passes (above); this must not.
        $this->inspect($line, [
            'inspected_quantity' => '123450.0001',
            'accepted_quantity' => '123450.0001',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Cannot inspect more than the quantity received on this line: received 123450.0000, inspected 123450.0001.');

        $this->assertSame(0, IncomingInspection::query()->count());
    }

    public function test_the_columns_maximum_is_accepted_and_one_more_is_refused(): void
    {
        $this->actingAsQuality();

        // decimal(15,4): eleven integer digits. 99999999999 is the whole-
        // number ceiling the column can hold.
        $max = $this->arrivalLine('99999999999', 'IQ-MAX');
        $this->inspect($max)->assertCreated()
            ->assertJsonPath('data.inspected_quantity', '99999999999.0000');

        // One more integer digit does not fit the column, so it is a 422 from
        // the request — never a truncated write and never a 500 from bcmath.
        $over = $this->arrivalLine('100', 'IQ-OVERMAX');
        $this->inspect($over, [
            'inspected_quantity' => '100000000000',
            'accepted_quantity' => '100000000000',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['inspected_quantity', 'accepted_quantity']);
    }

    /**
     * WHAT IS ACTUALLY MEASURED ABOUT THE PRECISION BOUNDARY — no more.
     *
     * The VERIFIED fact is one line of PHP: a JSON number is decoded to a
     * float, and a float carrying more significant digits than PHP's default
     * precision of 14 stringifies short. `json_decode('12345678901.2345')`
     * reaches bcmath as the string `12345678901.235` — a DIFFERENT figure
     * from the one the operator typed, and a larger one.
     *
     * WHAT IS NOT VERIFIED HERE, STATED PLAINLY. Whether that shortened
     * figure produces a false "exceeds received" refusal depends on the
     * database storing the received quantity exactly. This suite runs SQLite
     * (phpunit.xml), where `decimal(15,4)` is float-affinity and the STORED
     * side rounds identically — both sides move together, the compare comes
     * out equal, and the request succeeds. So the false rejection cannot be
     * demonstrated on this database, and this test does not pretend to
     * demonstrate it: it asserts what actually happens here, which is 201 for
     * both spellings.
     *
     * WHAT IS FIXED REGARDLESS. The decimal STRING is never converted at all,
     * so it reaches bcmath as typed and comes back as stored, whatever the
     * database underneath. That is why the rebuilt page sends strings, and it
     * is what this pins.
     */
    public function test_a_decimal_string_reaches_bcmath_unaltered(): void
    {
        $this->actingAsQuality();

        // The premise, measured rather than assumed.
        $this->assertSame('12345678901.235', (string) json_decode('12345678901.2345'));
        $this->assertSame('1.0E+20', (string) json_decode('1e20'));

        $line = $this->arrivalLine('12345678901.2345', 'IQ-PREC');
        $stored = $line->fresh()->quantity;

        // The string the page now sends: through untouched, echoed exactly.
        $this->inspect($line, ['inspected_quantity' => $stored, 'accepted_quantity' => $stored])
            ->assertCreated()
            ->assertJsonPath('data.inspected_quantity', $stored);

        // And the float leg, for the record: on SQLite it also succeeds,
        // because the stored side rounded the same way. Asserted so that a
        // future move to MySQL in CI turns this into a real, visible
        // difference instead of a silent one.
        $float = $this->arrivalLine('12345678901.2345', 'IQ-PREC2');
        $this->inspect($float, [
            'inspected_quantity' => 12345678901.2345,
            'accepted_quantity' => 12345678901.2345,
        ])->assertCreated();
    }

    // =====================================================================
    // E. Malformed figures are 422s, never 500s
    // =====================================================================

    #[DataProvider('malformedQuantities')]
    public function test_a_malformed_quantity_is_a_422_not_a_500(string $value): void
    {
        $this->actingAsQuality();
        $line = $this->arrivalLine('1000', 'IQ-MAL'.md5($value));

        $response = $this->inspect($line, [
            'inspected_quantity' => $value,
            'accepted_quantity' => $value,
        ]);

        $this->assertSame(422, $response->status(), "`{$value}` was not refused cleanly");
        $response->assertJsonValidationErrors(['inspected_quantity']);
        $this->assertSame(0, IncomingInspection::query()->count());
    }

    public static function malformedQuantities(): array
    {
        return [
            // The one that was a live 500: bccomp() ValueError at
            // IncomingInspectionService.php:60.
            'scientific notation' => ['1e3'],
            'scientific notation, capital' => ['1E3'],
            'scientific notation, negative exponent' => ['5e-2'],
            'hexadecimal' => ['0x1A'],
            'infinity' => ['1e400'],
            'letters' => ['12abc'],
            'thousands separators' => ['1,234'],
            'empty' => [''],
        ];
    }

    public function test_the_pending_queue_is_untouched_by_a_refused_inspection(): void
    {
        $this->actingAsQuality();
        $line = $this->arrivalLine('1000', 'IQ-UNTOUCHED');

        $this->inspect($line, ['inspected_quantity' => '1e3', 'accepted_quantity' => '1e3'])->assertStatus(422);

        // A refusal is not a disposition: the line is still the desk's work.
        $this->assertSame([$line->id], $this->getJson(self::PENDING)->json('data.*.id'));
    }
}
