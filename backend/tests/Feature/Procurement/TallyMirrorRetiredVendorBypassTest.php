<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Item;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\Vendor;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PINS TODAY'S BEHAVIOUR SO THAT CHANGING IT IS DELIBERATE.
 *
 * WS-B (audit 17-Aug-2026 §1) refuses a RETIRED vendor on a new purchase
 * order — but only when the order is ERP-entered. `StorePurchaseOrderRequest`
 * reads `source` from the CLIENT'S REQUEST BODY and nothing verifies that a
 * matching order exists in Tally, so sending `source: tally` opts the caller
 * out of the retired-vendor rule entirely — no extra grant needed beyond the
 * ordinary permission to raise a purchase order, which this test holds.
 *
 * That is an owner question, recorded as PENDING-OWNER-QUESTIONS Q53(c) and
 * UNANSWERED: should a retired vendor also block a Tally MIRROR, or is
 * mirroring what Tally already holds always permitted? Until the factory
 * answers, the rule is left exactly as it is — and pinned here, so an answer
 * turns this file red instead of arriving as an unnoticed drift.
 *
 * ActiveSelectionTest already proves the mirror ACCEPTS a retired vendor.
 * What this file adds is the bypass itself: the SAME retired vendor and the
 * SAME lines, refused one way and created the other, with nothing separating
 * the two calls but a field the client chose.
 *
 * Both halves are asserted in one method on purpose. Asserting the refusal
 * as a `vendor_id` VALIDATION error (not merely "no order was created")
 * is what stops a 401/403/404 from reading as either result.
 */
class TallyMirrorRetiredVendorBypassTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $retiredVendor;

    private Item $resin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $admin = User::factory()->create(['name' => 'Procurement Admin', 'is_active' => true]);
        $admin->assignRole('Administrator');
        Sanctum::actingAs($admin);

        $this->retiredVendor = Vendor::create([
            'code' => 'V-RET',
            'name' => 'Retired In The Erp',
            'is_active' => false,
        ]);
        $this->resin = Item::create(['sku' => 'RM-1', 'name' => 'Resin', 'uom' => 'Kgs', 'category' => ItemCategory::RawMaterial]);
    }

    /** The identical order, differing only in the client-supplied `source`. */
    private function payload(?string $source): array
    {
        $payload = [
            'vendor_id' => $this->retiredVendor->id,
            'order_date' => '2026-08-17',
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '10', 'unit_price' => '1.00']],
        ];

        if ($source !== null) {
            $payload['source'] = $source;
            $payload['tally_order_no'] = 'MIRROR-0001';
        }

        return $payload;
    }

    public function test_a_client_supplied_source_tally_bypasses_the_retired_vendor_rule(): void
    {
        // 1. ERP-entered: the WS-B rule bites, on `vendor_id` specifically.
        $this->postJson('/api/v1/procurement/purchase-orders', $this->payload(null))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vendor_id']);

        // 2. The SAME payload plus `source: tally` is accepted. Nothing was
        //    checked against Tally — the caller simply said so in the body.
        $this->postJson('/api/v1/procurement/purchase-orders', $this->payload('tally'))
            ->assertCreated();

        // 3. And the order really exists, against the retired vendor.
        $order = PurchaseOrder::query()
            ->where('vendor_id', $this->retiredVendor->id)
            ->firstOrFail();

        $this->assertSame(
            'tally',
            $order->source,
            'PENDING-OWNER-QUESTIONS Q53(c): a purchase order for a RETIRED vendor exists '
            .'because the request body claimed source=tally. If Q53(c) has been answered '
            .'"a retired vendor blocks the mirror too", this test must be rewritten, not deleted.',
        );
        $this->assertFalse(
            $this->retiredVendor->fresh()->is_active,
            'the vendor must still be retired, or the bypass above proved nothing',
        );
    }

    public function test_source_erp_is_explicit_and_is_still_refused(): void
    {
        // The bypass is the `tally` value, not the presence of the field:
        // spelling `erp` out changes nothing.
        $payload = $this->payload(null);
        $payload['source'] = 'erp';

        $this->postJson('/api/v1/procurement/purchase-orders', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vendor_id']);
    }

    public function test_the_rule_that_is_bypassed_really_is_the_active_flag(): void
    {
        // Non-vacuity for the refusals above: the identical ERP-source
        // payload passes the moment the vendor is active again, so it is the
        // retired flag doing the refusing and not some other rule.
        $this->retiredVendor->update(['is_active' => true]);

        $this->postJson('/api/v1/procurement/purchase-orders', $this->payload(null))
            ->assertCreated();
    }
}
