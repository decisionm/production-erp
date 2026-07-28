<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates every traceability route (material lots/bags, day-bin ledger,
 * shift handover) behind config('production.traceability_enabled').
 * 404 — not 403 — by design: with the flag off the feature does not
 * exist, and the API must be indistinguishable from a deployment that
 * never had it (design doc "Rollout" §1: schema lands invisibly).
 */
class EnsureTraceabilityEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('production.traceability_enabled'), 404);

        return $next($request);
    }
}
