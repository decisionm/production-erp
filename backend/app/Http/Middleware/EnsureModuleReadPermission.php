<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureModulePermission's read half, on every verb. For a route group whose
 * POSTs are READS — Ask ERP: a question is a SELECT, and the only thing the
 * POST writes is the asker's own conversation log — so `{module}.view` is
 * enough for all of it, and `.manage` is accepted as the wider grant it
 * always is. Deliberately a separate alias rather than a flag on the other
 * middleware: a route group that names `module-read:` says on its face that
 * nothing behind it changes factory data.
 */
class EnsureModuleReadPermission
{
    public function handle(Request $request, Closure $next, string ...$modules): Response
    {
        $user = $request->user();

        $required = [];
        foreach ($modules as $module) {
            $required[] = "{$module}.view";
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
