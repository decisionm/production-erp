<?php

namespace Tests\Feature\Configuration;

use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Procurement\Services\VendorService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * THE VENDOR MASTER under the Configuration Lifecycle Contract
 * (DEC-20260817-002) — Create · View · Edit · Activate/Deactivate ·
 * Safe Delete · Audit.
 *
 * Vendor and Customer were the last two masters left off the contract. Both
 * carried `is_active` on the table with nothing behind it: the only way to
 * flip it was a plain `update` sending the whole record, so a vendor with
 * live purchase orders could be switched off with no reason captured, no
 * audit entry naming the change, and no dependency guard consulted.
 *
 * Both of a vendor's inbound keys are RESTRICT, so the database would refuse
 * a hard delete anyway. The declarations still earn their place: they turn a
 * foreign-key error into a refusal that COUNTS the rows and names them in
 * business words, which is what the confirm dialog shows a person.
 */
class VendorLifecycleTest extends ProductDefinitionLifecycleTestCase
{
    use RefreshDatabase;

    private const MODULE = ['procurement.view', 'procurement.manage'];

    private function vendor(string $code = 'V-1', array $overrides = []): Vendor
    {
        return Vendor::create([
            'code' => $code,
            'name' => 'Vendor '.$code,
            ...$overrides,
        ]);
    }

    private function service(): VendorService
    {
        return app(VendorService::class);
    }

    public function test_archive_takes_a_vendor_out_of_service_and_activate_puts_it_back(): void
    {
        $manager = $this->moduleUser(...self::MODULE);
        $vendor = $this->vendor('V-CYCLE');

        $this->actingAs($manager)
            ->postJson("/api/v1/procurement/vendors/{$vendor->id}/archive", ['reason' => 'Stopped supplying'])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($manager)
            ->postJson("/api/v1/procurement/vendors/{$vendor->id}/activate", ['reason' => 'Supplying again'])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_can_delete_is_undetermined_on_index_and_answered_on_show(): void
    {
        $owner = $this->ownerUser(...self::MODULE);
        $vendor = $this->vendor('V-CAN');

        // `delete` carries THREE answers, and the difference matters. For a
        // user who could delete, index declines to answer — resolving it
        // costs a COUNT per dependency per row, so null means "undetermined,
        // ask show()", never "no".
        $this->actingAs($owner)
            ->getJson('/api/v1/procurement/vendors')
            ->assertOk()
            ->assertJsonPath('data.0.can.delete', null);

        // show is authoritative: unreferenced, and this user holds the tier.
        $this->actingAs($owner)
            ->getJson("/api/v1/procurement/vendors/{$vendor->id}")
            ->assertOk()
            ->assertJsonPath('data.can.delete', true);
    }

    public function test_no_hard_delete_tier_is_a_decision_on_index_not_an_unknown(): void
    {
        $manager = $this->moduleUser(...self::MODULE);
        $this->vendor('V-NOTIER');

        // The third answer. For a user below the tier the reply is false even
        // on the cheap block, because counting could not change it — telling
        // this user "undetermined" would send the confirm dialog off to fetch
        // an answer that was already settled by permission alone.
        $this->actingAs($manager)
            ->getJson('/api/v1/procurement/vendors')
            ->assertOk()
            ->assertJsonPath('data.0.can.delete', false);
    }

    public function test_the_hard_delete_is_refused_for_a_module_user_without_the_owner_tier(): void
    {
        $manager = $this->moduleUser(...self::MODULE);
        $vendor = $this->vendor('V-TIER');

        $this->actingAs($manager)
            ->deleteJson("/api/v1/procurement/vendors/{$vendor->id}")
            ->assertStatus(403);

        $this->assertNotNull($vendor->fresh(), 'the vendor survives a refused delete');
    }

    public function test_a_vendor_with_a_purchase_order_is_refused_with_counts(): void
    {
        $owner = $this->ownerUser(...self::MODULE);
        $vendor = $this->vendor('V-USED');

        PurchaseOrder::create([
            'vendor_id' => $vendor->id,
            'order_date' => '2026-08-20',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($owner)->deleteJson("/api/v1/procurement/vendors/{$vendor->id}");

        $response->assertStatus(422);
        $this->assertSame(
            ['purchase_orders'],
            collect($response->json('blocking'))->pluck('code')->all(),
        );
        $this->assertSame(1, $response->json('blocking.0.count'));
        $this->assertNotNull($vendor->fresh(), 'the vendor survives a refused delete');
    }

    public function test_an_unused_vendor_is_really_deleted_and_the_code_is_freed(): void
    {
        $owner = $this->ownerUser(...self::MODULE);
        $vendor = $this->vendor('V-FREE');

        $this->actingAs($owner)
            ->deleteJson("/api/v1/procurement/vendors/{$vendor->id}")
            ->assertStatus(204);

        // DEC-20260817-002 rule 2: a genuinely unused record that is hard
        // deleted RELEASES its code, because nothing in history referred to
        // it. An archived one would still be holding V-FREE.
        $this->assertNull(Vendor::withTrashed()->find($vendor->id));
        $this->vendor('V-FREE');
        $this->assertSame(1, Vendor::where('code', 'V-FREE')->count());
    }

    public function test_an_archived_vendor_keeps_its_code_reserved(): void
    {
        $manager = $this->moduleUser(...self::MODULE);
        $vendor = $this->vendor('V-RESERVED');

        $this->actingAs($manager)
            ->postJson("/api/v1/procurement/vendors/{$vendor->id}/archive", ['reason' => 'Dormant'])
            ->assertOk();

        // The other half of rule 2 — a retired code stays taken, so history
        // can never be read as pointing at a different vendor.
        $this->actingAs($manager)
            ->postJson('/api/v1/procurement/vendors', ['code' => 'V-RESERVED', 'name' => 'Impostor'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }
}
