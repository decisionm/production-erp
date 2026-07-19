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
 */
class EnsureModulePermission
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = $request->user();

        $required = $request->isMethod('GET') || $request->isMethod('HEAD')
            ? ["{$module}.view", "{$module}.manage"]
            : ["{$module}.manage"];

        abort_unless(
            $user && $user->hasAnyPermission($required),
            403,
            "You don't have permission to access this feature."
        );

        return $next($request);
    }
}
