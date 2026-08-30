<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\TallySync\Models\Enums\TallyLedgerRole;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Models\TallySyncEvent;
use App\Modules\TallySync\Services\TallyLedgerMappingService;
use App\Modules\TallySync\Services\TallySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Support\SeedsSalesTallyMasterData;
use Tests\TestCase;

/**
 * THE HONESTY ENDPOINT — GET /sales/tally-mirror (Phase 3.5).
 *
 * "ALL real sales are invoiced directly in Tally — the ERP Sales module is
 * demo-scale" (DEC-20260809-003). The Sales pages therefore show the
 * ERP-originated subset only, and rather than let an empty or short table
 * pass for the truth, the page asks the server what it is looking at. The
 * server's answer is a fixed set of facts, and it must stay fixed no
 * matter what ERP-side data exists:
 *
 *   mirrored: false            — Tally-side Sales / Sales Order vouchers are NOT here
 *   decision: DEC-20260809-003 — the owner decision behind that
 *   erp_invoice_builder.validated: false — the Sales voucher XML is unvalidated, no GST
 *   payments_recorded_here: false — an invoice is never marked paid by this ERP
 *
 * The frontend renders the server's sentences (never its own), so the
 * strings are asserted here as the contract; the two keys the pages branch
 * on — `mirrored` and `decision` — are pinned exactly.
 *
 * It is a READ behind sales.view: it reads nothing from Tally (there is no
 * read path — agent v0.3.3 removed reads), it writes nothing (watched
 * statement by statement), and it is refused without the permission.
 */
class TallyMirrorHonestyTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSalesTallyMasterData;

    private const URL = '/api/v1/sales/tally-mirror';

    protected function setUp(): void
    {
        parent::setUp();

        // The Sales voucher is this file's FIXTURE, not its subject: one test
        // issues an invoice only to have a queued voucher the answer must
        // survive. SalesVoucherPayload now refuses — and stages nothing —
        // without the GST registration, the ledger roles and a single
        // Tally-linked godown, so seed them here. In setUp they also land well
        // before the statement-by-statement write watch below, which must see
        // the endpoint's own statements and no one else's.
        $this->seedSalesTallyMasterData();
    }

    /** @param  list<string>  $permissions */
    private function actingWith(array $permissions, string $name = 'Someone'): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * The body as the contract draws it — a flat object. A resource-wrapped
     * `data` envelope is unwrapped so the FACTS are what is judged.
     */
    private function body(TestResponse $response): array
    {
        $json = $response->assertOk()->json();

        return is_array($json['data'] ?? null) && array_key_exists('mirrored', $json['data']) ? $json['data'] : $json;
    }

    private function assertHonest(array $body): void
    {
        // The two keys the pages branch on, pinned exactly.
        $this->assertArrayHasKey('mirrored', $body);
        $this->assertFalse($body['mirrored'], 'mirrored must be boolean false — not null, not "false", not missing');
        $this->assertSame('DEC-20260809-003', $body['decision']);

        // The sentences the panel renders — the server's words, per contract.
        $this->assertSame('Real sales are invoiced in Tally', $body['headline']);
        $this->assertSame(
            'Tally-side Sales and Sales Order vouchers are not mirrored into this ERP. The documents on these pages are the '
            .'ERP-originated subset only. Reads from Tally are deliberate and human-triggered; none is scheduled.',
            $body['body'],
        );

        // The builder's own status: unvalidated, no GST, do not post real invoices.
        $this->assertFalse($body['erp_invoice_builder']['validated']);
        $this->assertSame(
            'The ERP\'s Sales voucher XML is not yet validated against real Tally and carries no GST — do not post real '
            .'invoices from here while DEC-20260809-003 stands.',
            $body['erp_invoice_builder']['note'],
        );

        // Receipts live in Tally; InvoiceStatus::Paid is deliberately unwired.
        $this->assertFalse($body['payments_recorded_here']);
        $this->assertSame('An invoice is never marked paid by this ERP — receipts live in Tally.', $body['payments_note']);
    }

    // ---- the facts ------------------------------------------------------------

    public function test_it_says_plainly_that_tally_sales_are_not_mirrored_and_names_the_decision(): void
    {
        $this->actingWith(['sales.view'], 'Vasanth Viewer');

        $this->assertHonest($this->body($this->getJson(self::URL)));
    }

    public function test_the_answer_does_not_change_when_erp_originated_sales_documents_exist(): void
    {
        // An issued ERP invoice with its Sales voucher queued — the very thing
        // a reader might mistake for "sales are here". mirrored stays false:
        // the ERP-originated subset is not the Tally sales ledger.
        $this->actingWith(['sales.view', 'sales.manage'], 'Sales Desk');
        app(TallyLedgerMappingService::class)->setMany([TallyLedgerRole::Sales->value => 'Sales - Local']);
        $bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos', 'tally_stock_item_guid' => 'itm-bottle']);
        $customer = Customer::create(['code' => 'CUST-1', 'name' => 'Aqua Traders', 'gstin' => '33AAACA1111A1Z5']);
        $order = SalesOrder::create(['customer_id' => $customer->id, 'status' => SalesOrderStatus::Confirmed, 'order_date' => '2026-08-09']);
        $line = $order->lines()->create(['item_id' => $bottle->id, 'quantity' => '2000', 'unit_price' => '4.50', 'quantity_delivered' => 0]);
        // The item and the customer are minted HERE, after setUp's seeding ran,
        // so complete them — an HSN with a rate behind it, the customer's Tally
        // ledger name and state — before the voucher is assembled. Neither the
        // GSTIN nor anything else set above is overwritten.
        $this->completeSalesTallyMastersOnExistingRows();
        $invoiceId = $this->postJson('/api/v1/sales/invoices', [
            'sales_order_id' => $order->id,
            'invoice_date' => '2026-08-10',
            'lines' => [['sales_order_line_id' => $line->id, 'quantity' => '2000', 'unit_price' => '4.50']],
        ])->assertSuccessful()->json('data.id');
        $this->postJson("/api/v1/sales/invoices/{$invoiceId}/issue")->assertSuccessful()->assertJsonPath('data.status', 'issued');
        $entry = TallySyncEntry::query()->sole();

        $this->assertHonest($this->body($this->getJson(self::URL)));

        // Even once the agent has reported that voucher synced: an ERP
        // invoice in Tally is still not Tally's sales mirrored here.
        app(TallySyncService::class)->markSynced($entry);
        $this->assertHonest($this->body($this->getJson(self::URL)));
    }

    public function test_it_is_a_pure_read_that_writes_nothing_and_touches_no_queue(): void
    {
        $this->actingWith(['sales.view'], 'Vasanth Viewer');

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $this->assertHonest($this->body($this->getJson(self::URL)));

        // Statement by statement: no insert, update or delete — and, the
        // easier check, nothing queued for Tally and no sync event recorded.
        $this->assertSame([], $writes, 'The honesty endpoint fired a write statement.');
        $this->assertSame(0, TallySyncEntry::query()->count());
        $this->assertSame(0, TallySyncEvent::query()->count());
    }

    // ---- the gate -------------------------------------------------------------

    public function test_it_is_refused_without_sales_view(): void
    {
        // Nobody logged in: 401 (the SPA owns /login).
        $this->getJson(self::URL)->assertUnauthorized();

        // Logged in, no sales permission at all.
        $this->actingWith([], 'Someone Else');
        $this->getJson(self::URL)->assertForbidden();

        // A permission from another module is not sales.view.
        $this->actingWith(['production.view', 'tally-sync.view'], 'Ravi Production');
        $this->getJson(self::URL)->assertForbidden();

        // sales.manage reads too (module:sales lets Manage read) — and
        // sales.view alone is exactly enough.
        $this->actingWith(['sales.manage'], 'Sales Desk');
        $this->assertHonest($this->body($this->getJson(self::URL)));

        $this->actingWith(['sales.view'], 'Vasanth Viewer');
        $this->assertHonest($this->body($this->getJson(self::URL)));
    }
}
