<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\MrpNetRequirementsRequest;
use App\Modules\Production\Services\MrpService;
use Illuminate\Http\JsonResponse;

class MrpController extends Controller
{
    public function __construct(private readonly MrpService $mrp) {}

    public function netRequirements(MrpNetRequirementsRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->mrp->netRequirements(
                (int) $request->validated('item_id'),
                (string) $request->validated('quantity'),
            ),
        ]);
    }
}
