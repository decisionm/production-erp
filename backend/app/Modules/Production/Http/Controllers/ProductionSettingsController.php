<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ProductionSettingsController extends Controller
{
    /**
     * Deployment-level production settings the frontend must agree with
     * the backend about — rounding mode and tolerance bands come from
     * config/production.php, never hard-coded client-side.
     */
    public function show(): JsonResponse
    {
        return response()->json([
            'data' => [
                'packing_rounding' => config('production.packing_rounding'),
                'tolerances' => config('production.tolerances'),
            ],
        ]);
    }
}
