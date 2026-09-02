<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Procurement\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** DEC-20260902-026: one or more of five classifications, set by a person; a filter, never a block. */
class VendorClassificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create(['is_active' => true]);
        foreach (['procurement.view', 'procurement.manage'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        Sanctum::actingAs($user);
    }

    public function test_a_vendor_takes_one_or_more_classifications(): void
    {
        $id = $this->postJson('/api/v1/procurement/vendors', ['name' => 'Relpet Traders', 'classifications' => ['resin', 'packaging']])
            ->assertCreated()->assertJsonPath('data.classifications', ['packaging', 'resin'])->json('data.id');

        $this->putJson("/api/v1/procurement/vendors/{$id}", ['name' => 'Relpet Traders', 'classifications' => ['service']])
            ->assertOk()->assertJsonPath('data.classifications', ['service']);
    }

    public function test_an_unknown_classification_is_refused(): void
    {
        $this->postJson('/api/v1/procurement/vendors', ['name' => 'X', 'classifications' => ['tooling']])
            ->assertStatus(422)->assertJsonValidationErrors(['classifications.0']);
    }

    public function test_an_update_that_omits_classifications_leaves_them_untouched(): void
    {
        $id = $this->postJson('/api/v1/procurement/vendors', ['name' => 'Relpet Traders', 'classifications' => ['resin']])
            ->json('data.id');

        $this->putJson("/api/v1/procurement/vendors/{$id}", ['name' => 'Relpet Traders Ltd'])
            ->assertOk()->assertJsonPath('data.classifications', ['resin']);
    }

    public function test_an_update_with_an_empty_array_clears_classifications(): void
    {
        $id = $this->postJson('/api/v1/procurement/vendors', ['name' => 'Relpet Traders', 'classifications' => ['resin']])
            ->json('data.id');

        $this->putJson("/api/v1/procurement/vendors/{$id}", ['name' => 'Relpet Traders', 'classifications' => []])
            ->assertOk()->assertJsonPath('data.classifications', []);
    }

    /**
     * A vendor's classifications are not a lifecycle fact — archiving one
     * must not make the Vendors screen show it as suddenly unclassified.
     */
    public function test_archiving_a_classified_vendor_still_carries_its_classifications(): void
    {
        $id = $this->postJson('/api/v1/procurement/vendors', ['name' => 'Relpet Traders', 'classifications' => ['resin']])
            ->json('data.id');

        $this->postJson("/api/v1/procurement/vendors/{$id}/archive", ['reason' => 'Stopped supplying'])
            ->assertOk()->assertJsonPath('data.classifications', ['resin']);
    }

    /** The twin of the archive case above — reactivating loses nothing either. */
    public function test_activating_a_classified_vendor_still_carries_its_classifications(): void
    {
        $id = $this->postJson('/api/v1/procurement/vendors', ['name' => 'Relpet Traders', 'classifications' => ['resin']])
            ->json('data.id');
        $this->postJson("/api/v1/procurement/vendors/{$id}/archive", ['reason' => 'Stopped supplying']);

        $this->postJson("/api/v1/procurement/vendors/{$id}/activate", ['reason' => 'Supplying again'])
            ->assertOk()->assertJsonPath('data.classifications', ['resin']);
    }

    public function test_the_list_filters_by_classification_and_by_unclassified(): void
    {
        $resin = Vendor::create(['code' => 'V-R', 'name' => 'Resin Co']);
        $resin->classifications()->create(['classification' => 'resin']);
        $service = Vendor::create(['code' => 'V-S', 'name' => 'Service Co']);
        $service->classifications()->create(['classification' => 'service']);
        Vendor::create(['code' => 'V-U', 'name' => 'Unclassified Co']);

        $this->getJson('/api/v1/procurement/vendors?classification[]=resin&classification[]=packaging')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'V-R');
        $this->getJson('/api/v1/procurement/vendors?unclassified=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'V-U');
        $this->getJson('/api/v1/procurement/vendors')->assertOk()->assertJsonCount(3, 'data');

        // classification[] AND unclassified=1 together widen rather than
        // narrow: resin-holders plus the unclassified, service excluded.
        $this->getJson('/api/v1/procurement/vendors?classification[]=resin&unclassified=1')
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', 'V-R')->assertJsonPath('data.1.code', 'V-U');
    }
}
