<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Services\ClientOutstandingService;
use Illuminate\Http\JsonResponse;

/**
 * GET /crm/client-outstanding — what every client owes, how long they have
 * owed it, and what the factory has still to ship them.
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
