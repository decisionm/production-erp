<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Services\AppSettingService;
use App\Modules\Finance\Http\Requests\ImportClientOutstandingRequest;
use App\Modules\Finance\Services\ClientOutstandingService;
use App\Modules\Finance\Services\TallyOutstandingExportParser;
use App\Modules\TallySync\Http\Controllers\TallySettingsController;
use App\Modules\TallySync\Services\TallyReceivableSyncService;
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

    /**
     * POST /finance/client-outstanding/import — fill the page from a Tally
     * export taken by hand.
     *
     * WHY A SECOND DOOR EXISTS. The position normally arrives from the local
     * Tally agent, and that path has two failure points nobody in the office
     * can do anything about: the factory PC must be running, and its Tally
     * must answer on the XML gateway. On 03-Sep-2026 both were down for an
     * afternoon and Accounts could not see what a single client owed, while a
     * perfectly good export of exactly that position sat on a laptop.
     *
     * IT IS THE SAME DESTINATION, THROUGH THE SAME SERVICE. This does not get
     * its own table, its own rules, or its own idea of what an outstanding is.
     * It parses the file into the same rows the agent posts and hands them to
     * TallyReceivableSyncService, so every guard that protects the agent path
     * protects this one: the company scoping, the all-or-nothing replace, and
     * the refusal to wipe a standing position on an export that yielded
     * nothing.
     *
     * ORDERS ARE NEVER TOUCHED. This report is bills only. Passing an empty
     * order list would delete the pending sales orders the agent last
     * delivered, so the service is handed back exactly what it already holds
     * — a bills-only import replaces bills, and says nothing about shipping.
     */
    public function import(
        ImportClientOutstandingRequest $request,
        TallyOutstandingExportParser $parser,
        TallyReceivableSyncService $receivables,
        AppSettingService $settings
    ): JsonResponse {
        $contents = (string) file_get_contents($request->file('file')->getRealPath());

        $bills = $parser->parse($contents);

        $company = $settings->get(TallySettingsController::KEY_COMPANY);

        $summary = $receivables->syncBills($bills, $request->validated()['as_of'], $company);

        return response()->json(['data' => $summary]);
    }
}
