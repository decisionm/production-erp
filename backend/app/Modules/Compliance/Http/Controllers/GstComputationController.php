<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Services\GstComputationService;
use App\Modules\Sales\Models\Invoice;
use Illuminate\Http\JsonResponse;

class GstComputationController extends Controller
{
    public function __construct(private readonly GstComputationService $computation) {}

    public function invoiceBreakdown(Invoice $invoice): JsonResponse
    {
        return response()->json(['data' => $this->computation->invoiceBreakdown($invoice)]);
    }
}
