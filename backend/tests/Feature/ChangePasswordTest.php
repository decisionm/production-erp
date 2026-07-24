<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_change_their_own_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Swaash@2026'), 'is_active' => true]);

        $this->actingAs($user)
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'Swaash@2026',
                'password' => 'MyNewPass1',
                'password_confirmation' => 'MyNewPass1',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('MyNewPass1', $user->fresh()->password));
    }

    public function test_the_current_password_must_be_correct(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Swaash@2026'), 'is_active' => true]);

        $this->actingAs($user)
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'wrong-password',
                'password' => 'MyNewPass1',
                'password_confirmation' => 'MyNewPass1',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('Swaash@2026', $user->fresh()->password));
    }

    public function test_the_new_password_must_be_confirmed_and_different(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Swaash@2026'), 'is_active' => true]);

        $this->actingAs($user)
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'Swaash@2026',
                'password' => 'Swaash@2026',
                'password_confirmation' => 'Swaash@2026',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }
}
