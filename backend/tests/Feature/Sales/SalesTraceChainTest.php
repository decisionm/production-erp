<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\TallySync\Models\Enums\TallyLedgerRole;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Services\AgentTokenService;
use App\Modules\TallySync\Services\TallyLedgerMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE SALES TRACE CHAIN, END TO END (Phase 3.5): one order walked through
 * every ERP-originated document that can hang off it, then read back
 * through the three show endpoints with their `trace`.
 *
 *   SO (draft) → confirm → dispatch by CARTON SCAN (2 boxes of an approved
 *   batch) → a TYPED delivery → a draft invoice (no voucher) → issue (a
 *   Sales voucher) → GET sales-orders/{id}: both deliveries, the scanned
 *   one WITH its cartons and their batch, the invoice, and a TallyLink on
 *   each — all `pending`, each flagged `unvalidated_builder` (the agent's
 *   Delivery Note and Sales builders say so themselves; the Sales one
 *   names DEC-20260809-003) → the REAL agent polls, acks one Delivery
 *   Note and fails the other → the trace says synced / failed, the Sales
 *   link still pending, and no error text, payload or rate rides any link.
 *
 * What a TallyLink is NOT is as much the contract as what it is: status +
 * flags + link only. It never carries the voucher payload, a rate, or
 * Tally's rejection text — the Control Center (linked to) is where those
 * are read, under their own gates (FC-06, SupplierIdentityVisibilityTest).
 *
 * The show endpoints are reads: sales.view (or sales.manage) sees them,
 * anyone else is refused; a missing id is a 404. Nothing here reads from
 * Tally — every "synced" below is the agent's own report over its bearer
 * token, exactly as it arrives from the factory PC.
 */
class SalesTraceChainTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Warehouse $fg;

    private Customer $customer;

    private User $salesDesk;

    private ?string $agentToken = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->salesDesk = $this->userWith('Sales Desk', ['production.view', 'production.manage', 'sales.view', 'sales.manage']);
        $this->asUser($this->salesDesk);

        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos', 'tally_stock_item_guid' => 'itm-bottle']);
        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'tally_guid' => 'gd-fg']);
        $this->customer = Customer::create(['code' => 'CUST-1', 'name' => 'Aqua Traders', 'gstin' => '33AAACA1111A1Z5']);

        // The configured Sales ledger rides the invoice payload; nothing
        // here reads it back, but the enqueue must not trip on its absence.
        app(TallyLedgerMappingService::class)->setMany([TallyLedgerRole::Sales->value => 'Sales - Local']);

        // 5000 bottles on the FG shelf: enough for every dispatch below.
        app(StockMovementService::class)->recordReceipt(
            itemId: $this->bottle->id, warehouseId: $this->fg->id, quantity: '5000', unitCost: '2.50', reference: 'seed',
        );
    }

    // ---- actors -------------------------------------------------------------

    /** @param  list<string>  $permissions */
    private function userWith(string $name, array $permissions): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    /** Subsequent requests come from this person, over the SPA's session auth. */
    private function asUser(User $user): static
    {
        $this->withoutToken();
        Sanctum::actingAs($user);

        return $this;
    }

    /** Subsequent requests come from the factory PC, over its real bearer token (PerTypeLifecycleTestCase). */
    private function asAgent(): static
    {
        if ($this->agentToken === null) {
            $this->agentToken = app(AgentTokenService::class)->issueToken('factory-pc')['plainTextToken'];
        }

        // Forget the seated staff user, or the token is never resolved.
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->agentToken);
    }

    // ---- fixtures -----------------------------------------------------------

    /** An APPROVED, counted batch with its cartons labelled (3 × 600 + 360) — the scan path's source. */
    private function approvedLabelledBatch(string $batchNumber = '20260802-M01-001'): ShiftProductionEntry
    {
        $shift = Shift::firstOrCreate(['name' => 'Morning'], ['start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $machine = WorkCenter::firstOrCreate(['code' => 'MC-01'], ['name' => 'Machine 1', 'is_active' => true]);

        $entry = ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => '2026-08-02',
            'batch_number' => $batchNumber,
            'status' => ShiftProductionEntryStatus::Pending,
            'batch_status' => BatchStatus::Completed,
            'quantity_produced' => '2160',
            'nos_per_box' => '600',
        ]);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/cartons")->assertSuccessful();
        $entry->update(['status' => ShiftProductionEntryStatus::Approved]);

        return $entry;
    }

    private function confirmedOrder(string $quantity = '3000'): SalesOrder
    {
        $id = $this->postJson('/api/v1/sales/sales-orders', [
            'customer_id' => $this->customer->id,
            'order_date' => '2026-08-09',
            'expected_date' => '2026-08-20',
            'lines' => [['item_id' => $this->bottle->id, 'quantity' => $quantity, 'unit_price' => '4.50']],
        ])->assertSuccessful()->assertJsonPath('data.status', 'draft')->json('data.id');

        $this->postJson("/api/v1/sales/sales-orders/{$id}/confirm")->assertSuccessful()->assertJsonPath('data.status', 'confirmed');

        return SalesOrder::query()->with('lines')->findOrFail($id);
    }

    /** @param  list<string>  $cartons */
    private function dispatchByScan(SalesOrder $order, array $cartons): int
    {
        return $this->postJson('/api/v1/sales/deliveries', [
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->fg->id,
            'delivered_date' => '2026-08-10',
            'reference' => 'LR-1001',
            'carton_codes' => $cartons,
        ])->assertSuccessful()->json('data.id');
    }

    private function dispatchTyped(SalesOrder $order, string $quantity): int
    {
        return $this->postJson('/api/v1/sales/deliveries', [
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->fg->id,
            'delivered_date' => '2026-08-11',
            'reference' => 'LR-1002',
            'lines' => [['sales_order_line_id' => $order->lines->first()->id, 'quantity' => $quantity]],
        ])->assertSuccessful()->json('data.id');
    }

    private function draftInvoice(SalesOrder $order, string $quantity): int
    {
        return $this->postJson('/api/v1/sales/invoices', [
            'sales_order_id' => $order->id,
            'invoice_date' => '2026-08-12',
            'lines' => [['sales_order_line_id' => $order->lines->first()->id, 'quantity' => $quantity, 'unit_price' => '4.50']],
        ])->assertSuccessful()->assertJsonPath('data.status', 'draft')->json('data.id');
    }

    // ---- readers ------------------------------------------------------------

    private function showOrder(int $id): TestResponse
    {
        return $this->getJson("/api/v1/sales/sales-orders/{$id}")->assertOk();
    }

    /**
     * The `trace` of a show response. The contract says "Resource + trace";
     * both natural renderings — `trace` inside `data`, or beside it via
     * ->additional() — are accepted here so this chain test judges the
     * CONTENT of the trace, not where the resource put the key.
     */
    private function traceOf(TestResponse $response): array
    {
        $json = $response->json();
        $trace = $json['data']['trace'] ?? $json['trace'] ?? null;

        $this->assertIsArray($trace, 'A show response must carry a trace');

        return $trace;
    }

    /** The one Delivery Note / Sales entry queued for a syncable id. */
    private function entryFor(string $voucherType, int $syncableId): TallySyncEntry
    {
        return TallySyncEntry::query()
            ->where('tally_voucher_type', $voucherType)
            ->where('syncable_id', $syncableId)
            ->sole();
    }

    /**
     * A TallyLink is status + flags + link, and nothing that belongs behind
     * the Control Center's own gates.
     */
    private function assertTallyLink(array $link, TallySyncEntry $entry, string $status): void
    {
        foreach (['entry_id', 'voucher_type', 'status', 'voucher_number', 'synced_at', 'flags', 'link'] as $key) {
            $this->assertArrayHasKey($key, $link, "TallyLink must carry `{$key}`");
        }
        $this->assertSame($entry->id, $link['entry_id']);
        $this->assertSame($entry->tally_voucher_type, $link['voucher_type']);
        $this->assertSame($status, $link['status']);
        $this->assertSame($entry->payload['voucher_number'], $link['voucher_number']);
        $this->assertSame("/tally-sync?entry={$entry->id}", $link['link']);

        // Never on a link: the payload (rates, lines), Tally's rejection
        // text, the repair log that quotes it — those live on the entry,
        // behind the Control Center's own gates.
        foreach (['payload', 'error_message', 'error_withheld', 'rate', 'lines', 'total_amount', 'resolution_log'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $link, "A TallyLink must not carry `{$forbidden}`");
        }
    }

    /**
     * The `unvalidated_builder` flag — EntryPresenter::flags' own object
     * for the Delivery Note and Sales builders (a boolean true is accepted
     * too, should the link ever compress it). Truthy is the contract.
     */
    private function assertUnvalidatedBuilderFlag(array $link, ?string $decision = null): void
    {
        $this->assertIsArray($link['flags']);
        $this->assertArrayHasKey('unvalidated_builder', $link['flags']);
        $flag = $link['flags']['unvalidated_builder'];
        $this->assertTrue((bool) $flag, 'unvalidated_builder must be truthy on a Delivery Note / Sales link');

        if ($decision !== null && is_array($flag)) {
            // The Sales builder's flag names the decision that keeps real
            // sales in Tally — the warning the drawer renders next to it.
            $this->assertSame($decision, $flag['decision'] ?? null);
        }
    }

    // ---- the chain ----------------------------------------------------------

    public function test_the_whole_chain_reads_back_through_the_order_and_the_agents_report_is_reflected(): void
    {
        $batch = $this->approvedLabelledBatch();
        $order = $this->confirmedOrder('3000');

        // 1. Two documents leave the gate: 2 scanned cartons (2 × 600) and a
        //    typed 600 — the order is now partially delivered (1800 of 3000).
        $scanned = $this->dispatchByScan($order, ['20260802-M01-001-C01', '20260802-M01-001-C02']);
        $typed = $this->dispatchTyped($order, '600');
        $this->assertSame('1800.0000', (string) $order->lines()->first()->fresh()->quantity_delivered);

        // 2. A draft invoice has no voucher — the show says so with a null
        //    TallyLink, not a made-up "pending".
        $invoiceId = $this->draftInvoice($order, '1800');
        $draft = $this->getJson("/api/v1/sales/invoices/{$invoiceId}")->assertOk();
        $this->assertNull($draft->json('data.tally'), 'A draft invoice has no Tally voucher and its link must be null');
        $this->assertNull($this->traceOf($draft)['tally']);
        $this->assertSame(2, TallySyncEntry::query()->count(), 'Two Delivery Notes and nothing else so far');

        // 3. Issue → the Sales voucher is queued.
        $this->postJson("/api/v1/sales/invoices/{$invoiceId}/issue")->assertSuccessful()->assertJsonPath('data.status', 'issued');
        $dnScanned = $this->entryFor('Delivery Note', $scanned);
        $dnTyped = $this->entryFor('Delivery Note', $typed);
        $sales = $this->entryFor('Sales', $invoiceId);
        $this->assertSame(3, TallySyncEntry::query()->count());

        // 4. The order's show: header, totals, counts, and the trace.
        $shown = $this->showOrder($order->id);
        $shown->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.document_number', "SO-{$order->id}")
            ->assertJsonPath('data.status', 'partially_delivered')
            ->assertJsonPath('data.customer.id', $this->customer->id)
            ->assertJsonPath('data.customer.name', 'Aqua Traders')
            ->assertJsonPath('data.totals.ordered_quantity', '3000.0000')
            ->assertJsonPath('data.totals.delivered_quantity', '1800.0000')
            ->assertJsonPath('data.totals.invoiced_quantity', '1800.0000')
            ->assertJsonPath('data.deliveries_count', 2)
            ->assertJsonPath('data.invoices_count', 1)
            ->assertJsonPath('data.can_cancel', false);

        $trace = $this->traceOf($shown);
        $this->assertCount(2, $trace['deliveries']);
        $this->assertCount(1, $trace['invoices']);

        $deliveries = collect($trace['deliveries'])->keyBy('id');
        $this->assertSame([$scanned, $typed], $deliveries->keys()->sort()->values()->all());

        // 4a. The scanned delivery: its two cartons by number, pieces and
        //     batch — the carton → batch → delivery traceability the scan
        //     path promised (DEC-20260807-013) — and its line derived from them.
        $scannedRow = $deliveries[$scanned];
        $this->assertSame("DN-{$scanned}", $scannedRow['document_number']);
        $this->assertSame('LR-1001', $scannedRow['reference']);
        $this->assertStringStartsWith('2026-08-10', (string) $scannedRow['delivered_date']);
        $this->assertSame(['id' => $this->fg->id, 'name' => 'FG Store'], ['id' => $scannedRow['warehouse']['id'], 'name' => $scannedRow['warehouse']['name']]);
        $this->assertCount(1, $scannedRow['lines']);
        $this->assertSame($this->bottle->id, $scannedRow['lines'][0]['item']['id']);
        $this->assertSame('500ml PET Bottle', $scannedRow['lines'][0]['item']['name']);
        $this->assertSame('1200.0000', (string) $scannedRow['lines'][0]['quantity']);
        $cartons = collect($scannedRow['cartons'])->sortBy('carton_no')->values();
        $this->assertSame(['20260802-M01-001-C01', '20260802-M01-001-C02'], $cartons->pluck('carton_no')->all());
        foreach ($cartons as $carton) {
            $this->assertSame('600.0000', (string) $carton['pieces']);
            $this->assertSame($batch->id, $carton['shift_production_entry_id']);
            $this->assertSame('20260802-M01-001', $carton['batch_no']);
        }
        $this->assertTallyLink($scannedRow['tally'], $dnScanned, 'pending');
        $this->assertUnvalidatedBuilderFlag($scannedRow['tally']);

        // 4b. The typed delivery: no cartons (nothing was scanned), its own
        //     line, its own pending Delivery Note.
        $typedRow = $deliveries[$typed];
        $this->assertSame("DN-{$typed}", $typedRow['document_number']);
        $this->assertSame('LR-1002', $typedRow['reference']);
        $this->assertSame([], $typedRow['cartons'], 'A typed delivery has no cartons to show');
        $this->assertSame('600.0000', (string) $typedRow['lines'][0]['quantity']);
        $this->assertTallyLink($typedRow['tally'], $dnTyped, 'pending');
        $this->assertUnvalidatedBuilderFlag($typedRow['tally']);

        // 4c. The invoice: issued, its selling line, and a pending Sales link
        //     whose flag names DEC-20260809-003 — the drawer's warning that
        //     Tally, not this ERP, is the sales system of record.
        $invoiceRow = $trace['invoices'][0];
        $this->assertSame($invoiceId, $invoiceRow['id']);
        $this->assertSame("INV-{$invoiceId}", $invoiceRow['document_number']);
        $this->assertSame('issued', $invoiceRow['status']);
        $this->assertSame('2026-08-12', $invoiceRow['invoice_date']);
        $this->assertSame('500ml PET Bottle', $invoiceRow['lines'][0]['item']['name']);
        $this->assertSame('1800.0000', (string) $invoiceRow['lines'][0]['quantity']);
        $this->assertSame('4.5000', (string) $invoiceRow['lines'][0]['unit_price']);
        $this->assertTallyLink($invoiceRow['tally'], $sales, 'pending');
        $this->assertUnvalidatedBuilderFlag($invoiceRow['tally'], 'DEC-20260809-003');

        // 5. The REAL agent: polls (all three handed out), acks the scanned
        //    Delivery Note, and reports Tally's rejection of the typed one.
        $offered = collect($this->asAgent()->getJson('/api/v1/tally-sync/pending')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($offered->contains($dnScanned->id) && $offered->contains($dnTyped->id) && $offered->contains($sales->id));
        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$dnScanned->id}/ack")->assertOk()->assertJsonPath('data.status', 'synced');
        $this->asAgent()->postJson("/api/v1/tally-sync/entries/{$dnTyped->id}/fail", ['error_message' => "Godown 'FG Store' does not exist!"])
            ->assertOk()->assertJsonPath('data.status', 'failed');
        $this->assertSame(TallySyncStatus::Synced, $dnScanned->fresh()->status);
        $this->assertSame(TallySyncStatus::Failed, $dnTyped->fresh()->status);

        // 6. The trace reflects the agent's report — and only its status.
        $this->asUser($this->salesDesk);
        $after = collect($this->traceOf($this->showOrder($order->id))['deliveries'])->keyBy('id');
        $this->assertTallyLink($after[$scanned]['tally'], $dnScanned->fresh(), 'synced');
        $this->assertNotNull($after[$scanned]['tally']['synced_at'], 'A synced link carries when');
        $this->assertTallyLink($after[$typed]['tally'], $dnTyped->fresh(), 'failed');
        $this->assertNull($after[$typed]['tally']['synced_at']);
        // The rejection text stays on the entry, behind the Control Center's
        // gates — the link says "failed" and points there, nothing more.
        $this->assertStringNotContainsString('does not exist', json_encode($after[$typed]));
        $invoiceAfter = $this->traceOf($this->showOrder($order->id))['invoices'][0];
        $this->assertTallyLink($invoiceAfter['tally'], $sales->fresh(), 'pending');

        // 7. The same truth from the other two doors.
        $deliveryShow = $this->getJson("/api/v1/sales/deliveries/{$scanned}")->assertOk();
        $deliveryShow->assertJsonPath('data.document_number', "DN-{$scanned}")
            ->assertJsonPath('data.sales_order.id', $order->id)
            ->assertJsonPath('data.sales_order.document_number', "SO-{$order->id}")
            ->assertJsonPath('data.sales_order.status', 'partially_delivered')
            ->assertJsonPath('data.customer.id', $this->customer->id)
            ->assertJsonPath('data.customer.code', 'CUST-1')
            ->assertJsonPath('data.customer.name', 'Aqua Traders')
            ->assertJsonPath('data.carton_count', 2)
            ->assertJsonPath('data.tally.status', 'synced');
        $deliveryTrace = $this->traceOf($deliveryShow);
        $this->assertSame($order->id, $deliveryTrace['sales_order']['id']);
        $this->assertSame("SO-{$order->id}", $deliveryTrace['sales_order']['document_number']);
        $this->assertSame('partially_delivered', $deliveryTrace['sales_order']['status']);
        $this->assertSame('Aqua Traders', $deliveryTrace['sales_order']['customer']['name']);
        $this->assertSame(
            ['20260802-M01-001-C01', '20260802-M01-001-C02'],
            collect($deliveryTrace['cartons'])->pluck('carton_no')->sort()->values()->all(),
        );
        $this->assertSame('20260802-M01-001', $deliveryTrace['cartons'][0]['batch_no']);
        $this->assertTallyLink($deliveryTrace['tally'], $dnScanned->fresh(), 'synced');

        $invoiceShow = $this->getJson("/api/v1/sales/invoices/{$invoiceId}")->assertOk();
        $invoiceShow->assertJsonPath('data.document_number', "INV-{$invoiceId}")
            ->assertJsonPath('data.status', 'issued')
            ->assertJsonPath('data.sales_order.id', $order->id)
            ->assertJsonPath('data.sales_order.document_number', "SO-{$order->id}")
            ->assertJsonPath('data.tally.status', 'pending');
        $invoiceTrace = $this->traceOf($invoiceShow);
        $this->assertSame("SO-{$order->id}", $invoiceTrace['sales_order']['document_number']);
        $this->assertTallyLink($invoiceTrace['tally'], $sales->fresh(), 'pending');
        $this->assertUnvalidatedBuilderFlag($invoiceTrace['tally'], 'DEC-20260809-003');

        // 8. Nothing on any of the three pages names a purchase rate or a
        //    supplier (FC-06) — a sales trace carries customers and selling
        //    prices only. And nothing was written by reading: still exactly
        //    three vouchers.
        foreach ([$shown, $deliveryShow, $invoiceShow] as $page) {
            $this->assertStringNotContainsString('unit_cost', $page->getContent());
            $this->assertStringNotContainsString('vendor', $page->getContent());
        }
        $this->assertSame(3, TallySyncEntry::query()->count(), 'Reading a trace queues nothing');
    }

    // ---- gates ---------------------------------------------------------------

    public function test_the_trace_is_readable_with_sales_view_alone_and_refused_without_it(): void
    {
        $order = $this->confirmedOrder('600');
        $delivery = $this->dispatchTyped($order, '600');
        $invoice = $this->draftInvoice($order, '600');

        // sales.view only: every show is readable, trace included.
        $this->asUser($this->userWith('Vasanth Viewer', ['sales.view']));
        $this->assertArrayHasKey('deliveries', $this->traceOf($this->showOrder($order->id)));
        $this->assertArrayHasKey('sales_order', $this->traceOf($this->getJson("/api/v1/sales/deliveries/{$delivery}")->assertOk()));
        $this->assertArrayHasKey('sales_order', $this->traceOf($this->getJson("/api/v1/sales/invoices/{$invoice}")->assertOk()));

        // A logged-in person with no sales permission: refused on all three.
        $this->asUser($this->userWith('Someone Else', ['production.view']));
        $this->getJson("/api/v1/sales/sales-orders/{$order->id}")->assertForbidden();
        $this->getJson("/api/v1/sales/deliveries/{$delivery}")->assertForbidden();
        $this->getJson("/api/v1/sales/invoices/{$invoice}")->assertForbidden();

        // A missing id is a 404, not an empty trace.
        $this->asUser($this->salesDesk);
        $this->getJson('/api/v1/sales/sales-orders/999999')->assertNotFound();
        $this->getJson('/api/v1/sales/deliveries/999999')->assertNotFound();
        $this->getJson('/api/v1/sales/invoices/999999')->assertNotFound();
    }
}
