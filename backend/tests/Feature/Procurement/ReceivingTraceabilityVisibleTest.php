<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE RECEIVING SCREEN GETS THE SAME ANSWER WHOEVER IS LOOKING AT IT.
 *
 * Whether this deployment captures lots and bags is `production.traceability_
 * enabled`, and the receiving form used to read it from /production/settings —
 * a route behind the production module. A storekeeper holding procurement and
 * NOT production therefore got a 403 there, which the hook turns into null, and
 * the page read null as "traceability off".
 *
 * The consequence was not cosmetic. That login's receipts were booked with no
 * lots, so no material bags were created, so nothing entered `waiting_qc` and
 * the incoming-QC hold never applied: material reached available stock without
 * passing quality, silently, and only for some logins. The same screen, the
 * same deployment, a different answer depending on the reader's permissions.
 *
 * The flag is deployment config and names nothing about the reader, so serving
 * it on the procurement endpoint that screen already calls reveals nothing and
 * removes the divergence. It is a top-level key rather than part of `meta`,
 * which belongs to the paginator — overwriting that would take the register's
 * page count with it, which is a defect this same page was just fixed for.
 */
class ReceivingTraceabilityVisibleTest extends TestCase
{
    use RefreshDatabase;

    private function user(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    public function test_a_procurement_only_login_is_told_whether_lots_are_captured(): void
    {
        config(['production.traceability_enabled' => true]);

        $this->actingAs($this->user('procurement.view'))
            ->getJson('/api/v1/procurement/goods-receipts')
            ->assertOk()
            ->assertJsonPath('traceability_enabled', true);
    }

    public function test_it_reports_the_flag_off_when_the_deployment_has_it_off(): void
    {
        config(['production.traceability_enabled' => false]);

        $this->actingAs($this->user('procurement.view'))
            ->getJson('/api/v1/procurement/goods-receipts')
            ->assertOk()
            ->assertJsonPath('traceability_enabled', false);
    }

    /**
     * The answer must not depend on who is asking — that divergence IS the
     * defect. A login holding production as well gets the same value.
     */
    public function test_the_answer_does_not_depend_on_holding_the_production_module(): void
    {
        config(['production.traceability_enabled' => true]);

        $procurementOnly = $this->actingAs($this->user('procurement.view'))
            ->getJson('/api/v1/procurement/goods-receipts')->json('traceability_enabled');

        $alsoProduction = $this->actingAs($this->user('procurement.view', 'production.view'))
            ->getJson('/api/v1/procurement/goods-receipts')->json('traceability_enabled');

        $this->assertSame($procurementOnly, $alsoProduction);
    }

    /** The paginator's own meta must survive — the register pages on it. */
    public function test_the_pagination_meta_is_untouched(): void
    {
        $this->actingAs($this->user('procurement.view'))
            ->getJson('/api/v1/procurement/goods-receipts')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'current_page', 'per_page']]);
    }
}
