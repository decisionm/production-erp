<?php

namespace App\Support\Configuration;

use Closure;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * WHO may hard-delete a configuration master — DEC-20260817-002 §3, "Super
 * Admin / Owner level only", expressed once for every module.
 *
 * `ConfigurationLifecycle` ships the SEAM (a `$canHardDelete` callback) and
 * deliberately refuses every hard delete while that seam is null, because
 * Phase 7.6 had no permission to name and would not invent one. This class
 * is the wiring wave's answer, and it is ONE class rather than a closure
 * per service so twelve masters cannot drift into twelve different
 * authorities.
 *
 * ## Why a catalogue entry, and not a hand-created permission
 *
 * `RoleService::validPermissions()` intersects every grant with
 * `PermissionService::allPermissionNames()`, which is derived from
 * `PermissionService::MODULES`. A permission that is not in that catalogue
 * is silently STRIPPED from every role on the next save through the Roles
 * screen — so a hand-created `configuration.delete` row would work until an
 * administrator opened the Roles page, and then stop working for everyone
 * with no error anywhere. `machine-master` and `carton-trace` are the two
 * existing precedents and their comments say exactly this. Hence
 * `configuration-delete` is a catalogue module, and this constant names the
 * half of it that is real.
 *
 * ## Why `.manage` and not `.view`
 *
 * The catalogue mints `.view` and `.manage` for every module; a module uses
 * the half that fits and the other stays vestigial (`carton-trace`'s real
 * grant is its `.view`, its `.manage` is the spare). Hard delete is a WRITE,
 * so this tier is `.manage`.
 *
 * ## Who actually receives it
 *
 * Nobody is added to the seeder for this. `PermissionSeeder` hands the
 * Administrator role EVERY catalogue permission via `syncPermissions`, and
 * the owner logs in as Administrator — so Administrator gains the tier the
 * moment the catalogue entry exists, and no other role does unless a human
 * grants it deliberately on the Roles screen. That is the whole of "Owner
 * level only" in a repo that has no Super Admin role to invent.
 *
 * ## Why this is NOT route middleware
 *
 * The DELETE route stays inside its module's own `module:<key>` group. The
 * tier is enforced at the ONE place the delete is decided — the lifecycle's
 * `$canHardDelete` seam — so it applies identically to any future caller of
 * the service, and so a user who may manage the module still gets Archive
 * while being refused Delete. A second guard in the middleware layer would
 * be a second place to keep in step, and it would 403 the archive and
 * activate routes with it.
 */
class HardDeleteAuthority
{
    /**
     * The permission a hard delete of ANY configuration master requires,
     * on top of that module's own `manage` permission (the route group
     * already enforces the latter).
     */
    public const PERMISSION = 'configuration-delete.manage';

    /**
     * The callback every module's `configurationHardDeleteAuthorisation()`
     * returns. FAIL-CLOSED at both ends: an unauthenticated caller is a
     * `null` user and answers false, and `can()` answers false — rather
     * than throwing `PermissionDoesNotExist` — on a database where the
     * permission row was never seeded (spatie's gate wraps the lookup in
     * `checkPermissionTo`). A missing seed must read as "not permitted",
     * never as a 500.
     *
     * @return Closure(?Authenticatable): bool
     */
    public static function callback(): Closure
    {
        return static fn (?Authenticatable $user): bool => $user instanceof Authorizable
            && $user->can(self::PERMISSION);
    }
}
