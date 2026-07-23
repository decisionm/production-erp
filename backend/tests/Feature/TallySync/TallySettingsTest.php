<?php

namespace Tests\Feature\TallySync;

use App\Models\User;
use App\Modules\TallySync\Models\Ledger;
use App\Modules\TallySync\Models\TallyLedgerMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TallySettingsTest extends TestCase
{
    use RefreshDatabase;

    private function actAsStaff(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('tally-sync.manage', 'web');
        $user->givePermissionTo('tally-sync.manage');
        Sanctum::actingAs($user);
    }

    public function test_it_shows_settings_with_all_roles_and_ledger_picklist(): void
    {
        $this->actAsStaff();
        Ledger::create(['tally_guid' => 'l1', 'name' => 'Sales - Bottles']);
        Ledger::create(['tally_guid' => 'l2', 'name' => 'Output CGST']);

        $response = $this->getJson('/api/v1/tally-sync/settings');

        $response->assertOk()
            ->assertJsonPath('data.company', null)
            ->assertJsonPath('data.companies', [])
            ->assertJsonPath('data.mappings.sales', null)
            ->assertJsonPath('data.mappings.cgst', null);

        $this->assertContains('Sales - Bottles', $response->json('data.ledgers'));
        $this->assertContains(['value' => 'sales', 'label' => 'Sales'], $response->json('data.roles'));
    }

    public function test_it_updates_the_selected_company(): void
    {
        $this->actAsStaff();

        $this->putJson('/api/v1/tally-sync/settings/company', ['company' => 'Acme Bottles Pvt Ltd'])
            ->assertOk()
            ->assertJsonPath('data.company', 'Acme Bottles Pvt Ltd');

        $this->getJson('/api/v1/tally-sync/settings')
            ->assertJsonPath('data.company', 'Acme Bottles Pvt Ltd');
    }

    public function test_it_saves_ledger_role_mappings(): void
    {
        $this->actAsStaff();

        $this->putJson('/api/v1/tally-sync/settings/ledger-mappings', [
            'mappings' => ['sales' => 'Sales - Bottles', 'cgst' => 'Output CGST', 'igst' => ''],
        ])->assertOk()
            ->assertJsonPath('data.mappings.sales', 'Sales - Bottles')
            ->assertJsonPath('data.mappings.cgst', 'Output CGST')
            ->assertJsonPath('data.mappings.igst', null); // blank clears

        $this->assertSame('Sales - Bottles', TallyLedgerMapping::where('role', 'sales')->value('tally_ledger_name'));
        // Unknown roles are ignored, not persisted.
        $this->putJson('/api/v1/tally-sync/settings/ledger-mappings', ['mappings' => ['bogus' => 'X']])->assertOk();
        $this->assertDatabaseMissing('tally_ledger_mappings', ['role' => 'bogus']);
    }

    public function test_it_requires_permission_for_staff_settings(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_active' => true])); // no tally-sync.manage

        $this->getJson('/api/v1/tally-sync/settings')->assertForbidden();
    }

    public function test_agent_reports_companies_which_show_in_settings(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_active' => true]), ['tally-sync:masters']);

        $this->postJson('/api/v1/tally-sync/companies', [
            'companies' => ['Acme Bottles Pvt Ltd', 'Acme Bottles Pvt Ltd', 'Test Co'],
        ])->assertOk()->assertJsonPath('data.companies', ['Acme Bottles Pvt Ltd', 'Test Co']); // de-duped

        // A staff user then sees them for selection.
        $this->actAsStaff();
        $this->getJson('/api/v1/tally-sync/settings')
            ->assertJsonPath('data.companies', ['Acme Bottles Pvt Ltd', 'Test Co']);
    }
}
