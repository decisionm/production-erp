<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Services\ClientOutstandingService;
use Illuminate\Http\JsonResponse;

/**
 * GET /finance/client-outstanding — what every client owes, how long they have
 * owed it, and what the factory has still to ship them.
 *
 * GATED BY `module:finance`, not `module:crm`. The rows name a client and the
 * money they owe, which is the same class of data `reports/receivables` next
 * door is gated for; putting it behind the weaker CRM gate would have widened
 * who can read the factory's debtor book (owner decision, 31-Aug-2026).
 *
 * A bare object, not a resource: it describes no single row. The whole
 * position is one read because the page's totals and its ageing columns must
 * agree with its rows — paginating the clients would make the header sum a
 * different set from the table under it.
 */
class ClientOutstandingController extends Controller
{
    public function __construct(private readonly ClientOutstandingService $outstanding) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->outstanding->report()]);
    }
}
