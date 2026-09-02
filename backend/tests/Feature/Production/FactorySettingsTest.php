<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Production\Models\FactorySetting;
use App\Modules\Production\Services\RunMaterialSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The Factory Rules screen tells the truth about what a setting does.
 *
 * Ten of the rows on it are the workbook's System Config sheet loaded as
 * data; no code reads them. A switch that changes nothing must be labelled
 * as such, so the resource says which keys are read — and this test pins
 * that the registry and the one real reader agree.
 */
class FactorySettingsTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<int, string> $permissions */
    private function actAs(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);
        Sanctum::actingAs($user);

        return $user;
    }

    private function seedRows(): void
    {
        FactorySetting::create([
            'key' => RunMaterialSuggestionService::COLOUR_MAP_KEY,
            'value' => '{"Amber": 121}',
            'data_type' => 'json',
            'scope' => 'production',
            'label' => 'Masterbatch by colour',
        ]);
        FactorySetting::create([
            'key' => 'REQUIRE_OVERRIDE_REASON',
            'value' => 'true',
            'data_type' => 'boolean',
            'scope' => 'production',
            'label' => 'Require a reason for every override',
            'confirmation_status' => 'Recommended',
        ]);
    }

    public function test_the_colour_map_is_the_only_key_the_software_reads(): void
    {
        $this->assertSame([RunMaterialSuggestionService::COLOUR_MAP_KEY], FactorySetting::READ_BY_SOFTWARE);
    }

    public function test_the_index_says_which_rows_are_read_and_which_are_reference_only(): void
    {
        $this->actAs(['production.view']);
        $this->seedRows();

        $rows = collect($this->getJson('/api/v1/production/factory-settings')->assertOk()->json('data'))
            ->keyBy('key');

        $this->assertTrue($rows[RunMaterialSuggestionService::COLOUR_MAP_KEY]['applied']);
        $this->assertFalse($rows['REQUIRE_OVERRIDE_REASON']['applied']);
    }

    public function test_a_save_records_who_changed_it_and_why_and_answers_with_both(): void
    {
        $user = $this->actAs(['production.view', 'production.manage']);
        $this->seedRows();

        $response = $this->postJson('/api/v1/production/factory-settings', [
            'key' => 'REQUIRE_OVERRIDE_REASON',
            'value' => 'false',
            'change_reason' => 'Trial for shift C',
        ])->assertOk();

        $response->assertJsonPath('data.value', 'false')
            ->assertJsonPath('data.typed_value', false)
            ->assertJsonPath('data.change_reason', 'Trial for shift C')
            ->assertJsonPath('data.changed_by', $user->name)
            ->assertJsonPath('data.applied', false);

        $this->assertSame($user->id, FactorySetting::query()->where('key', 'REQUIRE_OVERRIDE_REASON')->value('changed_by'));
    }

    public function test_a_reader_without_manage_cannot_save(): void
    {
        $this->actAs(['production.view']);
        $this->seedRows();

        $this->postJson('/api/v1/production/factory-settings', [
            'key' => 'REQUIRE_OVERRIDE_REASON',
            'value' => 'false',
        ])->assertForbidden();
    }
}
