<?php

namespace App\Http\Middleware;

use App\Modules\Inventory\Models\MaterialRequest;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AN UNSUBMITTED MATERIAL REQUEST DOES NOT EXIST TO THE STORE.
 *
 * A draft is production's own working paper until the floor presses Submit.
 * `show` and `cancel` are route-model-bound in the group BOTH desks read, so
 * without this a store-only login could read one in full — and CANCEL it,
 * tearing up the floor's paper before it was ever sent (`cancel` is guarded by
 * lifecycle alone, and a draft is not final).
 *
 * MIDDLEWARE, NOT A CONTROLLER CHECK, AND THAT IS THE WHOLE POINT. The first
 * version of this gate sat in the controller — which runs AFTER the
 * FormRequest. So `POST .../cancel {"reason":"x"}` answered 422 (reason too
 * short) for a draft that exists and 404 for one that does not, and a store
 * login could enumerate production's drafts with one ordinary request and no
 * side effects. The gate has to run before anything that can answer
 * differently, and middleware is the only place that is true.
 *
 * It throws ModelNotFoundException rather than abort(404) so the response is
 * BYTE-IDENTICAL to the one route-model binding gives for an id that really is
 * missing. A distinguishable 404 is still an oracle.
 */
class EnsureDraftIsProductionsOwn
{
    /** The one list of who may see production's working papers. */
    public const PRODUCTION_EYES = ['production.view', 'production.manage'];

    public function handle(Request $request, Closure $next): Response
    {
        $materialRequest = $request->route('material_request');

        if ($materialRequest instanceof MaterialRequest
            && $materialRequest->submitted_at === null
            && $request->user()?->hasAnyPermission(self::PRODUCTION_EYES) !== true) {
            throw (new ModelNotFoundException)
                ->setModel(MaterialRequest::class, [$materialRequest->getKey()]);
        }

        return $next($request);
    }
}
