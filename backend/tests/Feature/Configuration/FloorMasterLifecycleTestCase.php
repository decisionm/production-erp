<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\Production\Services\ConfigurationDeleteTier;
use App\Support\Configuration\SchemaCascades;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Shared scaffolding for the five FLOOR masters' lifecycle tests —
 * WorkCenter, Mold, Shift, ScrapReason, DowntimeReason.
 *
 * It holds NO assertions and NO policy: only the three actors the contract
 * distinguishes, so five test files cannot each invent a different idea of
 * who "a Super Admin" is.
 *
 *   manager()   — the module's ordinary configuration user. Creates, edits,
 *                 archives, activates. MUST NOT hard-delete
 *                 (DEC-20260817-002 §3).
 *   owner()     — the same, plus the hard-delete tier.
 *   reader()    — view only.
 *
 * The delete tier's permission name is read from ConfigurationDeleteTier so
 * that when the shared catalog entry lands, these tests follow it instead of
 * pinning a string that has moved.
 */
abstract class FloorMasterLifecycleTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The cascade backstop caches per (connection, parent table) and the
        // suite rebuilds the schema between tests.
        SchemaCascades::flush();
    }

    /** The module permissions this entity's writes sit behind. */
    abstract protected function modulePermissions(): array;

    protected function actorWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user->givePermissionTo($permissions);
        Sanctum::actingAs($user);

        return $user;
    }

    /** Module manage rights, and deliberately NOT the hard-delete tier. */
    protected function manager(): User
    {
        return $this->actorWith($this->modulePermissions());
    }

    /** Module manage rights PLUS the Super Admin / Owner hard-delete tier. */
    protected function owner(): User
    {
        return $this->actorWith([
            ...$this->modulePermissions(),
            ConfigurationDeleteTier::PERMISSION,
        ]);
    }
}
