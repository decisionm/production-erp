<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates an entire route group behind a feature module's permissions.
 * Read requests (GET/HEAD) pass with either "{module}.view" or
 * "{module}.manage"; every other verb requires "{module}.manage" — a role
 * granted Manage can read too, without needing both permissions checked
 * explicitly. One `->middleware("module:{$name}")` per module route group
 * covers every route inside it, present and future, rather than requiring
 * each new route to remember which permission it needs.
 *
 * MORE THAN ONE MODULE MAY BE NAMED — `module:production,inventory` — and
 * then holding EITHER module's permission is enough (Phase 7.5, additive:
 * a single name behaves exactly as it always did). This is for the handful
 * of documents that genuinely have two sides: the production material
 * request is raised by the floor and worked by the store, and neither can
 * be asked to hold the other's permission to read the one piece of paper
 * they share. It is deliberately OR, not AND: route-group middleware
 * accumulates, so requiring both is already expressible by nesting groups
 * (and is what the inventory/finance split at routes/api.php does NOT
 * want — see the comment there).
 */
class EnsureModulePermission
{
    public function handle(Request $request, Closure $next, string ...$modules): Response
    {
        $user = $request->user();

        $reading = $request->isMethod('GET') || $request->isMethod('HEAD');

        $required = [];
        foreach ($modules as $module) {
            if ($reading) {
                $required[] = "{$module}.view";
            }
            $required[] = "{$module}.manage";
        }

        abort_unless(
            $user && $user->hasAnyPermission($required),
            403,
            "You don't have permission to access this feature."
        );

        return $next($request);
    }
}
