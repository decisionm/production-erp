<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Services\AccountsReceivableService;
use App\Modules\Finance\Services\FinancialReportService;
use App\Modules\Sales\Services\InvoiceService;
use Illuminate\Http\JsonResponse;

class FinancialReportController extends Controller
{
    public function __construct(
        private readonly FinancialReportService $reports,
        private readonly AccountsReceivableService $receivables,
    ) {}

    public function trialBalance(): JsonResponse
    {
        return response()->json(['data' => $this->reports->trialBalance()]);
    }

    public function profitAndLoss(): JsonResponse
    {
        return response()->json(['data' => $this->reports->profitAndLoss()]);
    }

    public function balanceSheet(): JsonResponse
    {
        return response()->json(['data' => $this->reports->balanceSheet()]);
    }

    /**
     * WHAT THE CUSTOMERS OWE — and what that figure now stands on.
     *
     * The ERP's own sales invoice is retired (DEC-20260903-004): Tally
     * originates the invoice and the ERP imports it (DEC-20260831-012,
     * DEC-20260902-046), so nothing new feeds these rows. The figure is
     * unchanged and still adds up; `basis` says out loud that it is history,
     * because a receivables total nobody can age is worse than one whose
     * source is named. Where it reads from INSTEAD is the Tally invoice
     * import build, not this change.
     *
     * Carried beside `data`, not inside it: `data` is a bare list of rows and
     * a key pushed into it would change that shape.
     */
    public function receivables(): JsonResponse
    {
        return response()->json([
            'data' => $this->receivables->outstanding(),
            'basis' => InvoiceService::BASIS,
        ]);
    }
}
