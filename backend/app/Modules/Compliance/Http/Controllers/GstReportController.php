<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Services\GstReportService;
use App\Modules\Sales\Services\InvoiceService;
use Illuminate\Http\JsonResponse;

class GstReportController extends Controller
{
    public function __construct(private readonly GstReportService $reports) {}

    /**
     * The GSTR-1-shaped return, and what it now stands on.
     *
     * Built from ERP sales invoices, which are retired (DEC-20260903-004):
     * Tally originates the invoice and the e-invoice/IRN (DEC-20260831-012),
     * so nothing new feeds this. `basis` says so — the same words Finance's
     * receivables prints, from the same constant, so the two screens cannot
     * describe the same history differently. Carried beside `data` for the
     * same reason it is there: metadata about the payload, not a figure in
     * it.
     */
    public function gstr1(): JsonResponse
    {
        return response()->json([
            'data' => $this->reports->gstr1(),
            'basis' => InvoiceService::BASIS,
        ]);
    }
}
