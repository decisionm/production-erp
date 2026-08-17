<?php

namespace App\Modules\Production\Services;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * WHO MAY HARD-DELETE ONE OF THE FLOOR'S MASTERS — DEC-20260817-002 §3,
 * "Super Admin / Owner level only", as the one callback
 * ConfigurationLifecycle's `$canHardDelete` seam wants.
 *
 * PROVISIONAL, AND DELIBERATELY IN ONE PLACE. The permission behind this
 * tier belongs to the shared permission CATALOG
 * (PermissionService::MODULES) — a permission that is not in the catalog is
 * stripped from every role on the next role save, which is why
 * `machine-master` and `carton-trace` are catalog entries rather than
 * hand-made rows. That catalog entry is owned by the delete-tier
 * workstream, not by Production. Until it lands, this class names the
 * permission the five floor masters check, so the integrator repoints ONE
 * constant instead of five services.
 *
 * FAIL-CLOSED BY CONSTRUCTION, twice over. `hasAnyPermission()` swallows
 * spatie's PermissionDoesNotExist and answers false, so before the
 * catalog entry and its seeder exist NOBODY holds this tier and every hard
 * delete is refused with a 403 — which is the honest reading of "Super
 * Admin only" for a system that cannot yet name a Super Admin. And a null
 * user (an unauthenticated or token-less caller) is false without asking.
 *
 * Archive, activate, create and edit are NOT gated here: they follow the
 * module's ordinary RBAC (`module:production`, and `module:machine-master`
 * for the machine master's writes). Only the irreversible act is reserved.
 */
final class ConfigurationDeleteTier
{
    /**
     * The permission a hard delete of a configuration master requires.
     *
     * PROVISIONAL — repoint to the delete-tier workstream's catalog entry
     * when it lands. Nothing else in this repo should spell this string.
     */
    public const PERMISSION = 'configuration-delete.manage';

    /** @return Closure(?Authenticatable): bool */
    public static function authorisation(): Closure
    {
        return static fn (?Authenticatable $user): bool => $user !== null
            && method_exists($user, 'hasAnyPermission')
            && $user->hasAnyPermission([self::PERMISSION]);
    }
}
