<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deactivating a user (UserService::update with is_active=false) should
 * revoke access immediately, not just block their next login — this runs
 * on every authenticated request, separately from EnsureModulePermission's
 * per-feature checks, so an already-open session dies on its next request.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(
            $request->user() && ! $request->user()->is_active,
            403,
            'This account has been deactivated.'
        );

        return $next($request);
    }
}
