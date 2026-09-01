<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Services\SubstituteMaterialOptionsService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The dropdown behind "this material ran short — I actually used that one".
 *
 * GATED ON THE SAME PERMISSION AS THE ACT. A person who may not record a
 * substitution is not shown the list of what they could substitute: the
 * options and the write are one decision, and splitting them would put a
 * control on the screen that only fails on submit.
 */
class SubstituteMaterialController extends Controller
{
    public function __construct(private readonly SubstituteMaterialOptionsService $options) {}

    public function __invoke(Request $request): JsonResource
    {
        // ->can(), not authorize(): the same call TraceabilityService makes
        // for production.override-fifo, so both scoped permissions are
        // checked one way across the module.
        abort_unless(
            $request->user()?->can('production.substitute-material') ?? false,
            403,
            'Recording a substituted material requires the production.substitute-material permission.',
        );

        return JsonResource::collection(
            $this->options->collect($request->query('search')),
        );
    }
}
